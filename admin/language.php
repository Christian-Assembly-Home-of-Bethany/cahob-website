<?php
// Switches the admin pages between Chinese (the default) and English, then goes back to the
// page the button was on. The choice is remembered in a cookie for a year.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';

$to = ($_GET['to'] ?? '') === 'en' ? 'en' : 'zh';
setcookie(ADMIN_LANG_COOKIE, $to, [
    'expires' => time() + 365 * 86400,
    'path' => '/admin/',
    'secure' => empty(config()['debug']),
    'httponly' => true,
    'samesite' => 'Lax',
]);
redirect(safe_admin_path($_GET['back'] ?? ''));
