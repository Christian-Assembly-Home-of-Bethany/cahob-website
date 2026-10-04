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
