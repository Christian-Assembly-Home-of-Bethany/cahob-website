<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
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
        ];
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
