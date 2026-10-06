<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** The local router has to block what lib/.htaccess blocks on the live server. */
final class DevRouterTest extends TestCase
{
    private function route(string $uri): bool
    {
        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        $handled = require __DIR__ . '/../lib/tools/dev_router.php';
        ob_end_clean();
        http_response_code(200);
        return $handled;
    }

    public static function blocked(): array
    {
        return [
            ['/lib/bootstrap.php'],
            ['/lib/'],
            ['/LIB/bootstrap.php'],
            ['//lib/db.php'],
            ['/%6cib/db.php'],
            ['/tests/DbTest.php'],
            ['/vendor/autoload.php'],
            ['/dev-data/messages.sqlite'],
            ['/dev-data/config.php'],
            ['/.git/config'],
            ['/lib/.htaccess'],
        ];
    }

    public static function allowed(): array
    {
        return [
            ['/'],
            ['/index.html'],
            ['/styles.css?v=2'],
            ['/images/favicon.svg'],
            ['/content/who-we-are.txt'],
            ['/library.html'],
            ['/messages/'],
            ['/messages-zh/?page=2'],
            ['/messages/index.php'],
            ['/messages/Not_A_Slug'],
        ];
    }

    public static function messageLinks(): array
    {
        return [['/messages/2026-10-05', '2026-10-05'], ['/messages-zh/faith-across-generations/', 'faith-across-generations']];
    }

    #[DataProvider('messageLinks')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testMapsMessageLinksLikeTheHtaccessFiles(string $uri, string $slug): void
    {
        $dir = sys_get_temp_dir() . '/cahob-router-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/config.php', "<?php return ['db_path' => __DIR__ . '/messages.sqlite'];");
        putenv('CAHOB_CONFIG=' . $dir . '/config.php');

        $_SERVER['REQUEST_URI'] = $uri;
        ob_start();
        $handled = require __DIR__ . '/../lib/tools/dev_router.php';
        $html = ob_get_clean();

        $this->assertTrue($handled);
        $this->assertSame($slug, $_GET['slug']);
        $this->assertSame(404, http_response_code(), 'The page ran and found no such message.');
        $this->assertStringContainsString(str_contains($uri, '-zh') ? '找不到這篇信息' : 'Message not found', $html);
    }

    /** Without QSA the live server drops ?version=... when it rewrites /messages/<slug>. The local router keeps it either way. */
    public function testFolderRewritesKeepTheQueryString(): void
    {
        foreach (['messages', 'messages-zh'] as $folder) {
            $rules = file_get_contents(__DIR__ . '/../' . $folder . '/.htaccess');
            $this->assertMatchesRegularExpression('#^RewriteRule \S+ index\.php\?slug=\$1 \[L,QSA\]\r?$#m', $rules, $folder);
        }
    }

    #[DataProvider('blocked')]
    public function testBlocksPrivateFolders(string $uri): void
    {
        $this->assertTrue($this->route($uri), "$uri should be blocked");
    }

    #[DataProvider('allowed')]
    public function testServesPublicFiles(string $uri): void
    {
        $this->assertFalse($this->route($uri), "$uri should be served");
    }
}
