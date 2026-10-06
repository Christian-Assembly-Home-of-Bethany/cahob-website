<?php
// Router for PHP's built-in server (`make up`). The live server enforces the .htaccess files, but
// the built-in server ignores them, so this blocks the same folders and maps message links locally.
// Not used on the live site.

// Not parse_url(): it reads "//lib/db.php" as a host name and would skip the check.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);

// Case-insensitive because Windows file names are.
if (preg_match('#^/+(lib|tests|vendor|dev-data)(/|$)#i', $path) || str_contains($path, '/.')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// What messages/.htaccess and messages-zh/.htaccess do on the live server.
if (preg_match('#^/(messages(?:-zh)?)/([a-z0-9-]+)/?$#', $path, $m)) {
    $_GET['slug'] = $m[2];
    require __DIR__ . '/../../' . $m[1] . '/index.php';
    return true;
}

return false; // serve the file normally
