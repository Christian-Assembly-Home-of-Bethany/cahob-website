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
 * every request.
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
    ];

    $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    foreach ($migrations as $version => $sql) {
        if ($version <= $current) {
            continue;
        }
        $pdo->beginTransaction();
        $pdo->exec($sql);
        $pdo->exec("PRAGMA user_version = $version");
        $pdo->commit();
    }
}

// ---------- Public pages: published messages only ----------
// Deleted messages keep their status, so these queries also check deleted_at.

function count_published(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'published' AND deleted_at IS NULL")->fetchColumn();
}

/** Published messages, newest first. */
function published_messages(PDO $pdo, int $limit, int $offset): array
{
    $query = $pdo->prepare(
        "SELECT * FROM messages WHERE status = 'published' AND deleted_at IS NULL
         ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset"
    );
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
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

/** $base, or $base-2, $base-3, ... if another message already uses it. */
function unique_slug(PDO $pdo, string $base, ?int $exceptId = null): string
{
    $taken = $pdo->prepare('SELECT 1 FROM messages WHERE slug = ? AND id IS NOT ?');
    for ($n = 1; ; $n++) {
        $slug = $n === 1 ? $base : "$base-$n";
        $taken->execute([$slug, $exceptId]);
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
    ];

    $outer = $pdo->inTransaction(); // the Blogger import saves all its messages in one transaction
    if (!$outer) {
        $pdo->beginTransaction();
    }
    if ($existing === null) {
        $pdo->prepare(
            'INSERT INTO messages (slug, title_en, body_en, title_zh, body_zh, status, published_at, was_published)
             VALUES (:slug, :title_en, :body_en, :title_zh, :body_zh, :status, :published_at, :was_published)'
        )->execute($values);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare(
            'UPDATE messages SET slug = :slug, title_en = :title_en, body_en = :body_en, title_zh = :title_zh,
             body_zh = :body_zh, status = :status, published_at = :published_at, was_published = :was_published,
             updated_at = CURRENT_TIMESTAMP
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
