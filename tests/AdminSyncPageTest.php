<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/** The Sync from Blogger page, reading a feed file instead of the real blog. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminSyncPageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cahob-sync-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->writeFeed([
            ['en1', '2026-10-07T11:00:00-07:00', '<p>Abiding in Christ</p><p>Text.</p>', 'Romans'],
            ['zh1', '2026-10-07T11:05:00-07:00', '<p>住在基督裡</p><p>內容。</p>', '羅馬書'],
        ]);
        file_put_contents(
            $this->dir . '/config.php',
            "<?php return ['db_path' => __DIR__ . '/messages.sqlite', 'blogger_feed' => __DIR__ . '/feed.xml', 'admin' => ['username' => 'pastor', 'password_hash' => 'x'], 'debug' => false];"
        );
        putenv('CAHOB_CONFIG=' . $this->dir . '/config.php');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        require_once __DIR__ . '/../lib/auth.php';
        require_once __DIR__ . '/../lib/sync.php';
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
        foreach (array_merge(glob($this->dir . '/sessions/*') ?: [], glob($this->dir . '/*')) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->dir);
    }

    /** A Blogger Atom feed with these posts: [id, published, html, label]. */
    private function writeFeed(array $posts): void
    {
        $entries = '';
        foreach ($posts as [$id, $published, $html, $label]) {
            $entries .= '<entry><id>tag:blogger.com,1999:blog-1.post-' . $id . '</id><published>' . $published . '</published><updated>' . $published . '</updated>'
                . '<category scheme="http://www.blogger.com/atom/ns#" term="' . htmlspecialchars($label) . '"/>'
                . '<link rel="alternate" href="https://test.blogspot.com/' . $id . '.html"/>'
                . '<content type="html">' . htmlspecialchars($html) . '</content></entry>';
        }
        file_put_contents($this->dir . '/feed.xml', '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">' . $entries . '</feed>');
    }

    private function render(array $query = [], array $post = []): string
    {
        $_GET = $query;
        $_POST = $post;
        if ($post) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        }
        ob_start();
        require __DIR__ . '/../admin/sync.php';
        return ob_get_clean();
    }

    public function testPreviewShowsNewMessagesAndTheApplyButton(): void
    {
        $html = $this->render();
        $this->assertStringContainsString('<h1>從 Blogger 同步</h1>', $html);
        $this->assertStringContainsString('新信息，將直接發佈（1）', $html);
        $this->assertStringContainsString('住在基督裡', $html);
        $this->assertStringContainsString('中文 + 英文', $html);
        $this->assertStringContainsString('<form method="post" action="/admin/sync"', $html);
        $this->assertSame(0, count_published(db()), 'Looking at the preview saves nothing.');
    }

    public function testUpToDateAfterSyncing(): void
    {
        sync_apply(db(), sync_plan(db(), blogger_posts(blogger_feed_source())));
        $html = $this->render();
        $this->assertStringContainsString('網站已經和 Blogger 一致', $html);
        $this->assertStringNotContainsString('<form method="post" action="/admin/sync"', $html);
        $this->assertStringContainsString('2 篇 Blogger 文章沒有變動。', $html);
    }

    public function testStaleTokenAppliesNothing(): void
    {
        $html = $this->render([], ['csrf' => 'old']);
        $this->assertStringContainsString('頁面已過期', $html);
        $this->assertSame(0, count_published(db()));
    }

    public function testUnreadableFeedIsReported(): void
    {
        unlink($this->dir . '/feed.xml');
        $html = $this->render();
        $this->assertStringContainsString('無法讀取 Blogger', $html);
    }

    public function testUpdatesShowWhatChanged(): void
    {
        sync_apply(db(), sync_plan(db(), blogger_posts(blogger_feed_source())));
        $this->writeFeed([
            ['en1', '2026-10-07T11:00:00-07:00', '<p>Abiding in Christ</p><p>Revised text.</p>', 'Romans'],
            ['zh1', '2026-10-07T11:05:00-07:00', '<p>住在基督裡</p><p>內容。</p>', '羅馬書'],
        ]);
        // Blogger marks the edit with a newer "updated" time.
        file_put_contents($this->dir . '/feed.xml', preg_replace('#(post-en1</id><published>[^<]+</published><updated>)[^<]+#', '${1}2026-10-08T09:00:00-07:00', file_get_contents($this->dir . '/feed.xml')));

        $html = $this->render();
        $this->assertStringContainsString('在 Blogger 上修改過，將更新內容（1）', $html);
        $this->assertStringContainsString('<dt>網站</dt><dd>Abiding in Christ [Text].</dd>', $html);
        $this->assertStringContainsString('<dt>Blogger</dt><dd>Abiding in Christ [Revised text].</dd>', $html);
    }

    public function testDoneNoticeAfterApplying(): void
    {
        $html = $this->render(['new' => '1', 'updated' => '2']);
        $this->assertStringContainsString('同步完成：新增 1 篇信息，更新 2 篇。', $html);
    }

    public function testDashboardLinksToSync(): void
    {
        $_GET = [];
        ob_start();
        require __DIR__ . '/../admin/index.php';
        $this->assertStringContainsString('href="/admin/sync"', ob_get_clean());
    }
}
