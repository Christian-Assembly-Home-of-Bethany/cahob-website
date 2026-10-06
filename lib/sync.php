<?php
// Sync from Blogger: brings posts the pastor adds or edits on Blogger over to the website.
// sync_plan() works out what would change (the admin page shows it as a preview) and
// sync_apply() does it. Rules:
// - A new post becomes a published message, titled from its first line; same-day English and
//   Chinese posts become one message, and its Blogger label picks the category.
// - An edited post replaces that language's text, keeping the website's title and category,
//   unless the text was also edited on the website since the last sync (then it's left alone).
// - A post deleted on Blogger is only reported; deleting stays a decision made on the website.
// - The first sync links the messages that were imported before syncing existed.

declare(strict_types=1);

require_once __DIR__ . '/blogger.php';

/** The feed to sync from: config.php's 'blogger_feed' (tests and local copies), or the blog itself. */
function blogger_feed_source(): string
{
    return (string) (config()['blogger_feed'] ?? BLOGGER_FEED);
}

function text_hash(string $html): string
{
    return sha1($html);
}

/**
 * A title from a post's first real line. Skips "Last updated" notes, the series name (the
 * post's own label), and links; trims ending punctuation; keeps it to about 100 characters.
 */
function suggest_title(string $cleanHtml, array $labels = []): string
{
    $blocks = preg_split('#</(?:p|h[1-6]|li|blockquote|td|th)>|<br\s*/?>#i', $cleanHtml) ?: [];
    foreach ($blocks as $block) {
        $line = trim(preg_replace('/[\s\x{3000}]+/u', ' ', html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $line = trim($line, " \u{00A0}");
        if ($line === ''
            || preg_match('/last\s*updated|最近更新|最新更新|更新日期/iu', $line)
            || preg_match('#^https?://#', $line)
            || in_array($line, $labels, true)) {
            continue;
        }
        $line = preg_replace('/[\s.。:：,，;；\-–—]+$/u', '', $line);
        return mb_strimwidth($line, 0, 120, '…', 'UTF-8');
    }
    return '';
}

/** Every link between a Blogger post and a message, by Blogger id. */
function blogger_links(PDO $pdo): array
{
    $links = [];
    foreach ($pdo->query('SELECT * FROM blogger_posts') as $row) {
        $links[$row['blogger_id']] = $row;
    }
    return $links;
}

/** All messages by id, deleted ones included (so a post in the Trash isn't imported again). */
function messages_by_id(PDO $pdo): array
{
    $messages = [];
    foreach ($pdo->query('SELECT * FROM messages ORDER BY id') as $row) {
        $messages[(int) $row['id']] = $row;
    }
    return $messages;
}

/** Whether a message's text in one language is still what it was when first saved (never edited on the website). */
function body_never_edited(PDO $pdo, array $message, string $lang): bool
{
    $first = $pdo->prepare('SELECT body_' . ($lang === 'zh' ? 'zh' : 'en') . ' FROM message_revisions WHERE message_id = ? ORDER BY id LIMIT 1');
    $first->execute([$message['id']]);
    $original = $first->fetchColumn();
    return $original === false || $original === $message['body_' . $lang];
}

function pacific_day(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
}

/**
 * What a sync would do with these posts. Returns lists of:
 * 'new' (messages to create), 'update' (a language's text to replace), 'add' (a language
 * added to a message that only had the other one), 'link' (existing messages to connect),
 * 'kept' (edited on the website, so not overwritten), 'trash' (edited on Blogger but the
 * message is deleted), 'removed' (gone from Blogger), plus 'touch' (nothing visible changes,
 * only the stored "updated" time) and the count of 'unchanged' posts.
 */
function sync_plan(PDO $pdo, array $posts): array
{
    $plan = ['new' => [], 'update' => [], 'add' => [], 'link' => [], 'kept' => [], 'trash' => [], 'removed' => [], 'touch' => [], 'unchanged' => 0];
    $links = blogger_links($pdo);
    $messages = messages_by_id($pdo);
    $taken = []; // "messageId:lang" slots that have a Blogger post
    foreach ($links as $link) {
        $taken[$link['message_id'] . ':' . $link['lang']] = true;
    }

    $unlinked = [];
    foreach ($posts as $post) {
        $post['lang'] = post_language($post['html']);
        $post['clean'] = clean_blogger_html($post['html']);
        $link = $links[$post['id']] ?? null;
        if ($link === null) {
            $unlinked[] = $post;
            continue;
        }
        $message = $messages[(int) $link['message_id']];
        $lang = $link['lang'];
        if ($post['updated'] <= $link['blogger_updated']) {
            $plan['unchanged']++;
        } elseif ($message['deleted_at'] !== null) {
            $plan['trash'][] = ['post' => $post, 'message' => $message, 'lang' => $lang];
        } elseif (text_hash($message['body_' . $lang]) !== $link['synced_hash']) {
            $plan['kept'][] = ['post' => $post, 'message' => $message, 'lang' => $lang];
        } elseif ($post['clean'] === $message['body_' . $lang]) {
            $plan['touch'][] = ['post' => $post, 'message' => $message, 'lang' => $lang];
        } else {
            $plan['update'][] = ['post' => $post, 'message' => $message, 'lang' => $lang];
        }
    }

    // Posts without a link: first the messages imported before syncing existed. The import
    // dated each message by its earlier post, so that post matches the date exactly...
    $claim = function (array $post, callable $fits) use (&$messages, &$taken): ?array {
        $best = null;
        foreach ($messages as $message) {
            $slot = $message['id'] . ':' . $post['lang'];
            if (!isset($taken[$slot]) && $message['published_at'] !== null && $fits($message)
                && ($best === null || abs(strtotime($message['published_at']) - strtotime($post['published'])) < abs(strtotime($best['published_at']) - strtotime($post['published'])))) {
                $best = $message;
            }
        }
        if ($best !== null) {
            $taken[$best['id'] . ':' . $post['lang']] = true;
        }
        return $best;
    };
    $linkOrUpdate = function (array $post, array $message) use (&$plan, $pdo): void {
        $current = $message['body_' . $post['lang']];
        $item = ['post' => $post, 'message' => $message, 'lang' => $post['lang']];
        if ($post['clean'] === $current) {
            $plan['link'][] = $item;
        } elseif ($message['deleted_at'] === null && body_never_edited($pdo, $message, $post['lang'])) {
            $plan['update'][] = $item + ['link' => true]; // edited on Blogger after the import
        } else {
            $plan['kept'][] = $item + ['link' => true];
        }
    };
    $remaining = [];
    foreach ($unlinked as $post) {
        $match = $claim($post, fn(array $m): bool => $m['published_at'] === $post['published'] && !html_is_blank($m['body_' . $post['lang']]));
        if ($match !== null) {
            $linkOrUpdate($post, $match);
        } else {
            $remaining[] = $post;
        }
    }
    // ...and its other-language post is the same day's message with text in that language. Then
    // a language posted after the other was already synced is added to that message. Both go by
    // closest publish time over all candidates, so a later post that day can't take the slot.
    $closest = function (array $posts, callable $fits) use (&$messages, &$taken): array {
        $pairs = [];
        foreach ($posts as $i => $post) {
            foreach ($messages as $message) {
                if (!isset($taken[$message['id'] . ':' . $post['lang']]) && $message['published_at'] !== null
                    && pacific_day($message['published_at']) === pacific_day($post['published']) && $fits($message, $post)) {
                    $pairs[] = [abs(strtotime($message['published_at']) - strtotime($post['published'])), $i, (int) $message['id']];
                }
            }
        }
        sort($pairs);
        $matched = [];
        foreach ($pairs as [, $i, $id]) {
            $slot = $id . ':' . $posts[$i]['lang'];
            if (!isset($matched[$i]) && !isset($taken[$slot])) {
                $taken[$slot] = true;
                $matched[$i] = $messages[$id];
            }
        }
        return $matched;
    };
    $matched = $closest($remaining, fn(array $m, array $p): bool => !html_is_blank($m['body_' . $p['lang']]));
    $rest = [];
    foreach ($remaining as $i => $post) {
        if (isset($matched[$i])) {
            $linkOrUpdate($post, $matched[$i]);
        } else {
            $rest[] = $post;
        }
    }
    $added = $closest($rest, fn(array $m, array $p): bool => html_is_blank($m['body_' . $p['lang']])
        && $m['deleted_at'] === null && isset($taken[$m['id'] . ':' . other_lang($p['lang'])]));
    $new = [];
    foreach ($rest as $i => $post) {
        if (isset($added[$i])) {
            $plan['add'][] = ['post' => $post, 'message' => $added[$i], 'lang' => $post['lang'], 'title' => suggest_title($post['clean'], $post['labels'])];
        } else {
            $new[] = $post;
        }
    }

    foreach (pair_posts($new) as $pair) {
        $item = ['published_at' => $pair['published_at'], 'labels' => []];
        foreach (['en', 'zh'] as $lang) {
            $post = $pair[$lang];
            $item[$lang] = $post;
            $item['title_' . $lang] = $post !== null ? suggest_title($post['clean'], $post['labels']) : '';
            if ($post !== null) {
                $item['labels'][$lang] = $post['labels'];
            }
        }
        $plan['new'][] = $item;
    }

    $inFeed = array_flip(array_column($posts, 'id'));
    foreach ($links as $id => $link) {
        if (!isset($inFeed[$id])) {
            $plan['removed'][] = ['message' => $messages[(int) $link['message_id']], 'lang' => $link['lang'], 'url' => $link['url']];
        }
    }
    return $plan;
}

/** How many visible changes a plan has (what the Apply button would do). */
function sync_change_count(array $plan): int
{
    return count($plan['new']) + count($plan['update']) + count($plan['add']) + count($plan['link']) + count($plan['kept']) + count($plan['touch']);
}

/** The category for a new message's labels: an existing one by either name, or a new one. */
function category_for_labels(PDO $pdo, array $labelsByLang): ?int
{
    $en = $labelsByLang['en'][0] ?? null;
    $zh = $labelsByLang['zh'][0] ?? null;
    foreach (array_filter([$en, $zh]) as $label) {
        $query = $pdo->prepare('SELECT id FROM categories WHERE name_en = :label OR name_zh = :label LIMIT 1');
        $query->execute(['label' => $label]);
        $id = $query->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
    }
    if ($en === null && $zh === null) {
        return null;
    }
    return add_category($pdo, $en ?? $zh, $zh ?? $en);
}

function save_link(PDO $pdo, array $post, int $messageId, string $lang, string $syncedText): void
{
    $pdo->prepare(
        'INSERT INTO blogger_posts (blogger_id, message_id, lang, url, blogger_updated, synced_hash) VALUES (?, ?, ?, ?, ?, ?)
         ON CONFLICT (blogger_id) DO UPDATE SET url = excluded.url, blogger_updated = excluded.blogger_updated, synced_hash = excluded.synced_hash'
    )->execute([$post['id'], $messageId, $lang, $post['url'], $post['updated'], text_hash($syncedText)]);
}

/** Saves one language's text (and its title, if the message has none) on an existing message. */
function save_language(PDO $pdo, array $message, string $lang, string $body, string $title = ''): void
{
    $fresh = find_message_including_deleted($pdo, (int) $message['id']);
    $changed = $fresh;
    $changed['body_' . $lang] = $body;
    if ($title !== '' && $fresh['title_' . $lang] === '') {
        $changed['title_' . $lang] = $title;
    }
    save_message($pdo, $changed, $fresh);
}

function find_message_including_deleted(PDO $pdo, int $id): array
{
    $query = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
    $query->execute([$id]);
    return $query->fetch();
}

/** Carries out a plan in one transaction. Returns how many messages were created, updated, and so on. */
function sync_apply(PDO $pdo, array $plan): array
{
    $pdo->beginTransaction();
    try {
        foreach ($plan['link'] as $item) {
            save_link($pdo, $item['post'], (int) $item['message']['id'], $item['lang'], $item['post']['clean']);
        }
        foreach ($plan['kept'] as $item) {
            if (!empty($item['link'])) {
                // Remember Blogger's text, so the website's edits keep counting as edits next time.
                save_link($pdo, $item['post'], (int) $item['message']['id'], $item['lang'], $item['post']['clean']);
            } else {
                // Only note that this Blogger edit was seen, so the notice isn't repeated every sync.
                $pdo->prepare('UPDATE blogger_posts SET blogger_updated = ? WHERE blogger_id = ?')->execute([$item['post']['updated'], $item['post']['id']]);
            }
        }
        foreach ($plan['touch'] as $item) {
            save_link($pdo, $item['post'], (int) $item['message']['id'], $item['lang'], $item['post']['clean']);
        }
        foreach ($plan['update'] as $item) {
            save_language($pdo, $item['message'], $item['lang'], $item['post']['clean']);
            save_link($pdo, $item['post'], (int) $item['message']['id'], $item['lang'], $item['post']['clean']);
        }
        foreach ($plan['add'] as $item) {
            save_language($pdo, $item['message'], $item['lang'], $item['post']['clean'], $item['title']);
            save_link($pdo, $item['post'], (int) $item['message']['id'], $item['lang'], $item['post']['clean']);
        }
        foreach ($plan['new'] as $item) {
            $id = save_message($pdo, [
                'title_en' => $item['title_en'],
                'title_zh' => $item['title_zh'],
                'body_en' => $item['en']['clean'] ?? '',
                'body_zh' => $item['zh']['clean'] ?? '',
                'status' => 'published',
                'published_at' => $item['published_at'],
                'category_id' => category_for_labels($pdo, $item['labels']),
            ]);
            foreach (['en', 'zh'] as $lang) {
                if ($item[$lang] !== null) {
                    save_link($pdo, $item[$lang], $id, $lang, $item[$lang]['clean']);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [
        'new' => count($plan['new']),
        'updated' => count($plan['update']) + count($plan['add']),
        'linked' => count($plan['link']) + count(array_filter($plan['kept'], fn(array $i): bool => !empty($i['link']))),
        'kept' => count($plan['kept']),
    ];
}
