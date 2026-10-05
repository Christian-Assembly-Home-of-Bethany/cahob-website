<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class MessageQueriesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = db_open(':memory:');
        db_migrate($this->pdo);
        $insert = $this->pdo->prepare('INSERT INTO messages (slug, status, published_at) VALUES (?, ?, ?)');
        $insert->execute(['oldest', 'published', '2026-07-16T18:00:00Z']);
        $insert->execute(['newest', 'published', '2026-10-02T18:00:00Z']);
        $insert->execute(['middle', 'published', '2026-08-07T18:00:00Z']);
        $insert->execute(['draft', 'draft', '2026-10-03T18:00:00Z']);
    }

    private function slugs(array $rows): array
    {
        return array_column($rows, 'slug');
    }

    public function testOnlyPublishedMessagesAreCounted(): void
    {
        $this->assertSame(3, count_published($this->pdo));
    }

    public function testPublishedMessagesAreNewestFirst(): void
    {
        $this->assertSame(['newest', 'middle', 'oldest'], $this->slugs(published_messages($this->pdo, 10, 0)));
    }

    public function testPagingUsesLimitAndOffset(): void
    {
        $this->assertSame(['newest', 'middle'], $this->slugs(published_messages($this->pdo, 2, 0)));
        $this->assertSame(['oldest'], $this->slugs(published_messages($this->pdo, 2, 2)));
        $this->assertSame([], published_messages($this->pdo, 2, 4));
    }

    public function testFindsAPublishedMessageBySlug(): void
    {
        $this->assertSame('middle', find_published($this->pdo, 'middle')['slug']);
    }

    public function testSqlInASlugIsJustASlugThatDoesNotExist(): void
    {
        foreach (["' OR 1=1 --", "draft' OR status = 'draft", "x'; DROP TABLE messages; --"] as $attack) {
            $this->assertNull(find_published($this->pdo, $attack), $attack);
        }
        $this->assertSame(3, count_published($this->pdo), 'The table is untouched.');
    }

    public function testDraftsAndMissingSlugsAreNotFound(): void
    {
        $this->assertNull(find_published($this->pdo, 'draft'));
        $this->assertNull(find_published($this->pdo, 'no-such-message'));
    }
}
