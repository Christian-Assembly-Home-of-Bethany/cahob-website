<?php
// Router for PHP's built-in server (`make up`). The live server enforces lib/.htaccess, but
// the built-in server ignores .htaccess files, so this blocks the same folders locally.
// Not used on the live site.

// Not parse_url(): it reads "//lib/db.php" as a host name and would skip the check.
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'], 2)[0]);

// Case-insensitive because Windows file names are.
if (preg_match('#^/+(lib|tests|vendor|dev-data)(/|$)#i', $path) || str_contains($path, '/.')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

return false; // serve the file normally
