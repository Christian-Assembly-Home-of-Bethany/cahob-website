<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Renders the real admin pages while signed in, against a throwaway config and database.
 * Saving redirects and exits, so saving itself is tested through apply_editor_action in
 * EditorTest, and end to end against the running server.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminPagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cahob-admin-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/config.php',
            "<?php return ['db_path' => __DIR__ . '/messages.sqlite', 'admin' => ['username' => 'pastor', 'password_hash' => 'x'], 'debug' => false];"
        );
        putenv('CAHOB_CONFIG=' . $this->dir . '/config.php');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        require_once __DIR__ . '/../lib/auth.php';
        require_once __DIR__ . '/../lib/editor.php';
        start_admin_session();
        $_SESSION['admin'] = 'pastor';
        $_SESSION['last_seen'] = time();
        $_SESSION['csrf'] = 'test-token';
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        foreach (array_merge(glob($this->dir . '/sessions/*') ?: [], glob($this->dir . '/logs/*') ?: [], glob($this->dir . '/*')) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    private function add(array $fields): int
    {
        $message = $fields + ['title_en' => '', 'body_en' => '', 'title_zh' => '', 'body_zh' => '', 'status' => 'published', 'published_at' => '2026-10-02T18:00:00Z'];
        return save_message(db(), $message);
    }

    private function render(string $page, array $query = [], array $post = []): string
    {
        $_GET = $query;
        $_POST = $post;
        if ($post) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        }
        ob_start();
        require __DIR__ . '/../admin/' . $page;
        return ob_get_clean();
    }

    public function testDashboardListsDraftsAndPublishedMessages(): void
    {
        $this->add(['title_zh' => '已發佈的信息', 'body_zh' => '<p>內容</p>']);
        $this->add(['title_en' => 'Draft in English', 'body_en' => '<p>x</p>', 'status' => 'draft', 'published_at' => '2026-10-03T18:00:00Z']);
        $this->add(['body_en' => '<p><strong>Abiding in the Vine</strong> opening words</p>', 'published_at' => '2026-09-01T18:00:00Z']);

        $html = $this->render('index.php');

        $this->assertStringContainsString('<html lang="zh-Hant">', $html);
        $this->assertStringContainsString('已發佈的信息', $html);
        $this->assertStringContainsString('Draft in English', $html);
        $this->assertStringContainsString('status-badge--draft">草稿', $html);
        $this->assertStringContainsString('status-badge--published">已發佈', $html);
        $this->assertStringContainsString('<span class="untitled-tag">無標題</span> <span class="untitled-text">Abiding in the Vine opening words</span>', $html);
        $this->assertLessThan(strpos($html, '已發佈的信息'), strpos($html, 'Draft in English'), 'Newest first.');
        $this->assertStringContainsString('href="/admin/edit.php"', $html);
    }

    public function testDashboardShowsTheLatestSecurityEvents(): void
    {
        mkdir($this->dir . '/logs');
        file_put_contents(
            $this->dir . '/logs/security.log',
            security_log_line('LOGIN_FAILED', '198.51.100.7', 'wrong password', time() - 60, 'Bot/1.0')
            . security_log_line('LOCKED_OUT', '198.51.100.7', '5 failed logins in 15 minutes; locked for 15 minutes', time() - 30, 'Bot/1.0')
            . security_log_line('LOGIN_OK', '203.0.113.5', '', time(), 'Firefox')
        );

        $html = $this->render('index.php');

        $this->assertStringContainsString('安全紀錄', $html);
        $this->assertStringContainsString('event-badge--locked_out">已鎖定', $html);
        $this->assertStringContainsString('15 分鐘內登入失敗 5 次，鎖定 15 分鐘', $html);
        $this->assertStringContainsString('密碼錯誤', $html);
        $this->assertLessThan(strpos($html, '已鎖定'), strpos($html, '登入成功'), 'Newest first.');
    }

    public function testDashboardWithNothingYet(): void
    {
        $html = $this->render('index.php');
        $this->assertStringContainsString('還沒有任何信息', $html);
        $this->assertStringContainsString('目前沒有紀錄。', $html);
    }

    public function testNewMessageFormStartsAsADraftWithTodaysDate(): void
    {
        $html = $this->render('edit.php');

        $this->assertStringContainsString('<h1>新增信息</h1>', $html);
        $this->assertStringContainsString('name="action" value="draft"', $html);
        $this->assertStringContainsString('name="action" value="publish"', $html);
        $this->assertStringNotContainsString('value="unpublish"', $html);
        $this->assertStringContainsString('value="' . utc_to_local_input(now_utc()) . '"', $html);
        $this->assertStringContainsString('data-key="new"', $html);
        $this->assertStringNotContainsString('版本記錄', $html, 'A new message has no history yet.');
        $this->assertLessThan(strpos($html, 'id="title_en"'), strpos($html, 'id="title_zh"'), 'Chinese comes first.');
    }

    public function testQuillIsPinnedAndIntegrityChecked(): void
    {
        $html = $this->render('edit.php');
        $this->assertStringContainsString('<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js" integrity="sha384-', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" integrity="sha384-', $html);
        $this->assertStringContainsString('"tb_divider":"分隔線"', $html);
    }

    public function testEditFormShowsTheMessageAndPublishedButtons(): void
    {
        $id = $this->add(['title_zh' => '我們是誰', 'title_en' => 'Who Are We', 'body_en' => '<p>Body <strong>text</strong></p>']);

        $html = $this->render('edit.php', ['id' => (string) $id, 'notice' => 'notice_published']);

        $this->assertStringContainsString('<h1>編輯信息</h1>', $html);
        $this->assertStringContainsString('value="我們是誰"', $html);
        $this->assertStringContainsString('<p>Body <strong>text</strong></p></div>', $html);
        $this->assertStringContainsString('value="&lt;p&gt;Body &lt;strong&gt;text&lt;/strong&gt;&lt;/p&gt;"', $html);
        $this->assertStringContainsString('已發佈！', $html);
        $this->assertStringContainsString('data-saved="1"', $html);
        $this->assertStringContainsString('value="update"', $html);
        $this->assertStringContainsString('value="unpublish"', $html);
        $this->assertStringContainsString('href="/messages-zh/who-are-we"', $html);
    }

    public function testUnknownNoticeIsIgnored(): void
    {
        $id = $this->add(['body_en' => '<p>x</p>']);
        $html = $this->render('edit.php', ['id' => (string) $id, 'notice' => '<script>x</script>']);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('data-saved="0"', $html);
    }

    public function testMissingMessageIsNotFound(): void
    {
        $html = $this->render('edit.php', ['id' => '999']);
        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('找不到這篇信息', $html);
    }

    public function testFailedPublishShowsTheErrorAndKeepsWhatWasTyped(): void
    {
        $html = $this->render('edit.php', [], ['csrf' => 'test-token', 'action' => 'publish', 'published_at' => '2026-10-04T18:00', 'title_zh' => '只有標題']);
        $this->assertStringContainsString('發佈前，至少要在一種語言寫下內容。', $html);
        $this->assertStringContainsString('value="只有標題"', $html);
        $this->assertSame(0, count(all_messages(db())));
    }

    public function testStaleTokenSavesNothing(): void
    {
        $html = $this->render('edit.php', [], ['csrf' => 'old', 'action' => 'draft', 'published_at' => '2026-10-04T18:00', 'body_en' => '<p>Keep me</p>']);
        $this->assertStringContainsString('頁面已過期', $html);
        $this->assertStringContainsString('<p>Keep me</p></div>', $html);
        $this->assertSame(0, count(all_messages(db())));
    }

    public function testDeletePageAsksFirst(): void
    {
        $id = $this->add(['title_zh' => '要刪除的信息', 'body_zh' => '<p>x</p>']);
        $html = $this->render('delete.php', ['id' => (string) $id]);
        $this->assertStringContainsString('確定要刪除這篇信息嗎？', $html);
        $this->assertStringContainsString('要刪除的信息', $html);
        $this->assertStringContainsString('<form method="post" action="/admin/delete.php?id=' . $id . '"', $html);
        $this->assertNotNull(find_message(db(), $id), 'Opening the page deletes nothing.');
    }

    public function testDeletedMessagesAreListedSeparatelyWithRestore(): void
    {
        $this->add(['title_zh' => '還在的信息', 'body_zh' => '<p>x</p>']);
        $gone = $this->add(['title_zh' => '刪掉的信息', 'body_zh' => '<p>x</p>']);
        delete_message(db(), $gone);

        $html = $this->render('index.php', ['deleted' => '1']);

        $this->assertStringContainsString('已刪除信息。', $html);
        $this->assertStringContainsString('<details class="deleted-panel" open>', $html, 'Opened right after deleting, so it is clear where it went.');
        $this->assertStringContainsString('已刪除的信息（1）', $html);
        $this->assertStringContainsString('<form method="post" action="/admin/restore.php?id=' . $gone . '">', $html);
        $this->assertStringNotContainsString('href="/admin/edit.php?id=' . $gone . '"', $html, 'A deleted message is restored before it can be edited.');
        $this->assertLessThan(strpos($html, 'deleted-panel'), strpos($html, '還在的信息'));
        $this->assertGreaterThan(strpos($html, 'deleted-panel'), strpos($html, '刪掉的信息'));
    }

    public function testNoDeletedListWhenNothingIsDeleted(): void
    {
        $this->add(['title_zh' => '信息', 'body_zh' => '<p>x</p>']);
        $this->assertStringNotContainsString('deleted-panel', $this->render('index.php'));
    }

    public function testDeletedMessageCannotBeEdited(): void
    {
        $id = $this->add(['body_en' => '<p>x</p>']);
        delete_message(db(), $id);
        $html = $this->render('edit.php', ['id' => (string) $id]);
        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('找不到這篇信息', $html);
    }

    public function testEditorListsEarlierVersions(): void
    {
        $id = $this->add(['title_zh' => '第一版', 'body_zh' => '<p>一</p>']);
        save_message(db(), ['title_zh' => '第二版', 'body_zh' => '<p>二</p>', 'title_en' => '', 'body_en' => '', 'status' => 'published', 'published_at' => '2026-10-02T18:00:00Z'], find_message(db(), $id));
        [$current, $first] = message_revisions(db(), $id);

        $html = $this->render('edit.php', ['id' => (string) $id]);

        $this->assertStringContainsString('版本記錄', $html);
        $this->assertStringContainsString('再按「更新」就能還原', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $current['id'] . '" disabled>[^<]+（目前版本）</option>#u', $html);
        $this->assertMatchesRegularExpression('#<option value="' . $first['id'] . '">\d{4}年\d+月\d+日 [上下]午\d+:\d\d · 已發佈</option>#u', $html);
        $this->assertStringContainsString('value="第二版"', $html, 'The form shows the current version.');
    }

    public function testEditorWithOneVersionSaysSo(): void
    {
        $id = $this->add(['body_en' => '<p>x</p>']);
        $html = $this->render('edit.php', ['id' => (string) $id]);
        $this->assertStringContainsString('目前只有這一個版本', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function testLoadingAnEarlierVersionFillsTheFormWithoutSaving(): void
    {
        $id = $this->add(['title_zh' => '第一版', 'body_zh' => '<p>一</p>', 'published_at' => '2026-09-01T18:00:00Z']);
        save_message(db(), ['title_zh' => '第二版', 'body_zh' => '<p>二</p>', 'title_en' => '', 'body_en' => '', 'status' => 'published', 'published_at' => '2026-10-02T18:00:00Z'], find_message(db(), $id));
        $first = message_revisions(db(), $id)[1];

        $html = $this->render('edit.php', ['id' => (string) $id, 'revision' => (string) $first['id']]);

        $this->assertStringContainsString('value="第一版"', $html);
        $this->assertStringContainsString('<p>一</p></div>', $html);
        $this->assertStringContainsString('value="2026-09-01T11:00"', $html);
        $this->assertStringContainsString('的版本，還沒有儲存。按「更新」就會還原成這個版本。', $html);
        $this->assertStringContainsString('<a href="/admin/edit.php?id=' . $id . '">不要還原，回到目前版本</a>', $html);
        $this->assertStringContainsString('<option value="' . $first['id'] . '" selected>', $html);
        $this->assertStringContainsString('<form method="post" action="/admin/edit.php?id=' . $id . '"', $html, 'Saving goes to the message, not the version.');
        $this->assertSame('第二版', find_message(db(), $id)['title_zh'], 'Loading a version changes nothing.');
    }

    public function testAnotherMessagesVersionIsIgnored(): void
    {
        $mine = $this->add(['title_zh' => '我的信息', 'body_zh' => '<p>x</p>']);
        $other = $this->add(['title_zh' => '別的信息', 'body_zh' => '<p>y</p>']);
        $otherVersion = message_revisions(db(), $other)[0]['id'];

        $html = $this->render('edit.php', ['id' => (string) $mine, 'revision' => (string) $otherVersion]);

        $this->assertStringContainsString('value="我的信息"', $html);
        $this->assertStringNotContainsString('別的信息', $html);
        $this->assertStringNotContainsString('revision-banner', $html);
    }

    public function testPreviewShowsUnsavedTextCleanedAndSavesNothing(): void
    {
        $html = $this->render('preview.php', [], [
            'csrf' => 'test-token', 'action' => 'preview', 'published_at' => '2026-10-04T18:00',
            'title_zh' => '預覽標題', 'body_zh' => '<p>預覽內容</p><script>alert(1)</script>',
            'title_en' => '', 'body_en' => '<p>English text</p>',
        ]);
        $this->assertStringContainsString('預覽：這是目前編輯中的內容', $html);
        $this->assertStringContainsString('預覽標題', $html);
        $this->assertStringContainsString('<p>預覽內容</p>', $html);
        $this->assertStringContainsString('<p>English text</p>', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringContainsString('noindex', $html);
        $this->assertLessThan(strpos($html, 'English text'), strpos($html, '預覽內容'), 'The admin language comes first.');
        $this->assertSame(0, count(all_messages(db())));
    }

    public function testEnglishAdminShowsEnglishEverywhere(): void
    {
        $_COOKIE['cahob_admin_lang'] = 'en';
        $this->add(['title_en' => 'Live', 'body_en' => '<p>x</p>']);
        $html = $this->render('index.php');
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('New message', $html);
        $this->assertStringContainsString('status-badge--published">Published', $html);
        $this->assertStringNotContainsString('新增信息', $html);
    }
}
