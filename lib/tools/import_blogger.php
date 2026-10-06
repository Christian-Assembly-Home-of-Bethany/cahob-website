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
require_once __DIR__ . '/../blogger.php';

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
