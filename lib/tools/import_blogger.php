<?php
// One-time import of the pastor's Blogger posts (johannavoice.blogspot.com) into the Messages
// database. Run locally; never on the live server.
//
//   make import-preview     show what would be imported, without writing anything
//   make import             import into the local database (replacing what's there)
//
// Directly:
//   php lib/tools/import_blogger.php [--dry-run] [--replace] [--db=path/to/messages.sqlite] [feed URL or file]
//
// What it does:
// - Reads the blog's public Atom feed (every published post, full text). Drafts, comments,
//   and anything that isn't a post are skipped.
// - Decides each post's language from its text (Blogger titles are all empty).
// - Pairs each day's English and Chinese posts that were published closest together into
//   one message with both languages. A post without a partner becomes a one-language message.
// - Cleans up Word's formatting (divs, align="center", bold/italic spans, <o:p>, empty spacing
//   paragraphs), then applies the same HTML Purifier rules as the editor.
// - Saves each message as published, with its original date and no title.
//
// It refuses to write into a database that already has messages, unless --replace is given
// (which deletes them first), so running it twice can't create duplicates.

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../editor.php';

const BLOGGER_FEED = 'https://johannavoice.blogspot.com/feeds/posts/default?max-results=500';
const ATOM = 'http://www.w3.org/2005/Atom';

// ---------- Reading the feed ----------

/**
 * The published posts in one page of a Blogger Atom feed, plus the next page's URL if any.
 * Each post: ['id', 'url', 'published' (UTC ISO 8601), 'html'].
 */
function blogger_feed_page(string $xml): array
{
    $doc = new DOMDocument();
    // An error page from Blogger can still be valid XML, so check it really is an Atom feed.
    if (!@$doc->loadXML($xml, LIBXML_NONET)
        || $doc->documentElement?->namespaceURI !== ATOM || $doc->documentElement->localName !== 'feed') {
        throw new RuntimeException('That is not a Blogger Atom feed.');
    }
    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('a', ATOM);
    $xpath->registerNamespace('app', 'http://purl.org/atom/app#');
    $xpath->registerNamespace('blogger', 'http://schemas.google.com/blogger/2008');

    $posts = [];
    foreach ($xpath->query('/a:feed/a:entry') as $entry) {
        $kind = $xpath->evaluate('string(a:category[@scheme="http://schemas.google.com/g/2005#kind"]/@term)', $entry);
        $isDraft = $xpath->evaluate('string(app:control/app:draft)', $entry) === 'yes';
        $type = $xpath->evaluate('string(blogger:type)', $entry);
        $status = $xpath->evaluate('string(blogger:status)', $entry);
        if (($kind !== '' && !str_ends_with($kind, '#post')) || $isDraft
            || ($type !== '' && $type !== 'POST') || ($status !== '' && $status !== 'LIVE')) {
            continue; // comments, pages, settings, drafts, deleted posts
        }
        $published = new DateTimeImmutable($xpath->evaluate('string(a:published)', $entry));
        $posts[] = [
            'id' => $xpath->evaluate('string(a:id)', $entry),
            'url' => $xpath->evaluate('string(a:link[@rel="alternate"]/@href)', $entry),
            'published' => $published->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'html' => $xpath->evaluate('string(a:content)', $entry),
        ];
    }
    $next = $xpath->evaluate('string(/a:feed/a:link[@rel="next"]/@href)');
    return ['posts' => $posts, 'next' => $next !== '' ? $next : null];
}

/** Every published post from a feed URL (following its pages) or a saved feed file. */
function blogger_posts(string $source): array
{
    $posts = [];
    $seen = [];
    while ($source !== null && !isset($seen[$source])) {
        $seen[$source] = true;
        $xml = @file_get_contents($source, false, stream_context_create(['http' => ['timeout' => 60]]));
        if ($xml === false) {
            throw new RuntimeException("Could not read $source");
        }
        $page = blogger_feed_page($xml);
        array_push($posts, ...$page['posts']);
        $source = $page['next'];
    }
    return $posts;
}

// ---------- Deciding language and pairing ----------

/** 'zh' if the text is mostly Chinese characters, otherwise 'en'. */
function post_language(string $html): string
{
    $text = plain_text($html);
    $han = preg_match_all('/\p{Han}/u', $text);
    $latin = preg_match_all('/[A-Za-z]/', $text);
    return $han > $latin / 4 ? 'zh' : 'en';
}

/**
 * Groups posts into messages. On each Pacific-time day, English and Chinese posts are paired
 * by closest publish time. Returns messages oldest first: ['published_at', 'en' => post|null, 'zh' => post|null].
 */
function pair_posts(array $posts): array
{
    $days = [];
    foreach ($posts as $post) {
        $post['lang'] = post_language($post['html']);
        $day = (new DateTimeImmutable($post['published']))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        $days[$day][$post['lang']][] = $post;
    }

    $messages = [];
    foreach ($days as $byLang) {
        $en = $byLang['en'] ?? [];
        $zh = $byLang['zh'] ?? [];
        // Every English/Chinese combination, closest in time first.
        $pairs = [];
        foreach ($en as $i => $e) {
            foreach ($zh as $j => $z) {
                $pairs[] = [abs(strtotime($e['published']) - strtotime($z['published'])), $i, $j];
            }
        }
        sort($pairs);
        $usedEn = $usedZh = [];
        foreach ($pairs as [, $i, $j]) {
            if (!isset($usedEn[$i]) && !isset($usedZh[$j])) {
                $usedEn[$i] = $usedZh[$j] = true;
                $messages[] = ['en' => $en[$i], 'zh' => $zh[$j]];
            }
        }
        foreach ($en as $i => $e) {
            if (!isset($usedEn[$i])) {
                $messages[] = ['en' => $e, 'zh' => null];
            }
        }
        foreach ($zh as $j => $z) {
            if (!isset($usedZh[$j])) {
                $messages[] = ['en' => null, 'zh' => $z];
            }
        }
    }

    foreach ($messages as &$message) {
        // A pair is dated by whichever of its two posts went up first.
        $message['published_at'] = min(array_column(array_filter([$message['en'], $message['zh']]), 'published'));
    }
    unset($message);
    usort($messages, fn(array $a, array $b): int => strcmp($a['published_at'], $b['published_at']));
    return $messages;
}

// ---------- Cleaning up Word's formatting ----------

const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'td', 'th', 'hr'];

/** Blogger/Word HTML turned into the editor's kind of HTML, then sanitized like any saved post. */
function clean_blogger_html(string $html): string
{
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8"><div id="cahob-root">' . $html . '</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
    $root = $doc->getElementById('cahob-root');
    if ($root === null) {
        return '';
    }
    $xpath = new DOMXPath($doc);

    // Word-only tags (<o:p> and friends) and anything that isn't content.
    foreach (iterator_to_array($xpath->query('.//*[contains(name(), ":")] | .//style | .//script | .//comment()', $root)) as $node) {
        $node->parentNode?->removeChild($node);
    }

    // Spans: bold and italic styles become <strong>/<em>; the span itself goes away.
    foreach (array_reverse(iterator_to_array($xpath->query('.//span | .//font', $root))) as $span) {
        $style = strtolower((string) $span->getAttribute('style'));
        $replacement = $doc->createDocumentFragment();
        $inner = $replacement;
        if (preg_match('/font-weight\s*:\s*(bold|[6-9]00)/', $style)) {
            $inner = $inner->appendChild($doc->createElement('strong'));
        }
        if (preg_match('/font-style\s*:\s*italic/', $style)) {
            $inner = $inner->appendChild($doc->createElement('em'));
        }
        while ($span->firstChild) {
            $inner->appendChild($span->firstChild);
        }
        $span->parentNode->replaceChild($replacement, $span);
    }

    // Centering: align="center" or text-align: center, written the way the editor writes it.
    foreach (iterator_to_array($xpath->query('.//*[@align or @style]', $root)) as $element) {
        $centered = strtolower($element->getAttribute('align')) === 'center'
            || preg_match('/text-align\s*:\s*center/i', $element->getAttribute('style'));
        $element->removeAttribute('align');
        $element->removeAttribute('style');
        if ($centered) {
            $element->setAttribute('style', 'text-align: center;');
        }
    }

    // Divs become paragraphs, or just disappear if they hold other blocks (keeping their centering).
    foreach (array_reverse(iterator_to_array($xpath->query('.//div', $root))) as $div) {
        $hasBlocks = false;
        foreach ($div->childNodes as $child) {
            $hasBlocks = $hasBlocks || ($child instanceof DOMElement && in_array(strtolower($child->nodeName), BLOCK_TAGS, true));
        }
        if ($hasBlocks) {
            $centered = $div->getAttribute('style') !== '';
            while ($div->firstChild) {
                $child = $div->firstChild;
                if ($centered && $child instanceof DOMElement && in_array(strtolower($child->nodeName), ['p', 'h1', 'h2', 'h3', 'h4'], true) && !$child->hasAttribute('style')) {
                    $child->setAttribute('style', 'text-align: center;');
                }
                $div->parentNode->insertBefore($child, $div);
            }
            $div->parentNode->removeChild($div);
        } else {
            $p = $doc->createElement('p');
            if ($div->hasAttribute('style')) {
                $p->setAttribute('style', $div->getAttribute('style'));
            }
            while ($div->firstChild) {
                $p->appendChild($div->firstChild);
            }
            $div->parentNode->replaceChild($p, $div);
        }
    }

    // Empty paragraphs that Word used for spacing; the site's paragraphs already have space.
    foreach (iterator_to_array($xpath->query('.//p | .//h1 | .//h2 | .//h3 | .//h4', $root)) as $block) {
        if (trim(str_replace(["\u{00A0}", "\u{3000}"], ' ', $block->textContent)) === '' && $xpath->query('.//hr | .//img | .//table', $block)->length === 0) {
            $block->parentNode->removeChild($block);
        }
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    $clean = sanitize_html($out);
    // Repairing Word's unclosed paragraphs can leave empty ones behind, and Word wraps runs of
    // spaces in bold; drop both.
    $clean = preg_replace('#<p(?: style="[^"]*")?>\s*(?:<br>\s*)*</p>\s*#', '', $clean);
    $clean = preg_replace('#<(strong|em)>(\s*)</\1>#', '$2', $clean);
    return trim($clean);
}

// ---------- Saving ----------

/** The messages as they'll be saved: cleaned bodies, no titles, published on their original dates. */
function messages_to_import(array $paired): array
{
    return array_map(fn(array $m): array => [
        'title_en' => '',
        'title_zh' => '',
        'body_en' => $m['en'] !== null ? clean_blogger_html($m['en']['html']) : '',
        'body_zh' => $m['zh'] !== null ? clean_blogger_html($m['zh']['html']) : '',
        'status' => 'published',
        'published_at' => $m['published_at'],
    ], $paired);
}

/** Saves the messages, oldest first. Refuses a database that already has messages unless $replace. */
function import_messages(PDO $pdo, array $messages, bool $replace): int
{
    $existing = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
    if ($existing > 0 && !$replace) {
        throw new RuntimeException("The database already has $existing messages. Use --replace to delete them and import, or --db= for a new file.");
    }
    $pdo->beginTransaction();
    if ($replace) {
        $pdo->exec('DELETE FROM messages');
    }
    foreach ($messages as $message) {
        save_message($pdo, $message);
    }
    $pdo->commit();
    return count($messages);
}

// ---------- Command line ----------

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return; // included by a test: only define the functions
}

$options = ['dry-run' => false, 'replace' => false, 'db' => null];
$source = BLOGGER_FEED;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run' || $arg === '--replace') {
        $options[substr($arg, 2)] = true;
    } elseif (str_starts_with($arg, '--db=')) {
        $options['db'] = substr($arg, 5);
    } elseif (!str_starts_with($arg, '--')) {
        $source = $arg;
    } else {
        fwrite(STDERR, "Unknown option $arg\n");
        exit(1);
    }
}

try {
    $posts = blogger_posts($source);
    $paired = pair_posts($posts);
    echo 'Read ', count($posts), " published posts from $source\n";
    echo count($paired), " messages to import:\n\n";
    foreach ($paired as $m) {
        $langs = implode(' + ', array_keys(array_filter(['zh' => $m['zh'], 'en' => $m['en']])));
        echo '  ', str_replace('T', ' ', utc_to_local_input($m['published_at'])), "  $langs\n";
        foreach (['zh', 'en'] as $lang) {
            if ($m[$lang] !== null) {
                printf("      %s %s  %s\n          %s\n", $lang, substr(utc_to_local_input($m[$lang]['published']), 11), mb_strimwidth(plain_text($m[$lang]['html']), 0, 64, '…', 'UTF-8'), $m[$lang]['url']);
            }
        }
    }
    echo "\n";

    if ($options['dry-run']) {
        echo "Dry run: nothing was written. Run without --dry-run to import.\n";
        exit(0);
    }

    if ($options['db'] !== null) {
        $pdo = db_open($options['db']);
        db_migrate($pdo);
        $target = $options['db'];
    } else {
        $pdo = db();
        $target = config()['db_path'];
    }
    $count = import_messages($pdo, messages_to_import($paired), $options['replace']);
    echo "Imported $count messages into $target\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Import stopped: ' . $e->getMessage() . "\n");
    exit(1);
}
