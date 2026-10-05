<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/tools/import_blogger.php';

final class BloggerImportTest extends TestCase
{
    private const FEED = __DIR__ . '/fixtures/blogger-feed.xml';

    private function posts(): array
    {
        return blogger_posts(self::FEED);
    }

    private function urlsOf(array $message): array
    {
        return [$message['zh']['url'] ?? null, $message['en']['url'] ?? null];
    }

    // ---------- Reading ----------

    public function testReadsOnlyPublishedPosts(): void
    {
        $posts = $this->posts();
        $this->assertCount(9, $posts, 'The comment and the draft are skipped.');
        $this->assertSame('2026-07-16T17:59:04Z', $posts[0]['published'], 'Stored in UTC.');
        $this->assertSame('https://test.blogspot.com/2026/07/zh1.html', $posts[0]['url']);
        $this->assertStringContainsString('測試標題一', $posts[0]['html']);
    }

    public function testRejectsSomethingThatIsNotAFeed(): void
    {
        $this->expectException(RuntimeException::class);
        blogger_feed_page('<html><body>Not a feed</body></html>');
    }

    // ---------- Language and pairing ----------

    public function testLanguageComesFromTheText(): void
    {
        $this->assertSame('zh', post_language('<p>我們是誰，提到 YouTube 一次。</p>'));
        $this->assertSame('en', post_language('<p>Marriage test content, mentioning 中文 once.</p>'));
    }

    public function testPairsEachDaysPostsByClosestTime(): void
    {
        $messages = pair_posts($this->posts());

        $this->assertSame([
            ['https://test.blogspot.com/2026/07/zh1.html', 'https://test.blogspot.com/2026/07/en1.html'],
            ['https://test.blogspot.com/2026/08/zh2.html', 'https://test.blogspot.com/2026/08/en2.html'],
            ['https://test.blogspot.com/2026/08/zh3.html', 'https://test.blogspot.com/2026/08/en3.html'],
            ['https://test.blogspot.com/2026/09/zh4.html', null],
            [null, 'https://test.blogspot.com/2026/09/en5.html'],
            ['https://test.blogspot.com/2026/09/zh5.html', null],
        ], array_map(fn(array $m): array => $this->urlsOf($m), $messages));
    }

    public function testAPairIsDatedByItsEarlierPost(): void
    {
        $messages = pair_posts($this->posts());
        $this->assertSame('2026-07-16T17:59:04Z', $messages[0]['published_at']);
        $this->assertSame('2026-08-07T18:21:00Z', $messages[2]['published_at'], 'English 11:21 came before Chinese 11:23.');
    }

    // ---------- Word cleanup ----------

    public function testWordFormattingIsCleanedUp(): void
    {
        $html = clean_blogger_html($this->posts()[0]['html']);

        $this->assertSame(
            '<p style="text-align:center;"><strong>測試標題一</strong></p>'
            . '<p>這是第一段，有<strong>粗體</strong>和<em>斜體</em>。</p>'
            . '<hr>'
            . '<p>第二段第一行<br>第二段第二行</p>',
            preg_replace('/>\s+</', '><', $html),
        );
    }

    public function testDivsLinksAndTablesFromEnglishPost(): void
    {
        $html = preg_replace('/>\s+</', '><', clean_blogger_html($this->posts()[1]['html']));

        $this->assertStringStartsWith('<p><strong>Test Title One</strong></p><p>First paragraph with a <a href="https://example.org/page">link</a>.</p>', $html);
        $this->assertStringContainsString('<table><tbody><tr><td><p>Week</p></td><td>Chapters</td></tr><tr><td>1</td><td>1–4</td></tr></tbody></table>', $html);
        $this->assertStringContainsString('<p>Unclosed paragraph</p><p>Next paragraph</p>', $html);
        $this->assertStringNotContainsString('<p></p>', $html);
        $this->assertStringNotContainsString('<p><br></p>', $html, 'Empty spacing lines are removed.');
    }

    // ---------- Saving ----------

    private function memoryDb(): PDO
    {
        $pdo = db_open(':memory:');
        db_migrate($pdo);
        return $pdo;
    }

    public function testImportSavesPublishedUntitledMessagesDatedAsOnBlogger(): void
    {
        $pdo = $this->memoryDb();
        $count = import_messages($pdo, messages_to_import(pair_posts($this->posts())), false);

        $this->assertSame(6, $count);
        $rows = $pdo->query('SELECT slug, status, title_en, title_zh, published_at, body_en <> \'\' AS en, body_zh <> \'\' AS zh FROM messages ORDER BY id')->fetchAll();
        $this->assertSame(
            ['2026-07-16', '2026-08-07', '2026-08-07-2', '2026-09-01', '2026-09-02', '2026-09-03'],
            array_column($rows, 'slug'),
        );
        foreach ($rows as $row) {
            $this->assertSame('published', $row['status']);
            $this->assertSame('', $row['title_en']);
            $this->assertSame('', $row['title_zh']);
        }
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE was_published = 1')->fetchColumn(), 'Their links are fixed.');
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM message_revisions')->fetchColumn(), 'Each starts its version history.');
        $this->assertSame(1, (int) $rows[0]['en']);
        $this->assertSame(1, (int) $rows[0]['zh']);
        $this->assertSame(0, (int) $rows[3]['en'], 'The Chinese-only post has no English section.');
        $this->assertSame(6, count_published($pdo), 'Visible on the public pages.');
    }

    public function testImportRefusesADatabaseThatAlreadyHasMessages(): void
    {
        $pdo = $this->memoryDb();
        $pdo->exec("INSERT INTO messages (slug) VALUES ('already-here')");
        $this->expectException(RuntimeException::class);
        import_messages($pdo, messages_to_import(pair_posts($this->posts())), false);
    }

    public function testReplaceDeletesWhatWasThere(): void
    {
        $pdo = $this->memoryDb();
        save_message($pdo, ['title_en' => 'Already here', 'body_en' => '<p>x</p>', 'title_zh' => '', 'body_zh' => '', 'status' => 'draft', 'published_at' => '2026-10-01T18:00:00Z']);
        import_messages($pdo, messages_to_import(pair_posts($this->posts())), true);
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn());
        $this->assertFalse($pdo->query("SELECT 1 FROM messages WHERE slug = 'already-here'")->fetchColumn());
        $this->assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM message_revisions')->fetchColumn(), 'Its history went with it.');
    }

    // ---------- Command line ----------

    private function runImport(array $args): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../lib/tools/import_blogger.php');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $output, $code);
        return [$code, implode("\n", $output)];
    }

    public function testDryRunWritesNothing(): void
    {
        $db = sys_get_temp_dir() . '/cahob-import-' . bin2hex(random_bytes(4)) . '.sqlite';
        [$code, $output] = $this->runImport(['--dry-run', '--db=' . $db, self::FEED]);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Read 9 published posts', $output);
        $this->assertStringContainsString('6 messages to import', $output);
        $this->assertStringContainsString('Dry run: nothing was written.', $output);
        $this->assertFileDoesNotExist($db);
    }

    public function testImportIntoANewFileThenRefusesToRunTwice(): void
    {
        $db = sys_get_temp_dir() . '/cahob-import-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            [$code, $output] = $this->runImport(['--db=' . $db, self::FEED]);
            $this->assertSame(0, $code, $output);
            $this->assertStringContainsString('Imported 6 messages', $output);

            [$code, $output] = $this->runImport(['--db=' . $db, self::FEED]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('already has 6 messages', $output);
        } finally {
            @unlink($db);
        }
    }
}
