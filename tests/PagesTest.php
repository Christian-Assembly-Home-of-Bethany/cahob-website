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
        $this->assertStringContainsString('href="/messages.php" class="active"', $html);
    }

    public function testListPagesTenAtATime(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->add("m$i", ['title_en' => "Message $i", 'body_en' => '<p>Body</p>', 'published_at' => sprintf('2026-09-%02dT18:00:00Z', $i)]);
        }

        $page2 = $this->render('messages.php', ['page' => '2']);

        $this->assertSame(2, substr_count($page2, 'class="message-item'));
        $this->assertStringContainsString('Page 2 of 2', $page2);
        $this->assertStringContainsString('href="/messages.php" rel="prev"', $page2);
        $this->assertStringNotContainsString('rel="next"', $page2);
    }

    public function testFirstPageLinksToTheNextOne(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->add("m$i", ['title_en' => "Message $i", 'body_en' => '<p>Body</p>']);
        }
        $page1 = $this->render('messages.php');
        $this->assertSame(10, substr_count($page1, 'class="message-item'));
        $this->assertStringContainsString('href="/messages.php?page=2" rel="next"', $page1);
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
        $this->assertStringContainsString('href="/message.php?slug=both&amp;lang=zh"', $html);
    }

    public function testUntitledMessageIsListedByItsDate(): void
    {
        $this->add('2026-10-02', ['body_en' => '<p><strong>Abiding in the Vine</strong></p><p>Opening words.</p>']);
        $html = $this->render('messages.php');
        $this->assertMatchesRegularExpression('#<a href="/message.php\?slug=2026-10-02">October 2, 2026</a>#', $html);
        $this->assertStringContainsString('Abiding in the Vine Opening words.', $html);
    }

    public function testMessagePageShowsTheChosenLanguageThenTheOther(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'title_zh' => '雙語信息', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'both']);

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('<title>Both languages | CAHOB</title>', $html);
        $this->assertStringContainsString('October 2, 2026', $html);
        $this->assertLessThan(strpos($html, '<p>中文內容</p>'), strpos($html, '<p>English body</p>'), 'English first on the English page.');
        $this->assertStringContainsString('<div class="content-narrow message-second" id="zh" lang="zh-Hant">', $html);
        $this->assertStringContainsString('<h2>雙語信息</h2>', $html);
        $this->assertStringContainsString('<a href="#zh" lang="zh-Hant">中文版 <span aria-hidden="true">&darr;</span></a>', $html);
        $this->assertStringContainsString('href="/message.php?slug=both&amp;lang=zh" class="lang-switch"', $html, 'The top bar still switches the page to Chinese.');
    }

    public function testChineseMessagePageShowsChineseFirst(): void
    {
        $this->add('both', ['title_en' => 'Both languages', 'body_en' => '<p>English body</p>', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'both', 'lang' => 'zh']);

        $this->assertLessThan(strpos($html, '<p>English body</p>'), strpos($html, '<p>中文內容</p>'));
        $this->assertStringContainsString('id="en" lang="en"', $html);
        $this->assertStringContainsString('<h2>Both languages</h2>', $html);
        $this->assertStringContainsString('<h1 class="hero-title fade-up">2026年10月2日</h1>', $html, 'No Chinese title, so the date is the heading.');
    }

    public function testMessagePageFallsBackToTheWrittenLanguage(): void
    {
        $this->add('zh-only', ['title_zh' => '讀經隨筆', 'body_zh' => '<p>中文內容</p>']);

        $html = $this->render('message.php', ['slug' => 'zh-only']);

        $this->assertStringContainsString('This message is available in Chinese only.', $html);
        $this->assertStringContainsString('<article class="content-narrow message-body" lang="zh-Hant">', $html);
        $this->assertStringContainsString('<p>中文內容</p>', $html);
        $this->assertSame(1, substr_count($html, '<p>中文內容</p>'), 'Shown once, not again as a second language.');
        $this->assertStringNotContainsString('message-second', $html);
        $this->assertStringNotContainsString('message-jump', $html);
    }

    public function testDraftMessageIsNotFound(): void
    {
        $this->add('draft', ['title_en' => 'Secret draft', 'body_en' => '<p>Draft</p>', 'status' => 'draft']);
        $html = $this->render('message.php', ['slug' => 'draft']);
        $this->assertSame(404, http_response_code());
        $this->assertStringNotContainsString('Secret draft', $html);
        $this->assertStringContainsString('noindex', $html);
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

    public function testTitlesAreEscaped(): void
    {
        $this->add('xss', ['title_en' => '<script>alert(1)</script>', 'body_en' => '<p>Body</p>']);
        $list = $this->render('messages.php');
        $this->assertStringNotContainsString('<script>alert(1)</script>', $list);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $list);
    }
}
