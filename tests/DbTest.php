<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = db_open(':memory:');
        db_migrate($this->pdo);
    }

    private function tables(): array
    {
        return $this->pdo
            ->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    public function testFirstRunCreatesTheTables(): void
    {
        $this->assertSame(['login_attempts', 'messages'], $this->tables());
        $this->assertSame(1, (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testMigratingAgainChangesNothing(): void
    {
        $this->pdo->exec("INSERT INTO messages (slug, title_en) VALUES ('kept', 'Kept')");
        db_migrate($this->pdo);
        $this->assertSame(['login_attempts', 'messages'], $this->tables());
        $this->assertSame('Kept', $this->pdo->query("SELECT title_en FROM messages WHERE slug = 'kept'")->fetchColumn());
    }

    public function testNewMessageDefaultsToAnEmptyDraft(): void
    {
        $this->pdo->exec("INSERT INTO messages (slug) VALUES ('new')");
        $row = $this->pdo->query("SELECT * FROM messages WHERE slug = 'new'")->fetch();
        $this->assertSame('draft', $row['status']);
        $this->assertSame('', $row['title_en']);
        $this->assertSame('', $row['body_zh']);
        $this->assertNull($row['published_at']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testStatusMustBeDraftOrPublished(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO messages (slug, status) VALUES ('bad', 'archived')");
    }

    public function testSlugsAreUnique(): void
    {
        $this->pdo->exec("INSERT INTO messages (slug) VALUES ('same')");
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO messages (slug) VALUES ('same')");
    }

    public function testChineseTextRoundTrips(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO messages (slug, title_zh) VALUES (?, ?)');
        $insert->execute(['zh', '我們是誰（一）']);
        $this->assertSame('我們是誰（一）', $this->pdo->query("SELECT title_zh FROM messages WHERE slug = 'zh'")->fetchColumn());
    }

    public function testDatabaseFileIsCreatedOnFirstOpen(): void
    {
        $path = sys_get_temp_dir() . '/cahob-test-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            $pdo = db_open($path);
            db_migrate($pdo);
            $this->assertFileExists($path);
            unset($pdo);
        } finally {
            @unlink($path);
        }
    }
}
