<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Renders the real public pages against a throwaway database. Each test runs in its own PHP
 * process because the pages cache the config and database connection.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cahob-pages-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents(
            $this->dir . '/config.php',
            "<?php return ['db_path' => __DIR__ . '/messages.sqlite', 'admin' => ['username' => 'pastor', 'password_hash' => ''], 'debug' => false];"
        );
        putenv('CAHOB_CONFIG=' . $this->dir . '/config.php');
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        // The page's database connection is still open, which blocks deleting on Windows.
        foreach (glob($this->dir . '/*') as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function add(string $slug, array $fields = []): void
    {
        $row = $fields + [
            'title_en' => '',
            'body_en' => '',
            'title_zh' => '',
            'body_zh' => '',
            'status' => 'published',
            'published_at' => '2026-10-02T18:00:00Z',
        ];
        db()->prepare(
            'INSERT INTO messages (slug, title_en, body_en, title_zh, body_zh, status, published_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$slug, $row['title_en'], $row['body_en'], $row['title_zh'], $row['body_zh'], $row['status'], $row['published_at']]);
    }

    private function render(string $page, array $query = []): string
    {
        $_GET = $query;
        ob_start();
        require __DIR__ . '/../' . $page;
        return ob_get_clean();
    }

    public function testListShowsPublishedMessagesNewestFirstAndHidesDrafts(): void
    {
        $this->add('older', ['title_en' => 'Older message', 'body_en' => '<p>Old</p>', 'published_at' => '2026-07-16T18:00:00Z']);
        $this->add('newer', ['title_en' => 'Newer message', 'body_en' => '<p>New</p>']);
        $this->add('draft', ['title_en' => 'Secret draft', 'body_en' => '<p>Draft</p>', 'status' => 'draft']);

        $html = $this->render('messages.php');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertLessThan(strpos($html, 'Older message'), strpos($html, 'Newer message'));
        $this->assertStringNotContainsString('Secret draft', $html);
        $this->assertStringContainsString('A Voice in the Wilderness', $html);
        $this->assertStringContainsString('href="/messages/" class="active"', $html);
        $this->assertStringContainsString('<a href="/admin/" class="btn btn-ghost" rel="nofollow">Sign in</a>', $html);
        $this->assertLessThan(strpos($html, 'class="message-list"'), strpos($html, '>Sign in</a>'), 'The sign-in button is above the list, under the header image.');
    }

    public function testListPagesTenAtATime(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->add("m$i", ['title_en' => "Message $i", 'body_en' => '<p>Body</p>', 'published_at' => sprintf('2026-09-%02dT18:00:00Z', $i)]);
        }

        $page2 = $this->render('messages.php', ['page' => '2']);

        $this->assertSame(2, substr_count($page2, 'class="message-item'));
        $this->assertStringContainsString('Page 2 of 2', $page2);
        $this->assertStringContainsString('href="/messages/" rel="prev"', $page2);
        $this->assertStringNotContainsString('rel="next"', $page2);
    }

    public function testFirstPageLinksToTheNextOne(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->add("m$i", ['title_en' => "Message $i", 'body_en' => '<p>Body</p>']);
        }
        $page1 = $this->render('messages.php');
        $this->assertSame(10, substr_count($page1, 'class="message-item'));
        $this->assertStringContainsString('href="/messages/?page=2" rel="next"', $page1);
    }

    public function testPagePastTheEndIsNotFound(): void
    {
        $this->add('only', ['title_en' => 'Only', 'body_en' => '<p>Body</p>']);
        $html = $this->render('messages.php', ['page' => '3']);
        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('Message not found', $html);
    }

    public function testEmptyListSaysSo(): void
    {
        $html = $this->render('messages-zh.php');
        $this->assertStringContainsString('目前還沒有信息', $html);
        $this->assertStringContainsString('rel="nofollow">登入</a>', $html, 'The sign-in button is there even with no messages.');
    }

    public function testChineseListUsesChineseTitlesAndFallsBackToEnglish(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>a</p>', 'title_zh' => '雙語信息', 'body_zh' => '<p>主</p>']);
        $this->add('english-only', ['title_en' => 'English only', 'body_en' => '<p>a</p>', 'published_at' => '2026-09-01T18:00:00Z']);

        $html = $this->render('messages-zh.php');

        $this->assertStringContainsString('<html lang="zh-Hant">', $html);
        $this->assertStringContainsString('雙語信息', $html);
        $this->assertStringNotContainsString('Both languages', $html);
        $this->assertStringContainsString('<h2 class="message-title" lang="en">', $html);
        $this->assertStringContainsString('English only', $html);
        $this->assertStringContainsString('href="/messages-zh/both"', $html);
    }

    public function testUntitledMessageIsListedByItsDate(): void
    {
        $this->add('2026-10-02', ['body_en' => '<p><strong>Abiding in the Vine</strong></p><p>Opening words.</p>']);
        $html = $this->render('messages.php');
        $this->assertMatchesRegularExpression('#<a href="/messages/2026-10-02">October 2, 2026</a>#', $html);
        $this->assertStringContainsString('Abiding in the Vine Opening words.', $html);
    }

    public function testMessagePageShowsOneLanguageAndLinksToTheOther(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'title_zh' => '雙語信息', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'both']);

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('<title>Both languages | CAHOB</title>', $html);
        $this->assertStringContainsString('October 2, 2026', $html);
        $this->assertStringContainsString('<p>English body</p>', $html);
        $this->assertStringNotContainsString('中文內容', $html, 'The Chinese text is on its own page.');
        $this->assertStringNotContainsString('雙語信息', $html);
        $this->assertStringContainsString('<a href="/messages-zh/both" class="explore-link" lang="zh-Hant">中文版 <span aria-hidden="true">&rarr;</span></a>', $html);
        $this->assertStringContainsString('href="/messages-zh/both" class="lang-switch"', $html, 'The top bar also switches the page to Chinese.');
    }

    public function testChineseMessagePageShowsOnlyChinese(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'both', 'lang' => 'zh']);

        $this->assertStringContainsString('<p>中文內容</p>', $html);
        $this->assertStringNotContainsString('English body', $html);
        $this->assertStringContainsString('<a href="/messages/both" class="explore-link" lang="en">English version <span aria-hidden="true">&rarr;</span></a>', $html);
        $this->assertStringContainsString('<h1 class="hero-title fade-up">2026年10月2日</h1>', $html, 'No Chinese title, so the date is the heading.');
    }

    public function testMessagePageFallsBackToTheWrittenLanguage(): void
    {
        $this->add('zh-only', ['title_zh' => '讀經隨筆', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'zh-only']);

        $this->assertStringContainsString('This message is available in Chinese only.', $html);
        $this->assertStringContainsString('<article class="content-narrow message-body" lang="zh-Hant">', $html);
        $this->assertStringContainsString('<p>中文內容</p>', $html);
        $this->assertStringNotContainsString('class="explore-link" lang="zh-Hant"', $html, 'No link to a Chinese version that is the same page.');
    }

    public function testDraftMessageIsNotFound(): void
    {
        $this->add('draft', ['title_en' => 'Secret draft', 'body_en' => '<p>Draft</p>', 'status' => 'draft']);
        $html = $this->render('message.php', ['slug' => 'draft']);
        $this->assertSame(404, http_response_code());
        $this->assertStringNotContainsString('Secret draft', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function testDeletedMessageIsGoneFromThePublicPages(): void
    {
        $this->add('kept', ['title_en' => 'Kept message', 'body_en' => '<p>Kept</p>']);
        $this->add('gone', ['title_en' => 'Deleted message', 'body_en' => '<p>Gone</p>']);
        db()->exec("UPDATE messages SET deleted_at = CURRENT_TIMESTAMP WHERE slug = 'gone'");

        $list = $this->render('messages.php');
        $this->assertStringContainsString('Kept message', $list);
        $this->assertStringNotContainsString('Deleted message', $list);

        $html = $this->render('message.php', ['slug' => 'gone']);
        $this->assertSame(404, http_response_code());
        $this->assertStringNotContainsString('Deleted message', $html);
    }

    public function testMissingOrMalformedSlugIsNotFound(): void
    {
        $this->assertStringContainsString('找不到這篇信息', $this->render('message.php', ['lang' => 'zh']));
        $this->assertSame(404, http_response_code());
    }

    public function testArraySlugIsNotFound(): void
    {
        $this->render('message.php', ['slug' => ['x']]);
        $this->assertSame(404, http_response_code());
    }

    public function testEnglishFolderShowsTheList(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'body_zh' => '<p>中文內容</p>']);
        $this->assertStringContainsString('href="/messages/both"', $this->render('messages/index.php'));
    }

    public function testChineseFolderShowsTheList(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'body_zh' => '<p>中文內容</p>']);
        $this->assertStringContainsString('href="/messages-zh/both"', $this->render('messages-zh/index.php'));
    }

    public function testChineseFolderShowsAMessageInChinese(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'title_zh' => '雙語信息', 'body_zh' => '<p>中文內容</p>']);
        $html = $this->render('messages-zh/index.php', ['slug' => 'both']);
        $this->assertStringContainsString('<title>雙語信息｜CAHOB</title>', $html);
        $this->assertStringContainsString('href="/messages/both" class="lang-switch"', $html);
    }

    public function testCleanUrlFolderIgnoresALangParameter(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'title_zh' => '雙語信息', 'body_zh' => '<p>中文內容</p>']);
        $html = $this->render('messages/index.php', ['slug' => 'both', 'lang' => 'zh']);
        $this->assertStringContainsString('<title>Both languages | CAHOB</title>', $html);
    }

    public function testTitlesAreEscaped(): void
    {
        $this->add('xss', ['title_en' => '<script>alert(1)</script>', 'body_en' => '<p>Body</p>']);
        $list = $this->render('messages.php');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $list);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list);
    }
}
