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
        $this->assertSame(['blogger_posts', 'categories', 'login_attempts', 'message_revisions', 'messages', 'slug_redirects'], $this->tables());
        $this->assertSame(6, (int) $this->pdo->query('PRAGMA user_version')->fetchColumn());
    }

    public function testMigratingAgainChangesNothing(): void
    {
        $this->pdo->exec("INSERT INTO messages (slug, title_en) VALUES ('kept', 'Kept')");
        db_migrate($this->pdo);
        $this->assertSame(['blogger_posts', 'categories', 'login_attempts', 'message_revisions', 'messages', 'slug_redirects'], $this->tables());
        $this->assertSame('Kept', $this->pdo->query("SELECT title_en FROM messages WHERE slug = 'kept'")->fetchColumn());
    }

    public function testUpgradingKeepsMessagesAndStartsTheirHistory(): void
    {
        // A database from before version 2, as the first deploy created it.
        $old = db_open(':memory:');
        $old->exec(<<<'SQL'
            CREATE TABLE messages (
              id INTEGER PRIMARY KEY, slug TEXT NOT NULL UNIQUE,
              title_en TEXT NOT NULL DEFAULT '', body_en TEXT NOT NULL DEFAULT '',
              title_zh TEXT NOT NULL DEFAULT '', body_zh TEXT NOT NULL DEFAULT '',
              status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published')),
              published_at TEXT,
              created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE login_attempts (ip TEXT NOT NULL, attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
            INSERT INTO messages (id, slug, body_zh, status, published_at, updated_at)
              VALUES (1, '2026-09-28', '<p>舊信息</p>', 'published', '2026-09-28T17:00:00Z', '2026-09-29 03:00:00'),
                     (2, 'unfinished', '', 'draft', '2026-10-01T17:00:00Z', '2026-10-01 18:00:00');
            PRAGMA user_version = 1;
            SQL);

        db_migrate($old);

        $rows = $old->query('SELECT id, was_published, deleted_at FROM messages ORDER BY id')->fetchAll();
        $this->assertSame([['id' => 1, 'was_published' => 1, 'deleted_at' => null], ['id' => 2, 'was_published' => 0, 'deleted_at' => null]], $rows);
        $history = message_revisions($old, 1);
        $this->assertCount(2, $history, 'Its first version, then the Blogger title from version 4.');
        $first = $history[1];
        $this->assertSame('<p>舊信息</p>', $first['body_zh']);
        $this->assertSame('', $first['title_zh']);
        $this->assertSame('published', $first['status']);
        $this->assertSame('2026-09-29 03:00:00', $first['saved_at'], 'Dated when the message was last saved.');
        $this->assertSame('兩個「免得」與一個「持守」', $history[0]['title_zh']);
        $this->assertCount(1, message_revisions($old, 2));
        $this->assertSame('notes-of-bible-reading', find_category($old, (int) $old->query('SELECT category_id FROM messages WHERE id = 1')->fetchColumn())['slug'], 'The imported 2026-09-28 message gets its Blogger label.');
        $this->assertNull($old->query('SELECT category_id FROM messages WHERE id = 2')->fetchColumn());
    }

    public function testImportedMessagesGetTheirBloggerTitlesOnce(): void
    {
        // The live database before version 4: imported messages have date slugs and no titles.
        $save = fn(string $slug, string $titleEn = ''): int => save_message($this->pdo, [
            'title_en' => $titleEn, 'title_zh' => '', 'body_en' => '<p>Text</p>', 'body_zh' => '<p>內容</p>',
            'status' => 'published', 'published_at' => '2026-09-28T23:44:00Z',
        ]);
        $untitled = $save('2026-09-28');
        $this->pdo->exec("UPDATE messages SET slug = '2026-09-28' WHERE id = $untitled");
        $typed = $save('x', 'Typed by the pastor');
        $this->pdo->exec("UPDATE messages SET slug = '2026-10-02' WHERE id = $typed");
        $this->pdo->exec('DROP TABLE blogger_posts; DROP TABLE slug_redirects; PRAGMA user_version = 3');

        db_migrate($this->pdo);

        $row = find_message($this->pdo, $untitled);
        $this->assertSame('Two “Lests” and One “Holding Fast”', $row['title_en']);
        $this->assertSame('兩個「免得」與一個「持守」', $row['title_zh']);
        $this->assertCount(2, message_revisions($this->pdo, $untitled), 'A new version, so the untitled one can be restored.');

        $row = find_message($this->pdo, $typed);
        $this->assertSame('Typed by the pastor', $row['title_en'], 'A title the pastor typed is kept.');
        $this->assertSame('世代信仰形成的屬靈原則', $row['title_zh'], 'The empty one is filled.');
    }

    public function testDateSlugsBecomeTitleSlugsAndStillWork(): void
    {
        $save = fn(string $titleEn, string $publishedAt): int => save_message($this->pdo, [
            'title_en' => $titleEn, 'title_zh' => '', 'body_en' => '<p>Text</p>', 'body_zh' => '',
            'status' => 'published', 'published_at' => $publishedAt,
        ]);
        $first = $save('', '2026-07-20T17:00:00Z');
        $second = $save('', '2026-08-07T18:00:00Z');
        $chinese = $save('', '2026-08-07T19:00:00Z');
        $named = $save('Already Named', '2026-08-08T19:00:00Z');
        $this->pdo->exec("UPDATE messages SET title_en = 'Same Title' WHERE id IN ($first, $second)");
        $this->pdo->exec("UPDATE messages SET title_zh = '只有中文' WHERE id = $chinese");
        $this->pdo->exec('DROP TABLE blogger_posts; DROP TABLE slug_redirects; PRAGMA user_version = 4');
        $this->assertSame(['2026-07-20', '2026-08-07', '2026-08-07-2', 'already-named'], array_column($this->pdo->query('SELECT slug FROM messages ORDER BY id')->fetchAll(), 'slug'));

        db_migrate($this->pdo);

        $this->assertSame('same-title', find_message($this->pdo, $first)['slug'], 'The older one gets the plain slug.');
        $this->assertSame('same-title-2', find_message($this->pdo, $second)['slug']);
        $this->assertSame('2026-08-07-2', find_message($this->pdo, $chinese)['slug'], 'No English title: the date stays.');
        $this->assertSame('already-named', find_message($this->pdo, $named)['slug']);
        $this->assertSame($second, find_redirect($this->pdo, '2026-08-07')['id'], 'The old link still finds it.');
        $this->assertNull(find_redirect($this->pdo, '2026-08-07-2'), 'Still its real slug, not a redirect.');

        // A new untitled message that day can't take the old link.
        $new = $save('', '2026-08-07T20:00:00Z');
        $this->assertSame('2026-08-07-3', find_message($this->pdo, $new)['slug']);
    }

    public function testCategoriesStartWithTheBloggerLabels(): void
    {
        $names = array_map(fn(array $c): string => $c['name_zh'] . ' / ' . $c['name_en'], all_categories($this->pdo));
        $this->assertSame(['教會歷史 / Church History', '婚姻和家庭 / Marriage & Family', '羅馬書 / Romans', '讀經隨筆 / The Notes of Bible Reading'], $names);
    }

    public function testDeletingACategoryKeepsItsMessages(): void
    {
        $category = find_category_by_slug($this->pdo, 'romans');
        $this->pdo->exec("INSERT INTO messages (slug, category_id) VALUES ('kept', {$category['id']})");
        delete_category($this->pdo, (int) $category['id']);
        $this->assertNull(find_category_by_slug($this->pdo, 'romans'));
        $this->assertNull($this->pdo->query("SELECT category_id FROM messages WHERE slug = 'kept'")->fetchColumn(), 'The message stays, without a category.');
    }

    public function testNewCategoriesGetAFixedSlugFromTheEnglishName(): void
    {
        $first = add_category($this->pdo, 'Hebrews', '希伯來書');
        $second = add_category($this->pdo, 'Hebrews!', '希伯來書二');
        $this->assertSame('hebrews', find_category($this->pdo, $first)['slug']);
        $this->assertSame('hebrews-2', find_category($this->pdo, $second)['slug']);
        rename_category($this->pdo, $first, 'The Book of Hebrews', '希伯來書');
        $this->assertSame('hebrews', find_category($this->pdo, $first)['slug'], 'Renaming keeps the address.');
        $this->assertSame('The Book of Hebrews', find_category($this->pdo, $first)['name_en']);
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
