<?php
// Shared setup for every Messages page and tool: settings, config, and the database.
// Pages start with `require __DIR__ . '/lib/bootstrap.php';` and then call config() / db().

declare(strict_types=1);

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Los_Angeles');
ini_set('display_errors', '0');

/** config.php is missing or incomplete, e.g. code deployed before the one-time server setup. */
final class NotConfiguredException extends RuntimeException
{
}

/**
 * Where config.php lives. The CAHOB_CONFIG environment variable overrides it (local dev and
 * command-line tools). Otherwise it's ~/cahob-data/config.php, one folder above the web root.
 */
function config_path(string|false $override, string $documentRoot): string
{
    if ($override !== false && $override !== '') {
        return $override;
    }
    if ($documentRoot === '') {
        return ''; // command line without CAHOB_CONFIG: there's no web root to start from
    }
    return dirname($documentRoot) . '/cahob-data/config.php';
}

/** The folder holding config.php: ~/cahob-data on the server, dev-data/ locally. Sessions and logs live here too. */
function data_dir(): string
{
    return dirname(config_path(getenv('CAHOB_CONFIG'), $_SERVER['DOCUMENT_ROOT'] ?? ''));
}

function load_config(string $path): array
{
    if ($path === '' || !is_file($path)) {
        throw new NotConfiguredException('config.php not found. Set CAHOB_CONFIG or do the one-time server setup.');
    }
    $config = require $path;
    if (!is_array($config) || empty($config['db_path'])) {
        throw new NotConfiguredException('config.php is incomplete: it must return an array with db_path.');
    }
    return $config;
}

function config(): array
{
    static $config;
    if ($config === null) {
        $config = load_config(config_path(getenv('CAHOB_CONFIG'), $_SERVER['DOCUMENT_ROOT'] ?? ''));
        if (!empty($config['debug'])) {
            ini_set('display_errors', '1');
        }
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $pdo = db_open(config()['db_path']);
        db_migrate($pdo);
    }
    return $pdo;
}

/** A bare page for when something stops the real page from loading. Never shows server paths. */
function fallback_page(string $titleEn, string $titleZh, string $detail = ''): string
{
    $detail = $detail === '' ? '' : '<pre>' . htmlspecialchars($detail) . '</pre>';
    return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
          <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <meta name="robots" content="noindex" />
            <title>CAHOB | Messages</title>
            <style>
              body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
                     box-sizing: border-box; background: #f4f8fb; color: #33475b; text-align: center;
                     font-family: "Nunito Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
              h1 { font-family: Lora, Georgia, serif; color: #12324f; font-weight: 600; margin: 0 0 8px; }
              p { margin: 0 0 24px; font-size: 1.1rem; }
              a { color: #1f4b72; }
              pre { text-align: left; white-space: pre-wrap; max-width: 60rem; font-size: 0.8rem; }
            </style>
          </head>
          <body>
            <main>
              <h1>{$titleEn}</h1>
              <p lang="zh-Hant">{$titleZh}</p>
              <a href="/index.html">Back to the home page</a> &middot; <a href="/index-zh.html" lang="zh-Hant">返回首頁</a>
              {$detail}
            </main>
          </body>
        </html>
        HTML;
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(function (Throwable $e): void {
        if ($e instanceof NotConfiguredException) {
            http_response_code(503);
            echo fallback_page('Messages are coming soon', '信息即將推出');
            return;
        }
        error_log((string) $e);
        http_response_code(500);
        $debug = ini_get('display_errors') === '1';
        echo fallback_page('Something went wrong', '發生錯誤，請稍後再試。', $debug ? (string) $e : '');
    });
}
