<?php
// Opening the SQLite database and keeping its tables up to date.
// All timestamps are stored in UTC; pages convert them to Pacific time for display.

declare(strict_types=1);

function db_open(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5, // seconds to wait if another request is writing
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}

/**
 * Creates the tables on first run and applies any later schema changes. SQLite's
 * user_version records which changes a database already has, so this is safe to call on
 * every request. A change is SQL, or a function for changes SQL can't make (like slugify()).
 */
function db_migrate(PDO $pdo): void
{
    $migrations = [
        1 => <<<'SQL'
            CREATE TABLE messages (
              id           INTEGER PRIMARY KEY,
              slug         TEXT NOT NULL UNIQUE,
              title_en     TEXT NOT NULL DEFAULT '',
              body_en      TEXT NOT NULL DEFAULT '',  -- sanitized HTML
              title_zh     TEXT NOT NULL DEFAULT '',
              body_zh      TEXT NOT NULL DEFAULT '',  -- sanitized HTML
              status       TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
              published_at TEXT,                      -- ISO 8601 UTC; can be back-dated
              created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX idx_messages_pub ON messages (status, published_at DESC);

            CREATE TABLE login_attempts (
              ip           TEXT NOT NULL,
              attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX idx_login_attempts ON login_attempts (ip, attempted_at);
            SQL,
        // Fixed links once published, version history, and deletes that can be undone.
        2 => <<<'SQL'
            ALTER TABLE messages ADD COLUMN was_published INTEGER NOT NULL DEFAULT 0; -- 1 once ever published: the slug is then fixed
            ALTER TABLE messages ADD COLUMN deleted_at TEXT;                         -- set when deleted; NULL brings it back
            UPDATE messages SET was_published = 1 WHERE status = 'published';

            -- A copy of a message each time it's saved, so an earlier version can be brought back.
            CREATE TABLE message_revisions (
              id           INTEGER PRIMARY KEY,
              message_id   INTEGER NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
              title_en     TEXT NOT NULL,
              body_en      TEXT NOT NULL,
              title_zh     TEXT NOT NULL,
              body_zh      TEXT NOT NULL,
              status       TEXT NOT NULL,
              published_at TEXT,
              saved_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX idx_revisions_message ON message_revisions (message_id, id);

            -- Existing messages start their history with the version they have now.
            INSERT INTO message_revisions (message_id, title_en, body_en, title_zh, body_zh, status, published_at, saved_at)
              SELECT id, title_en, body_en, title_zh, body_zh, status, published_at, updated_at FROM messages;
            SQL,
        // Categories (like Blogger's labels), shown as a sidebar on the message list.
        3 => <<<'SQL'
            CREATE TABLE categories (
              id      INTEGER PRIMARY KEY,
              slug    TEXT NOT NULL UNIQUE,  -- the ?category= in the list's address; never changes
              name_en TEXT NOT NULL,
              name_zh TEXT NOT NULL
            );
            ALTER TABLE messages ADD COLUMN category_id INTEGER REFERENCES categories (id) ON DELETE SET NULL;

            -- The pastor's Blogger labels.
            INSERT INTO categories (slug, name_en, name_zh) VALUES
              ('marriage-and-family', 'Marriage & Family', '婚姻和家庭'),
              ('church-history', 'Church History', '教會歷史'),
              ('notes-of-bible-reading', 'The Notes of Bible Reading', '讀經隨筆'),
              ('romans', 'Romans', '羅馬書');

            -- The messages imported from Blogger, by their (date) slugs on cahob.org. Only fills in
            -- messages without a category, and does nothing in databases without these messages.
            UPDATE messages SET category_id = (SELECT id FROM categories WHERE slug = 'marriage-and-family')
              WHERE category_id IS NULL AND slug IN ('2026-07-20', '2026-08-07', '2026-08-25', '2026-09-16', '2026-10-05');
            UPDATE messages SET category_id = (SELECT id FROM categories WHERE slug = 'church-history')
              WHERE category_id IS NULL AND slug IN ('2026-07-19', '2026-07-27', '2026-07-31', '2026-08-07-2', '2026-09-24', '2026-10-02');
            UPDATE messages SET category_id = (SELECT id FROM categories WHERE slug = 'notes-of-bible-reading')
              WHERE category_id IS NULL AND slug IN ('2026-09-28');
            UPDATE messages SET category_id = (SELECT id FROM categories WHERE slug = 'romans')
              WHERE category_id IS NULL AND slug IN ('2026-08-02', '2026-08-16', '2026-08-24');
            SQL,
        // Titles for the messages imported from Blogger, whose titles were only the first line of
        // the text. Only fills empty titles, so nothing the pastor typed is replaced, and the
        // links stay the same (published slugs never change).
        4 => <<<'SQL'
            UPDATE messages SET title_en = 'Who Are We? Part Two: The Revelation of Jesus Christ' WHERE slug = '2026-07-19' AND title_en = '';
            UPDATE messages SET title_en = 'Marriage Must Always Be Understood from God''s Perspective' WHERE slug = '2026-07-20' AND title_en = '';
            UPDATE messages SET title_zh = '婚姻必須始終從神的角度來理解' WHERE slug = '2026-07-20' AND title_zh = '';
            UPDATE messages SET title_en = 'Who Are We? Part 3-1: A Fragrance of Christ' WHERE slug = '2026-07-27' AND title_en = '';
            UPDATE messages SET title_zh = '我們是誰（三之一）：基督的馨香之氣' WHERE slug = '2026-07-27' AND title_zh = '';
            UPDATE messages SET title_en = 'Who Are We? Part 3-2: A Fragrance of Christ' WHERE slug = '2026-07-31' AND title_en = '';
            UPDATE messages SET title_zh = '我們是誰（三之二）：基督的馨香之氣' WHERE slug = '2026-07-31' AND title_zh = '';
            UPDATE messages SET title_en = 'The Apostle and the Gospel of God (1)' WHERE slug = '2026-08-02' AND title_en = '';
            UPDATE messages SET title_zh = '使徒保羅與神的福音（一）' WHERE slug = '2026-08-02' AND title_zh = '';
            UPDATE messages SET title_en = 'Marriage Must Always Be Understood from God''s Perspective' WHERE slug = '2026-08-07' AND title_en = '';
            UPDATE messages SET title_zh = '婚姻必須始終從神的角度來理解' WHERE slug = '2026-08-07' AND title_zh = '';
            UPDATE messages SET title_en = 'Who Are We? Part 3-3: A Fragrance of Christ' WHERE slug = '2026-08-07-2' AND title_en = '';
            UPDATE messages SET title_zh = '我們是誰（三之三）：基督的馨香之氣' WHERE slug = '2026-08-07-2' AND title_zh = '';
            UPDATE messages SET title_en = 'Called into the Fellowship of His Son' WHERE slug = '2026-08-16' AND title_en = '';
            UPDATE messages SET title_zh = '蒙召進入祂兒子的交通' WHERE slug = '2026-08-16' AND title_zh = '';
            UPDATE messages SET title_en = 'Called According to His Purpose' WHERE slug = '2026-08-24' AND title_en = '';
            UPDATE messages SET title_zh = '按著祂的旨意被召' WHERE slug = '2026-08-24' AND title_zh = '';
            UPDATE messages SET title_en = 'Classical Christian Education' WHERE slug = '2026-08-25' AND title_en = '';
            UPDATE messages SET title_zh = '基督教古典教育' WHERE slug = '2026-08-25' AND title_zh = '';
            UPDATE messages SET title_en = 'Train Up a Child in the Way He Should Go (Chapter 1)' WHERE slug = '2026-09-16' AND title_en = '';
            UPDATE messages SET title_zh = '教養孩童，使他走當行的道（第一章）' WHERE slug = '2026-09-16' AND title_zh = '';
            UPDATE messages SET title_en = 'Moralistic Therapeutic Deism and the American Church' WHERE slug = '2026-09-24' AND title_en = '';
            UPDATE messages SET title_zh = '道德主義治療式自然神論與美國教會' WHERE slug = '2026-09-24' AND title_zh = '';
            UPDATE messages SET title_en = 'Two “Lests” and One “Holding Fast”' WHERE slug = '2026-09-28' AND title_en = '';
            UPDATE messages SET title_zh = '兩個「免得」與一個「持守」' WHERE slug = '2026-09-28' AND title_zh = '';
            UPDATE messages SET title_en = 'Spiritual Principles in the Formation of Faith Across Generations' WHERE slug = '2026-10-02' AND title_en = '';
            UPDATE messages SET title_zh = '世代信仰形成的屬靈原則' WHERE slug = '2026-10-02' AND title_zh = '';
            UPDATE messages SET title_en = 'Train Up a Child in the Way He Should Go (Chapter 2)' WHERE slug = '2026-10-05' AND title_en = '';
            UPDATE messages SET title_zh = '教養孩童，使他走當行的道（第二章）' WHERE slug = '2026-10-05' AND title_zh = '';

            -- A version for each message that got a title, so the untitled one can be restored.
            INSERT INTO message_revisions (message_id, title_en, body_en, title_zh, body_zh, status, published_at)
              SELECT m.id, m.title_en, m.body_en, m.title_zh, m.body_zh, m.status, m.published_at FROM messages m
              JOIN message_revisions r ON r.id = (SELECT MAX(id) FROM message_revisions WHERE message_id = m.id)
              WHERE m.slug IN ('2026-07-19', '2026-07-20', '2026-07-27', '2026-07-31', '2026-08-02', '2026-08-07', '2026-08-07-2', '2026-08-16', '2026-08-24', '2026-08-25', '2026-09-16', '2026-09-24', '2026-09-28', '2026-10-02', '2026-10-05')
                AND (r.title_en <> m.title_en OR r.title_zh <> m.title_zh);
            SQL,
        // Links from titles instead of dates: published messages with a date slug and an English
        // title get a slug from that title. The old date slug keeps working as a redirect.
        5 => function (PDO $pdo): void {
            $pdo->exec(<<<'SQL'
                CREATE TABLE slug_redirects (
                  old_slug   TEXT PRIMARY KEY,
                  message_id INTEGER NOT NULL REFERENCES messages (id) ON DELETE CASCADE
                );
                SQL);
            $dated = $pdo->query(
                "SELECT * FROM messages WHERE title_en <> '' AND slug GLOB '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*'
                 ORDER BY published_at, id"
            )->fetchAll();
            foreach ($dated as $message) {
                $base = slugify($message['title_en']);
                if ($base === '') {
                    continue;
                }
                $pdo->prepare('INSERT INTO slug_redirects (old_slug, message_id) VALUES (?, ?)')->execute([$message['slug'], $message['id']]);
                $pdo->prepare('UPDATE messages SET slug = ? WHERE id = ?')->execute([unique_slug($pdo, $base, (int) $message['id']), $message['id']]);
            }
        },
        // Sync from Blogger: which Blogger post fills which language of which message.
        6 => <<<'SQL'
            CREATE TABLE blogger_posts (
              blogger_id      TEXT PRIMARY KEY,
              message_id      INTEGER NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
              lang            TEXT NOT NULL CHECK (lang IN ('en', 'zh')),
              url             TEXT NOT NULL DEFAULT '',
              blogger_updated TEXT NOT NULL,  -- the post's "updated" time when it was last synced
              synced_hash     TEXT NOT NULL   -- sha1 of the text as Blogger had it then, to spot edits made on the website
            );
            CREATE UNIQUE INDEX idx_blogger_posts_message ON blogger_posts (message_id, lang);
            SQL,
    ];

    $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    foreach ($migrations as $version => $sql) {
        if ($version <= $current) {
            continue;
        }
        $pdo->beginTransaction();
        is_callable($sql) ? $sql($pdo) : $pdo->exec($sql);
        $pdo->exec("PRAGMA user_version = $version");
        $pdo->commit();
    }
}

// ---------- Public pages: published messages only ----------
// Deleted messages keep their status, so these queries also check deleted_at.

/** Published messages, in one category if $categoryId is given. */
function count_published(PDO $pdo, ?int $categoryId = null): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM messages WHERE status = 'published' AND deleted_at IS NULL
         AND (:category IS NULL OR category_id = :category)"
    );
    $query->execute(['category' => $categoryId]);
    return (int) $query->fetchColumn();
}

/** Published messages, newest first, in one category if $categoryId is given. */
function published_messages(PDO $pdo, int $limit, int $offset, ?int $categoryId = null): array
{
    $query = $pdo->prepare(
        "SELECT * FROM messages WHERE status = 'published' AND deleted_at IS NULL
         AND (:category IS NULL OR category_id = :category)
         ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset"
    );
    $query->bindValue(':category', $categoryId, $categoryId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
}

/** The published message an old slug (from before it had a title) now points to. */
function find_redirect(PDO $pdo, string $oldSlug): ?array
{
    $query = $pdo->prepare(
        "SELECT m.* FROM slug_redirects r JOIN messages m ON m.id = r.message_id
         WHERE r.old_slug = ? AND m.status = 'published' AND m.deleted_at IS NULL"
    );
    $query->execute([$oldSlug]);
    return $query->fetch() ?: null;
}

function find_published(PDO $pdo, string $slug): ?array
{
    $query = $pdo->prepare("SELECT * FROM messages WHERE slug = ? AND status = 'published' AND deleted_at IS NULL");
    $query->execute([$slug]);
    return $query->fetch() ?: null;
}

// ---------- Admin: all messages, saving, deleting ----------

/** Every message that isn't deleted, drafts included, newest first. */
function all_messages(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM messages WHERE deleted_at IS NULL ORDER BY COALESCE(published_at, created_at) DESC, id DESC')->fetchAll();
}

/** Deleted messages, most recently deleted first. */
function deleted_messages(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM messages WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC')->fetchAll();
}

/** A message that isn't deleted. */
function find_message(PDO $pdo, int $id): ?array
{
    $query = $pdo->prepare('SELECT * FROM messages WHERE id = ? AND deleted_at IS NULL');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

/** A URL slug from an English title: lowercase words joined by hyphens, about 60 characters at most. */
function slugify(string $title): string
{
    $slug = strtolower(str_replace(["'", '’'], '', $title));
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    if (strlen($slug) > 60) {
        $cut = substr($slug, 0, 61);
        $slug = substr($cut, 0, strrpos($cut, '-') ?: 60);
    }
    return trim($slug, '-');
}

/**
 * $base, or $base-2, $base-3, ... if another message already uses it, either as its slug or as
 * an old slug that redirects to it (so an old shared link never shows a different message).
 */
function unique_slug(PDO $pdo, string $base, ?int $exceptId = null): string
{
    $taken = $pdo->prepare(
        'SELECT 1 FROM messages WHERE slug = :slug AND id IS NOT :id
         UNION ALL SELECT 1 FROM slug_redirects WHERE old_slug = :slug AND message_id IS NOT :id'
    );
    for ($n = 1; ; $n++) {
        $slug = $n === 1 ? $base : "$base-$n";
        $taken->execute(['slug' => $slug, 'id' => $exceptId]);
        if ($taken->fetchColumn() === false) {
            return $slug;
        }
    }
}

/**
 * Inserts or updates a message, keeps a copy in message_revisions, and returns its id. Until a
 * message is first published, its slug follows the English title (or the date when there's no
 * English title). After that the slug never changes, even if the message is unpublished,
 * renamed, and published again, so links that were already shared keep working.
 *
 * $message has title_en, body_en, title_zh, body_zh (already sanitized), status, published_at.
 */
function save_message(PDO $pdo, array $message, ?array $existing = null): int
{
    $slug = $existing['slug'] ?? null;
    if (empty($existing['was_published'])) {
        $base = slugify($message['title_en']);
        if ($base === '') {
            $base = (new DateTimeImmutable($message['published_at'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
        }
        $slug = unique_slug($pdo, $base, $existing['id'] ?? null);
    }

    $values = [
        'slug' => $slug,
        'title_en' => $message['title_en'],
        'body_en' => $message['body_en'],
        'title_zh' => $message['title_zh'],
        'body_zh' => $message['body_zh'],
        'status' => $message['status'],
        'published_at' => $message['published_at'],
        'was_published' => (int) (!empty($existing['was_published']) || $message['status'] === 'published'),
        // Callers that don't deal with categories (e.g. the Blogger import) leave it as it was.
        'category_id' => array_key_exists('category_id', $message) ? $message['category_id'] : ($existing['category_id'] ?? null),
    ];

    $outer = $pdo->inTransaction(); // the Blogger import saves all its messages in one transaction
    if (!$outer) {
        $pdo->beginTransaction();
    }
    if ($existing === null) {
        $pdo->prepare(
            'INSERT INTO messages (slug, title_en, body_en, title_zh, body_zh, status, published_at, was_published, category_id)
             VALUES (:slug, :title_en, :body_en, :title_zh, :body_zh, :status, :published_at, :was_published, :category_id)'
        )->execute($values);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare(
            'UPDATE messages SET slug = :slug, title_en = :title_en, body_en = :body_en, title_zh = :title_zh,
             body_zh = :body_zh, status = :status, published_at = :published_at, was_published = :was_published,
             category_id = :category_id, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        )->execute($values + ['id' => $existing['id']]);
        $id = (int) $existing['id'];
    }
    add_revision($pdo, $id);
    if (!$outer) {
        $pdo->commit();
    }
    return $id;
}

/** Hides a message from the site and the dashboard. Nothing is erased, so restore_message() can bring it back. */
function delete_message(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE messages SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL')->execute([$id]);
}

/** Brings back a deleted message, with the status it had before. */
function restore_message(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE messages SET deleted_at = NULL WHERE id = ?')->execute([$id]);
}

// ---------- Version history ----------

const REVISION_FIELDS = 'title_en, body_en, title_zh, body_zh, status, published_at';

/** Saves a copy of a message as it is now, unless the latest copy is already the same. */
function add_revision(PDO $pdo, int $messageId): void
{
    $current = $pdo->prepare('SELECT ' . REVISION_FIELDS . ' FROM messages WHERE id = ?');
    $current->execute([$messageId]);
    $latest = $pdo->prepare('SELECT ' . REVISION_FIELDS . ' FROM message_revisions WHERE message_id = ? ORDER BY id DESC LIMIT 1');
    $latest->execute([$messageId]);
    if ($latest->fetch() === $current->fetch()) {
        return; // saved again without changing anything
    }
    $pdo->prepare(
        'INSERT INTO message_revisions (message_id, ' . REVISION_FIELDS . ')
         SELECT id, ' . REVISION_FIELDS . ' FROM messages WHERE id = ?'
    )->execute([$messageId]);
}

/** Every saved version of a message, newest (the current one) first. */
function message_revisions(PDO $pdo, int $messageId): array
{
    $query = $pdo->prepare('SELECT * FROM message_revisions WHERE message_id = ? ORDER BY id DESC');
    $query->execute([$messageId]);
    return $query->fetchAll();
}

/** One saved version, only if it belongs to that message. */
function find_revision(PDO $pdo, int $messageId, int $revisionId): ?array
{
    $query = $pdo->prepare('SELECT * FROM message_revisions WHERE id = ? AND message_id = ?');
    $query->execute([$revisionId, $messageId]);
    return $query->fetch() ?: null;
}

// ---------- Categories ----------

/** Every category, in name order (English names, so both languages list them the same way). */
function all_categories(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM categories ORDER BY name_en COLLATE NOCASE, id')->fetchAll();
}

function find_category(PDO $pdo, int $id): ?array
{
    $query = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $query->execute([$id]);
    return $query->fetch() ?: null;
}

function find_category_by_slug(PDO $pdo, string $slug): ?array
{
    $query = $pdo->prepare('SELECT * FROM categories WHERE slug = ?');
    $query->execute([$slug]);
    return $query->fetch() ?: null;
}

/** Categories with at least one published message, each with its 'count', for the public sidebar. */
function categories_with_counts(PDO $pdo): array
{
    return $pdo->query(
        "SELECT c.*, COUNT(*) AS count FROM categories c
         JOIN messages m ON m.category_id = c.id AND m.status = 'published' AND m.deleted_at IS NULL
         GROUP BY c.id ORDER BY c.name_en COLLATE NOCASE, c.id"
    )->fetchAll();
}

/** How many messages (drafts and deleted ones included) are in each category, by id. */
function category_usage(PDO $pdo): array
{
    return $pdo->query('SELECT category_id, COUNT(*) FROM messages WHERE category_id IS NOT NULL GROUP BY category_id')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Adds a category and returns its id. Its slug comes from the English name and never changes. */
function add_category(PDO $pdo, string $nameEn, string $nameZh): int
{
    $base = slugify($nameEn) ?: 'category';
    $slug = $base;
    $taken = $pdo->prepare('SELECT 1 FROM categories WHERE slug = ?');
    for ($n = 2; $taken->execute([$slug]) && $taken->fetchColumn() !== false; $n++) {
        $slug = "$base-$n";
    }
    $pdo->prepare('INSERT INTO categories (slug, name_en, name_zh) VALUES (?, ?, ?)')->execute([$slug, $nameEn, $nameZh]);
    return (int) $pdo->lastInsertId();
}

/** Renames a category. Its slug (and so its address) stays the same. */
function rename_category(PDO $pdo, int $id, string $nameEn, string $nameZh): void
{
    $pdo->prepare('UPDATE categories SET name_en = ?, name_zh = ? WHERE id = ?')->execute([$nameEn, $nameZh, $id]);
}

/** Deletes a category; its messages are kept and just lose the category. */
function delete_category(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
}
