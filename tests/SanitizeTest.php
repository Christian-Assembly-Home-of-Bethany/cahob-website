<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/sanitize.php';

final class SanitizeTest extends TestCase
{
    public static function cleaned(): array
    {
        return [
            'scripts are removed' => ['<p>Hi<script>alert(1)</script></p>', '<p>Hi</p>'],
            'event handlers are removed' => ['<p onclick="steal()">Hi</p>', '<p>Hi</p>'],
            'javascript links lose their href' => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'normal links are kept, extras dropped' => ['<a href="https://cahob.org/" target="_blank" rel="noopener" onclick="x">x</a>', '<a href="https://cahob.org/">x</a>'],
            'mailto links are kept' => ['<a href="mailto:hi@example.org">mail</a>', '<a href="mailto:hi@example.org">mail</a>'],
            'images and iframes are removed' => ['<p>a<img src="x" onerror="1"><iframe src="//evil"></iframe>b</p>', '<p>ab</p>'],
            'fonts, sizes, and colors are dropped' => ['<p><span style="font-size:20pt;color:red;font-family:Arial">word</span></p>', '<p>word</p>'],
            'only centering survives as a style' => ['<p style="color:red; text-align: center;">c</p>', '<p style="text-align:center;">c</p>'],
            'right alignment is dropped' => ['<p style="text-align:right">r</p>', '<p>r</p>'],
            'centered headings are kept' => ['<h2 style="text-align: center;">H</h2>', '<h2 style="text-align:center;">H</h2>'],
            'Word bold and italics become strong and em' => ['<b>B</b> <i>I</i>', '<strong>B</strong> <em>I</em>'],
            'h1 becomes h2' => ['<h1>Big</h1>', '<h2>Big</h2>'],
            'h5 and h6 are flattened' => ['<h5>Small</h5>', 'Small'],
            'lists, quotes, and dividers are kept' => ['<ol><li>1</li></ol><ul><li>2</li></ul><blockquote><p>q</p></blockquote><hr>', '<ol><li>1</li></ol><ul><li>2</li></ul><blockquote><p>q</p></blockquote><hr>'],
            'tables are kept without attributes' => ['<table style="border:1px solid #000"><tbody><tr><td data-row="1">a</td></tr></tbody></table>', '<table><tbody><tr><td>a</td></tr></tbody></table>'],
            'Word tags disappear' => ['<p class="MsoNormal">Text<o:p></o:p></p>', '<p>Text</p>'],
            'Word tags with text keep the text' => ['<p>A<o:p>B</o:p> <w:Sdt ShowingPlcHdr="t">C</w:Sdt></p>', '<p>AB C</p>'],
            'links that look like namespaces are kept' => ['<p><a href="https://cahob.org/x:y">x</a></p>', '<p><a href="https://cahob.org/x:y">x</a></p>'],
            'completely empty paragraphs are dropped' => ['<p>a</p><p></p><p style="text-align:center"> </p><p>b</p>', '<p>a</p><p>b</p>'],
            'the editor\'s non-breaking spaces become normal spaces' => ['<p>Hello&nbsp;world&nbsp;again</p>', '<p>Hello world again</p>'],
            'empty editor lines are kept' => ['<p>a</p><p><br></p><p>b</p>', '<p>a</p><p><br></p><p>b</p>'],
            'Chinese text is untouched' => ['<p>舊的<strong>我</strong>已經釘了十字架</p>', '<p>舊的<strong>我</strong>已經釘了十字架</p>'],
        ];
    }

    #[DataProvider('cleaned')]
    public function testCleaning(string $input, string $expected): void
    {
        $this->assertSame($expected, sanitize_html($input));
    }

    public function testUnclosedTagsAreRepaired(): void
    {
        $this->assertSame('<p><strong>bold</strong></p>', sanitize_html('<p><strong>bold'));
    }
}
