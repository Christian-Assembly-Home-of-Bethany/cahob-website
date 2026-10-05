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

function count_published(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE status = 'published'")->fetchColumn();
}

/** Published messages, newest first. */
function published_messages(PDO $pdo, int $limit, int $offset): array
{
    $query = $pdo->prepare(
        "SELECT * FROM messages WHERE status = 'published'
         ORDER BY published_at DESC, id DESC LIMIT :limit OFFSET :offset"
    );
    $query->bindValue(':limit', $limit, PDO::PARAM_INT);
    $query->bindValue(':offset', $offset, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll();
}

function find_published(PDO $pdo, string $slug): ?array
{
    $query = $pdo->prepare("SELECT * FROM messages WHERE slug = ? AND status = 'published'");
    $query->execute([$slug]);
    return $query->fetch() ?: null;
}

// ---------- Admin: all messages, saving, deleting ----------

/** Every message, drafts included, newest first. */
function all_messages(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM messages ORDER BY COALESCE(published_at, created_at) DESC, id DESC')->fetchAll();
}

function find_message(PDO $pdo, int $id): ?array
{
    $query = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
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
 * Inserts or updates a message and returns its id. While a message is a draft its slug follows
 * the English title (or the date when there's no English title). Once it has been saved as
 * published, the slug never changes, so links to it keep working.
 *
 * $message has title_en, body_en, title_zh, body_zh (already sanitized), status, published_at.
 */
function save_message(PDO $pdo, array $message, ?array $existing = null): int
{
    $slug = $existing['slug'] ?? null;
    if ($existing === null || $existing['status'] !== 'published') {
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
    ];
    if ($existing === null) {
        $pdo->prepare(
            'INSERT INTO messages (slug, title_en, body_en, title_zh, body_zh, status, published_at)
             VALUES (:slug, :title_en, :body_en, :title_zh, :body_zh, :status, :published_at)'
        )->execute($values);
        return (int) $pdo->lastInsertId();
    }
    $pdo->prepare(
        'UPDATE messages SET slug = :slug, title_en = :title_en, body_en = :body_en, title_zh = :title_zh,
         body_zh = :body_zh, status = :status, published_at = :published_at, updated_at = CURRENT_TIMESTAMP
         WHERE id = :id'
    )->execute($values + ['id' => $existing['id']]);
    return (int) $existing['id'];
}

function delete_message(PDO $pdo, int $id): void
{
    $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$id]);
}
