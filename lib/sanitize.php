<?php
// Cleans message HTML before it's saved, using HTML Purifier with an allowlist. Whatever the
// editor sends (or anyone posting to the form directly), only these tags survive:
// p, br, h2–h4, strong, em, ul/ol/li, a[href], blockquote, hr, and simple tables, plus
// text-align: center. Everything else, including scripts, event handlers, fonts, colors, and
// sizes, is removed.

declare(strict_types=1);

require_once __DIR__ . '/vendor/htmlpurifier/library/HTMLPurifier.auto.php';

function html_purifier(): HTMLPurifier
{
    static $purifier;
    if ($purifier !== null) {
        return $purifier;
    }
    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null); // nothing written to disk on the server
    $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
    $config->set('HTML.Allowed', implode(',', [
        'p[style]', 'br', 'h2[style]', 'h3[style]', 'h4[style]', 'strong', 'em',
        'ul', 'ol', 'li', 'a[href]', 'blockquote', 'hr',
        'table', 'thead', 'tbody', 'tr', 'th', 'td',
    ]));
    $config->set('CSS.AllowedProperties', ['text-align']);
    $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

    // Word and Blogger use <b>, <i>, and <h1>; keep their meaning instead of dropping them.
    $definition = $config->getHTMLDefinition(true);
    $definition->info_tag_transform['b'] = new HTMLPurifier_TagTransform_Simple('strong');
    $definition->info_tag_transform['i'] = new HTMLPurifier_TagTransform_Simple('em');
    $definition->info_tag_transform['h1'] = new HTMLPurifier_TagTransform_Simple('h2');

    return $purifier = new HTMLPurifier($config);
}

function sanitize_html(string $html): string
{
    // The editor writes every space as &nbsp;, which would stop English lines from wrapping.
    $html = str_replace(['&nbsp;', '&#160;', "\u{00A0}"], ' ', $html);
    $clean = html_purifier()->purify($html);

    // The only style kept is centering; left/right/justify alignment is dropped.
    $clean = preg_replace_callback(
        '/ style="([^"]*)"/',
        fn(array $m): string => preg_match('/^\s*text-align:\s*center;?\s*$/i', $m[1]) ? ' style="text-align:center;"' : '',
        $clean,
    );
    return trim($clean);
}
