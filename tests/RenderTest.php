<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/render.php';

final class RenderTest extends TestCase
{
    private function message(array $fields = []): array
    {
        return $fields + [
            'slug' => 'a-message',
            'title_en' => '',
            'body_en' => '',
            'title_zh' => '',
            'body_zh' => '',
            'published_at' => '2026-10-02T18:00:00Z',
        ];
    }

    public function testEveryInterfaceTextExistsInBothLanguages(): void
    {
        $this->assertSame(array_keys(UI_TEXT['en']), array_keys(UI_TEXT['zh']));
        $this->assertSame(array_keys(ADMIN_TEXT['zh']), array_keys(ADMIN_TEXT['en']));
    }

    public function testAdminTextIsChineseUnlessEnglishWasChosen(): void
    {
        unset($_COOKIE['cahob_admin_lang']);
        $this->assertSame('登入', at('log_in'));
        $_COOKIE['cahob_admin_lang'] = 'fr';
        $this->assertSame('登入', at('log_in'), 'Anything but "en" means Chinese.');
        $_COOKIE['cahob_admin_lang'] = 'en';
        $this->assertSame('Try again in 3 minutes.', substr(at('locked_out', 3), -23));
        unset($_COOKIE['cahob_admin_lang']);
    }

    public function testDatesAreShownInPacificTime(): void
    {
        // 02:00 UTC on Oct 3 is still Oct 2 in San Gabriel.
        $this->assertSame('October 2, 2026', format_date('2026-10-03T02:00:00Z', 'en'));
        $this->assertSame('2026年10月2日', format_date('2026-10-03T02:00:00Z', 'zh'));
        $this->assertSame('2026-10-02', iso_date('2026-10-03T02:00:00Z'));
    }

    public function testPlainTextKeepsParagraphsApartAndDecodesEntities(): void
    {
        $this->assertSame('One Two & three', plain_text("<p>One</p><p>Two &amp; <strong>three</strong></p>"));
        $this->assertSame('First Second Item', plain_text('<h2>First</h2><p>Second<br>Item</p>'));
    }

    public function testPlainTextAddsNoSpacesAroundInlineChineseFormatting(): void
    {
        $this->assertSame('舊的我已經釘了十字架', plain_text('<p>舊的<strong>我</strong>已經<em>釘了</em>十字架</p>'));
    }

    public function testEmptyEditorBodiesCountAsBlank(): void
    {
        foreach (['', '<p><br></p>', '<p>&nbsp;</p>', "<p>\u{3000}</p>"] as $blank) {
            $this->assertTrue(html_is_blank($blank), var_export($blank, true));
        }
        $this->assertFalse(html_is_blank('<p>主</p>'));
    }

    public function testExcerptIsShortenedToAboutTheSameWidthInBothLanguages(): void
    {
        $english = excerpt('<p>' . str_repeat('word ', 200) . '</p>');
        $chinese = excerpt('<p>' . str_repeat('主耶穌', 200) . '</p>');
        $this->assertStringEndsWith('…', $english);
        $this->assertStringEndsWith('…', $chinese);
        $this->assertLessThanOrEqual(240, mb_strwidth($english));
        $this->assertLessThanOrEqual(240, mb_strwidth($chinese));
        $this->assertLessThan(mb_strlen($english), mb_strlen($chinese), 'Chinese characters count double.');
        $this->assertSame('Short body.', excerpt('<p>Short body.</p>'));
    }

    public function testSectionUsesTheRequestedLanguage(): void
    {
        $section = message_section($this->message(['title_zh' => '標題', 'body_zh' => '<p>內容</p>', 'body_en' => '<p>Body</p>']), 'zh');
        $this->assertSame(['lang' => 'zh', 'title' => '標題', 'body' => '<p>內容</p>', 'fallback' => false], $section);
    }

    public function testSectionFallsBackWhenTheLanguageWasNotWritten(): void
    {
        $section = message_section($this->message(['title_en' => 'Only English', 'body_en' => '<p>Body</p>', 'body_zh' => '<p><br></p>']), 'zh');
        $this->assertSame('en', $section['lang']);
        $this->assertSame('Only English', $section['title']);
        $this->assertTrue($section['fallback']);
    }

    public function testBothLanguagesNeedABody(): void
    {
        $this->assertTrue(has_both_languages($this->message(['body_en' => '<p>a</p>', 'body_zh' => '<p>主</p>'])));
        $this->assertFalse(has_both_languages($this->message(['body_en' => '<p>a</p>', 'title_zh' => 'Title only'])));
    }

    public function testUntitledMessagesAreTitledByTheirDate(): void
    {
        $message = $this->message(['body_en' => '<p>Body</p>']);
        $this->assertSame('October 2, 2026', display_title(message_section($message, 'en'), $message, 'en'));
        $this->assertSame('2026年10月2日', display_title(message_section($message, 'zh'), $message, 'zh'));
    }

    public function testUrls(): void
    {
        $this->assertSame('/messages/a%20b%26c', message_url(['slug' => 'a b&c'], 'en'));
        $this->assertSame('/messages-zh/x', message_url(['slug' => 'x'], 'zh'));
        $this->assertSame('/messages/x?version=zh', message_url(['slug' => 'x'], 'en', 'zh'));
        $this->assertSame('/messages-zh/x?version=en', message_url(['slug' => 'x'], 'zh', 'en'));
        $this->assertSame('/messages-zh/x', message_url(['slug' => 'x'], 'zh', 'zh'), 'No version when it matches the page.');
        $this->assertSame('/messages/', list_url('en'));
        $this->assertSame('/messages-zh/?page=3', list_url('zh', 3));
        $this->assertSame('/messages/?category=romans', list_url('en', 1, 'romans'));
        $this->assertSame('/messages-zh/?category=church-history&page=2', list_url('zh', 2, 'church-history'));
        $this->assertSame('/who-we-are-zh.html', static_page('who-we-are', 'zh'));
    }

    public function testOldPhpAddressesPointToTheCleanUrls(): void
    {
        $this->assertSame('/messages/', legacy_url('/messages.php', []));
        $this->assertSame('/messages/?page=2', legacy_url('/messages.php', ['page' => '2']));
        $this->assertSame('/messages-zh/', legacy_url('/messages-zh.php', []));
        $this->assertSame('/messages/2026-10-05', legacy_url('/message.php', ['slug' => '2026-10-05']));
        $this->assertSame('/messages-zh/2026-10-05', legacy_url('/message.php', ['slug' => '2026-10-05', 'lang' => 'zh']));
        $this->assertSame('/messages-zh/', legacy_url('/message.php', ['lang' => 'zh']), 'No slug: the list.');
        $this->assertSame('/messages/', legacy_url('/message.php', ['slug' => ['x']]));
        $this->assertNull(legacy_url('/messages/', []), 'The new addresses are left alone.');
        $this->assertNull(legacy_url('/messages/2026-10-05', ['slug' => '2026-10-05']));
    }

    public function testPageCount(): void
    {
        $this->assertSame(1, page_count(0, 10));
        $this->assertSame(1, page_count(10, 10));
        $this->assertSame(2, page_count(11, 10));
    }
}
