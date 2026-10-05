<?php
// Bringing back a deleted message: the Restore button in the dashboard's "Deleted messages"
// list posts here. The message comes back with the status it had before it was deleted.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    $id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;
    restore_message(db(), $id);
    redirect('/admin/?restored=1');
}
redirect('/admin/');
