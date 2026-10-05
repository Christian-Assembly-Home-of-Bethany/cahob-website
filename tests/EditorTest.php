<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/editor.php';

final class EditorTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = db_open(':memory:');
        db_migrate($this->pdo);
        unset($_COOKIE['cahob_admin_lang']);
    }

    private function form(array $fields = []): array
    {
        return $fields + [
            'published_at' => '2026-10-04T18:00',
            'title_en' => '',
            'title_zh' => '',
            'body_en' => '',
            'body_zh' => '',
        ];
    }

    private function row(int $id): array
    {
        return find_message($this->pdo, $id);
    }

    // ---------- Slugs ----------

    public function testSlugsAreLowercaseWordsJoinedByHyphens(): void
    {
        $this->assertSame('who-are-we-part-two', slugify('Who Are We? Part Two'));
        $this->assertSame('gods-way-not-mans', slugify("God’s Way, Not Man's"));
        $this->assertSame('romans-1-1-17', slugify('Romans 1:1–17'));
    }

    public function testLongSlugsAreCutAtAWordAbout60Characters(): void
    {
        $slug = slugify('Spiritual Principles in the Formation of Faith Across Generations and Beyond');
        $this->assertSame('spiritual-principles-in-the-formation-of-faith-across', $slug);
        $this->assertLessThanOrEqual(60, strlen($slug));
    }

    public function testChineseOnlyTitlesHaveNoSlugWords(): void
    {
        $this->assertSame('', slugify('我們是誰（一）'));
    }

    public function testTakenSlugsGetANumber(): void
    {
        $this->pdo->exec("INSERT INTO messages (id, slug) VALUES (1, 'grace'), (2, 'grace-2')");
        $this->assertSame('grace-3', unique_slug($this->pdo, 'grace'));
        $this->assertSame('grace', unique_slug($this->pdo, 'grace', 1), "A message keeps its own slug.");
        $this->assertSame('mercy', unique_slug($this->pdo, 'mercy'));
    }

    // ---------- Dates ----------

    public function testPacificTimeIsStoredAsUtc(): void
    {
        $this->assertSame('2026-10-05T01:00:00Z', local_to_utc('2026-10-04T18:00'), 'Daylight time is UTC-7.');
        $this->assertSame('2026-12-25T18:30:00Z', local_to_utc('2026-12-25T10:30'), 'Standard time is UTC-8.');
        $this->assertSame('2026-10-04T18:00', utc_to_local_input('2026-10-05T01:00:00Z'));
    }

    public function testInvalidDatesAreRejected(): void
    {
        foreach (['', 'yesterday', '2026-13-01T10:00', '2026-02-30T10:00', '2026-10-04 18:00', '2026-10-04T25:00'] as $bad) {
            $this->assertNull(local_to_utc($bad), $bad);
        }
    }

    // ---------- Reading the form ----------

    public function testFormIsCleanedUp(): void
    {
        $message = message_from_form($this->form([
            'title_en' => "  Grace\n upon   grace  ",
            'title_zh' => str_repeat('恩', 250),
            'body_en' => '<p onclick="x">Hello&nbsp;there</p><script>bad()</script>',
            'body_zh' => ['not', 'text'],
        ]));
        $this->assertSame('Grace upon grace', $message['title_en']);
        $this->assertSame(200, mb_strlen($message['title_zh']));
        $this->assertSame('<p>Hello there</p>', $message['body_en']);
        $this->assertSame('', $message['body_zh']);
        $this->assertSame('2026-10-05T01:00:00Z', $message['published_at']);
    }

    public function testBrokenUtf8DoesNotCrash(): void
    {
        $message = message_from_form($this->form(['title_en' => "God\x92s Word"]));
        $this->assertSame('God?s Word', $message['title_en']);
    }

    // ---------- What each button needs ----------

    public function testPublishingNeedsTextInOneLanguage(): void
    {
        $titleOnly = message_from_form($this->form(['title_zh' => '只有標題']));
        $this->assertSame(['error_needs_body'], message_errors($titleOnly, 'publish'));
        $this->assertSame([], message_errors($titleOnly, 'draft'), 'A draft can be just a title.');

        $chineseOnly = message_from_form($this->form(['body_zh' => '<p>內容</p>']));
        $this->assertSame([], message_errors($chineseOnly, 'publish'), 'Titles are optional and one language is enough.');
    }

    public function testEmptyEditorDoesNotCountAsText(): void
    {
        $message = message_from_form($this->form(['body_en' => '<p><br></p>', 'body_zh' => '<p>&nbsp;</p>']));
        $this->assertSame(['error_needs_body'], message_errors($message, 'publish'));
        $this->assertSame(['error_empty'], message_errors($message, 'draft'));
    }

    public function testBadDateIsReported(): void
    {
        $message = message_from_form($this->form(['published_at' => 'soon', 'body_en' => '<p>x</p>']));
        $this->assertSame(['error_date'], message_errors($message, 'draft'));
    }

    // ---------- Saving ----------

    public function testNewDraftGetsASlugFromItsEnglishTitle(): void
    {
        $result = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Abiding in the Vine', 'body_en' => '<p>x</p>']), 'draft');
        $this->assertSame('notice_saved', $result['notice']);
        $row = $this->row($result['id']);
        $this->assertSame('abiding-in-the-vine', $row['slug']);
        $this->assertSame('draft', $row['status']);
        $this->assertSame('2026-10-05T01:00:00Z', $row['published_at']);
    }

    public function testUntitledMessageUsesItsPacificDateAsSlug(): void
    {
        $result = apply_editor_action($this->pdo, null, $this->form(['body_zh' => '<p>內容</p>']), 'publish');
        $this->assertSame('2026-10-04', $this->row($result['id'])['slug'], '18:00 Pacific on Oct 4 is Oct 5 in UTC, but the slug uses the local date.');
    }

    public function testDraftSlugFollowsTheTitleUntilPublished(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'First Title', 'body_en' => '<p>x</p>']), 'draft')['id'];
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Second Title', 'body_en' => '<p>x</p>']), 'draft');
        $this->assertSame('second-title', $this->row($id)['slug']);

        $result = apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Final Title', 'body_en' => '<p>x</p>']), 'publish');
        $this->assertSame('notice_published', $result['notice']);
        $this->assertSame('final-title', $this->row($id)['slug']);
        $this->assertSame('published', $this->row($id)['status']);

        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Renamed Later', 'body_en' => '<p>y</p>']), 'update');
        $this->assertSame('final-title', $this->row($id)['slug'], 'Published links never change.');
        $this->assertSame('Renamed Later', $this->row($id)['title_en']);
    }

    public function testRepublishingKeepsTheFirstPublishedSlug(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Shared Link', 'body_en' => '<p>x</p>']), 'publish')['id'];
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Shared Link', 'body_en' => '<p>x</p>']), 'unpublish');
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'A New Name', 'body_en' => '<p>x</p>']), 'draft');
        $this->assertSame('shared-link', $this->row($id)['slug'], 'Still a draft, but it was published once.');

        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'A New Name', 'body_en' => '<p>x</p>']), 'publish');
        $this->assertSame('shared-link', $this->row($id)['slug'], 'Links shared before it was unpublished still work.');
        $this->assertNotNull(find_published($this->pdo, 'shared-link'));
    }

    public function testUnpublishMakesItADraftAgain(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['body_en' => '<p>x</p>']), 'publish')['id'];
        $result = apply_editor_action($this->pdo, $this->row($id), $this->form(['body_en' => '<p>x</p>']), 'unpublish');
        $this->assertSame('notice_unpublished', $result['notice']);
        $this->assertSame('draft', $this->row($id)['status']);
        $this->assertNull(find_published($this->pdo, $this->row($id)['slug']));
    }

    public function testSameTitleTwiceGetsDifferentSlugs(): void
    {
        $a = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Grace', 'body_en' => '<p>x</p>']), 'publish')['id'];
        $b = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Grace', 'body_en' => '<p>y</p>']), 'publish')['id'];
        $this->assertSame('grace', $this->row($a)['slug']);
        $this->assertSame('grace-2', $this->row($b)['slug']);
    }

    public function testFailedSaveReturnsTheFormAndChangesNothing(): void
    {
        $result = apply_editor_action($this->pdo, null, $this->form(['title_zh' => '只有標題']), 'publish');
        $this->assertSame(['error_needs_body'], $result['errors']);
        $this->assertSame('只有標題', $result['message']['title_zh']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn());
    }

    public function testSavedBodiesAreSanitized(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['body_en' => '<p>ok</p><script>alert(1)</script>']), 'draft')['id'];
        $this->assertSame('<p>ok</p>', $this->row($id)['body_en']);
    }

    // ---------- Version history ----------

    public function testEverySaveKeepsAVersion(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'First', 'body_en' => '<p>one</p>']), 'draft')['id'];
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Second', 'body_en' => '<p>two</p>']), 'publish');
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Third', 'body_en' => '<p>three</p>']), 'update');

        $history = message_revisions($this->pdo, $id);
        $this->assertSame(['Third', 'Second', 'First'], array_column($history, 'title_en'), 'Newest first.');
        $this->assertSame(['published', 'published', 'draft'], array_column($history, 'status'));
        $this->assertSame('<p>one</p>', $history[2]['body_en']);
        $this->assertSame('2026-10-05T01:00:00Z', $history[2]['published_at']);
        $this->assertNotEmpty($history[2]['saved_at']);
    }

    public function testSavingWithoutChangesAddsNoVersion(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['body_en' => '<p>x</p>']), 'publish')['id'];
        apply_editor_action($this->pdo, $this->row($id), $this->form(['body_en' => '<p>x</p>']), 'update');
        $this->assertCount(1, message_revisions($this->pdo, $id));

        apply_editor_action($this->pdo, $this->row($id), $this->form(['body_en' => '<p>x</p>']), 'unpublish');
        $this->assertCount(2, message_revisions($this->pdo, $id), 'Unpublishing is a change.');
    }

    public function testRestoringAVersionIsSavingItAgain(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Original', 'body_en' => '<p>original</p>']), 'publish')['id'];
        apply_editor_action($this->pdo, $this->row($id), $this->form(['title_en' => 'Mistake', 'body_en' => '<p>oops</p>']), 'update');
        $original = message_revisions($this->pdo, $id)[1];

        apply_editor_action($this->pdo, $this->row($id), $this->form([
            'title_en' => $original['title_en'], 'body_en' => $original['body_en'], 'published_at' => utc_to_local_input($original['published_at']),
        ]), 'update');

        $this->assertSame('<p>original</p>', $this->row($id)['body_en']);
        $this->assertSame(['Original', 'Mistake', 'Original'], array_column(message_revisions($this->pdo, $id), 'title_en'), 'The mistake stays in the history too.');
    }

    public function testAVersionIsOnlyFoundThroughItsOwnMessage(): void
    {
        $a = apply_editor_action($this->pdo, null, $this->form(['body_en' => '<p>a</p>']), 'draft')['id'];
        $b = apply_editor_action($this->pdo, null, $this->form(['body_en' => '<p>b</p>']), 'draft')['id'];
        $versionOfA = message_revisions($this->pdo, $a)[0]['id'];
        $this->assertSame('<p>a</p>', find_revision($this->pdo, $a, $versionOfA)['body_en']);
        $this->assertNull(find_revision($this->pdo, $b, $versionOfA));
    }

    // ---------- Deleting ----------

    public function testDeleteHidesTheMessageWithoutErasingIt(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Gone', 'body_en' => '<p>x</p>']), 'publish')['id'];
        delete_message($this->pdo, $id);

        $this->assertNull(find_message($this->pdo, $id));
        $this->assertSame([], all_messages($this->pdo));
        $this->assertNull(find_published($this->pdo, 'gone'));
        $this->assertSame(0, count_published($this->pdo));
        $this->assertSame([], published_messages($this->pdo, 10, 0));

        $deleted = deleted_messages($this->pdo);
        $this->assertSame(['Gone'], array_column($deleted, 'title_en'));
        $this->assertNotNull($deleted[0]['deleted_at']);
        $this->assertCount(1, message_revisions($this->pdo, $id), 'Its history is kept.');
    }

    public function testRestoreBringsItBackAsItWas(): void
    {
        $id = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Back Again', 'body_en' => '<p>x</p>']), 'publish')['id'];
        delete_message($this->pdo, $id);
        restore_message($this->pdo, $id);

        $this->assertSame([], deleted_messages($this->pdo));
        $this->assertSame('published', $this->row($id)['status']);
        $this->assertNotNull(find_published($this->pdo, 'back-again'), 'The same link works again.');
    }

    public function testADeletedMessageKeepsItsSlug(): void
    {
        $old = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Grace', 'body_en' => '<p>x</p>']), 'publish')['id'];
        delete_message($this->pdo, $old);
        $new = apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Grace', 'body_en' => '<p>y</p>']), 'publish')['id'];
        $this->assertSame('grace-2', $this->row($new)['slug'], 'So restoring the old one never clashes.');
    }

    // ---------- Dashboard ----------

    public function testDashboardShowsDraftsToo(): void
    {
        apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Draft', 'body_en' => '<p>x</p>']), 'draft');
        apply_editor_action($this->pdo, null, $this->form(['title_en' => 'Live', 'body_en' => '<p>x</p>', 'published_at' => '2026-10-05T09:00']), 'publish');
        $this->assertSame(['Live', 'Draft'], array_column(all_messages($this->pdo), 'title_en'));
    }

    public function testDashboardLabelPrefersTheAdminLanguage(): void
    {
        $both = ['title_en' => 'English', 'title_zh' => '中文', 'body_en' => '', 'body_zh' => ''];
        $this->assertSame('中文', dashboard_label($both)['text']);
        $_COOKIE['cahob_admin_lang'] = 'en';
        $this->assertSame('English', dashboard_label($both)['text']);
        unset($_COOKIE['cahob_admin_lang']);
    }

    public function testUntitledMessagesAreLabeledByTheirOpeningWords(): void
    {
        $label = dashboard_label(['title_en' => '', 'title_zh' => '', 'body_en' => '<p>Abiding in the Vine</p>', 'body_zh' => '']);
        $this->assertSame(['text' => 'Abiding in the Vine', 'lang' => 'en', 'untitled' => true], $label);
    }
}
