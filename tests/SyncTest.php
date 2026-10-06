<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/sync.php';

final class SyncTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = db_open(':memory:');
        db_migrate($this->pdo);
    }

    /** A post as blogger_posts() returns it. */
    private function post(string $id, string $published, string $html, array $labels = [], ?string $updated = null): array
    {
        return ['id' => "tag:blogger.com,1999:blog-1.post-$id", 'url' => "https://test.blogspot.com/$id.html", 'published' => $published,
            'updated' => $updated ?? $published, 'labels' => $labels, 'html' => $html];
    }

    private function message(int $id): array
    {
        return find_message_including_deleted($this->pdo, $id);
    }

    private function rows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    }

    // ---------- Titles ----------

    public function testTitleIsTheFirstRealLine(): void
    {
        $this->assertSame('Spiritual Principles in the Formation of Faith', suggest_title('<p>Last updated: 10/05/2026</p><p>Spiritual Principles in the Formation of Faith—</p><p>Text</p>'));
        $this->assertSame('兩個「免得」與一個「持守」', suggest_title('<p>讀經隨筆 （最近更新日期：09/29.2026）</p><p>兩個「免得」與一個「持守」</p>', ['讀經隨筆']));
        $this->assertSame('Classical Christian Education', suggest_title('<p><strong>Classical Christian Education.</strong></p>'));
        $this->assertSame('教養孩童', suggest_title('<p>讀經隨筆</p><p>教養孩童</p>', ['讀經隨筆']), 'The series name (its label) is skipped.');
        $this->assertSame('', suggest_title('<p><br></p>'));
        $this->assertLessThanOrEqual(120, mb_strwidth(suggest_title('<p>' . str_repeat('Long words here ', 20) . '</p>')));
    }

    // ---------- New posts ----------

    public function testNewSameDayPostsBecomeOnePublishedMessageWithACategory(): void
    {
        $posts = [
            $this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding in Christ.</p><p>Brothers and sisters, abide in Him.</p>', ['Romans']),
            $this->post('zh1', '2026-10-07T18:05:00Z', '<p>住在基督裡</p><p>弟兄姊妹，要住在祂裡面。</p>', ['羅馬書']),
        ];
        $plan = sync_plan($this->pdo, $posts);
        $this->assertCount(1, $plan['new']);
        $this->assertSame(['Abiding in Christ', '住在基督裡'], [$plan['new'][0]['title_en'], $plan['new'][0]['title_zh']]);

        $this->assertSame(['new' => 1, 'updated' => 0, 'linked' => 0, 'kept' => 0], sync_apply($this->pdo, $plan));
        $message = find_published($this->pdo, 'abiding-in-christ');
        $this->assertNotNull($message, 'Published right away, with a slug from the English title.');
        $this->assertSame('2026-10-07T18:00:00Z', $message['published_at']);
        $this->assertStringContainsString('弟兄姊妹', $message['body_zh']);
        $this->assertSame('romans', find_category($this->pdo, (int) $message['category_id'])['slug']);
        $this->assertSame(2, $this->rows('blogger_posts'));

        $again = sync_plan($this->pdo, $posts);
        $this->assertSame(0, sync_change_count($again), 'Syncing twice changes nothing.');
        $this->assertSame(2, $again['unchanged']);
    }

    public function testUnknownLabelsMakeANewCategory(): void
    {
        $plan = sync_plan($this->pdo, [
            $this->post('en1', '2026-10-07T18:00:00Z', '<p>Hebrews</p><p>Text.</p>', ['Hebrews']),
            $this->post('zh1', '2026-10-07T18:01:00Z', '<p>希伯來書</p><p>內容。</p>', ['希伯來書']),
        ]);
        sync_apply($this->pdo, $plan);
        $category = find_category_by_slug($this->pdo, 'hebrews');
        $this->assertSame(['Hebrews', '希伯來書'], [$category['name_en'], $category['name_zh']]);
    }

    public function testALanguagePostedLaterIsAddedToItsMessage(): void
    {
        sync_apply($this->pdo, sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding in Christ</p><p>Text.</p>')]));
        $id = (int) find_published($this->pdo, 'abiding-in-christ')['id'];

        $plan = sync_plan($this->pdo, [
            $this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding in Christ</p><p>Text.</p>'),
            $this->post('zh1', '2026-10-07T23:00:00Z', '<p>住在基督裡</p><p>內容。</p>'),
        ]);
        $this->assertCount(1, $plan['add']);
        $this->assertSame([], $plan['new']);
        sync_apply($this->pdo, $plan);
        $this->assertSame('住在基督裡', $this->message($id)['title_zh']);
        $this->assertStringContainsString('內容', $this->message($id)['body_zh']);
    }

    // ---------- Edits on Blogger ----------

    public function testABloggerEditReplacesTheTextButKeepsTheWebsitesTitle(): void
    {
        sync_apply($this->pdo, sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>First draft.</p>')]));
        $id = (int) find_published($this->pdo, 'abiding')['id'];
        $this->pdo->exec("UPDATE messages SET title_en = 'Abiding in the Vine' WHERE id = $id");

        $plan = sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>Second draft.</p>', [], '2026-10-08T09:00:00Z')]);
        $this->assertCount(1, $plan['update']);
        sync_apply($this->pdo, $plan);

        $message = $this->message($id);
        $this->assertStringContainsString('Second draft', $message['body_en']);
        $this->assertSame('Abiding in the Vine', $message['title_en']);
        $this->assertSame('abiding', $message['slug']);
        $this->assertCount(2, message_revisions($this->pdo, $id), 'The first draft is still in the history.');
    }

    public function testTextEditedOnTheWebsiteIsNotOverwritten(): void
    {
        sync_apply($this->pdo, sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>First draft.</p>')]));
        $id = (int) find_published($this->pdo, 'abiding')['id'];
        $this->pdo->exec("UPDATE messages SET body_en = '<p>Fixed on the website.</p>' WHERE id = $id");

        $edited = [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>Second draft.</p>', [], '2026-10-08T09:00:00Z')];
        $plan = sync_plan($this->pdo, $edited);
        $this->assertCount(1, $plan['kept']);
        $this->assertSame([], $plan['update']);
        sync_apply($this->pdo, $plan);
        $this->assertSame('<p>Fixed on the website.</p>', $this->message($id)['body_en']);
        $this->assertSame(0, sync_change_count(sync_plan($this->pdo, $edited)), 'Reported once, not on every sync.');

        $later = [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>Third draft.</p>', [], '2026-10-09T09:00:00Z')];
        $this->assertCount(1, sync_plan($this->pdo, $later)['kept'], 'Still protected after another Blogger edit.');
    }

    public function testEditsToADeletedMessageAreOnlyReported(): void
    {
        sync_apply($this->pdo, sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>Text.</p>')]));
        $id = (int) find_published($this->pdo, 'abiding')['id'];
        delete_message($this->pdo, $id);
        $plan = sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>New.</p>', [], '2026-10-08T09:00:00Z')]);
        $this->assertCount(1, $plan['trash']);
        $this->assertSame([], $plan['new'], 'Not imported again either.');
    }

    public function testPostsDeletedOnBloggerAreOnlyReported(): void
    {
        sync_apply($this->pdo, sync_plan($this->pdo, [$this->post('en1', '2026-10-07T18:00:00Z', '<p>Abiding</p><p>Text.</p>')]));
        $plan = sync_plan($this->pdo, []);
        $this->assertCount(1, $plan['removed']);
        sync_apply($this->pdo, $plan);
        $this->assertNotNull(find_published($this->pdo, 'abiding'), 'Still on the website.');
    }

    // ---------- The first sync: messages imported before syncing existed ----------

    /** Messages as the one-time import saved them: dated by the earlier post, untitled. */
    private function imported(array $posts): void
    {
        require_once __DIR__ . '/../lib/tools/import_blogger.php';
        import_messages($this->pdo, messages_to_import(pair_posts($posts)), false);
    }

    public function testImportedMessagesAreLinkedNotDuplicated(): void
    {
        $posts = [
            // Two messages on one day, as on 2026-08-07.
            $this->post('zh2', '2026-08-07T18:09:00Z', '<p>婚姻</p><p>內容一。</p>'),
            $this->post('en2', '2026-08-07T18:09:30Z', '<p>Marriage</p><p>Text one.</p>'),
            $this->post('en3', '2026-08-07T18:21:00Z', '<p>Who are we</p><p>Text two.</p>'),
            $this->post('zh3', '2026-08-07T18:23:00Z', '<p>我們是誰</p><p>內容二。</p>'),
            $this->post('en4', '2026-07-19T23:52:00Z', '<p>English only</p><p>Text.</p>'),
        ];
        $this->imported($posts);

        // A post added later that day must not take another message's place.
        $posts[] = $this->post('zh9', '2026-08-07T22:00:00Z', '<p>另一篇</p><p>新的內容。</p>');
        $plan = sync_plan($this->pdo, array_reverse($posts)); // the feed lists newest first
        $this->assertCount(5, $plan['link']);
        $this->assertCount(1, $plan['new']);
        $this->assertSame([], $plan['update']);
        sync_apply($this->pdo, $plan);

        $byPost = $this->pdo->query('SELECT b.blogger_id, m.body_en, m.body_zh FROM blogger_posts b JOIN messages m ON m.id = b.message_id')->fetchAll();
        foreach ($byPost as $row) {
            $id = substr($row['blogger_id'], -3);
            $expected = ['zh2' => '內容一', 'en2' => 'Text one', 'en3' => 'Text two', 'zh3' => '內容二', 'en4' => 'Text.', 'zh9' => '新的內容'][$id];
            $this->assertStringContainsString($expected, $row['body_en'] . $row['body_zh'], "$id is linked to its own message");
        }
        $this->assertSame(4, $this->rows('messages'), 'The three imported messages plus the one new post.');
    }

    public function testAPostEditedAfterTheImportUpdatesAnUneditedMessage(): void
    {
        $this->imported([$this->post('en1', '2026-09-28T23:44:00Z', '<p>Two Lests</p><p>Old text.</p>')]);
        $id = (int) $this->pdo->query('SELECT id FROM messages')->fetchColumn();
        $plan = sync_plan($this->pdo, [$this->post('en1', '2026-09-28T23:44:00Z', '<p>Two Lests</p><p>New text.</p>', [], '2026-09-29T10:00:00Z')]);
        $this->assertCount(1, $plan['update']);
        sync_apply($this->pdo, $plan);
        $this->assertStringContainsString('New text', $this->message($id)['body_en']);
    }

    public function testAnImportedMessageEditedOnTheWebsiteIsKept(): void
    {
        $this->imported([$this->post('en1', '2026-09-28T23:44:00Z', '<p>Two Lests</p><p>Old text.</p>')]);
        $message = $this->pdo->query('SELECT * FROM messages')->fetch();
        save_message($this->pdo, ['body_en' => '<p>Edited on the website.</p>'] + $message, $message);

        $plan = sync_plan($this->pdo, [$this->post('en1', '2026-09-28T23:44:00Z', '<p>Two Lests</p><p>New text.</p>', [], '2026-09-29T10:00:00Z')]);
        $this->assertCount(1, $plan['kept']);
        sync_apply($this->pdo, $plan);
        $this->assertSame('<p>Edited on the website.</p>', $this->message((int) $message['id'])['body_en']);
        $this->assertSame(1, $this->rows('blogger_posts'), 'Linked, so it is not imported again.');
    }
}
