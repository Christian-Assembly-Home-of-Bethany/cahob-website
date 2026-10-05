<?php
// Logging out is a form post with a token, so another site can't log the pastor out.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

send_admin_headers();
start_admin_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    redirect('/admin/');
}

log_out();
redirect('/admin/login.php?loggedout=1');
