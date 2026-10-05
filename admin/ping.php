<?php
// Keeps the login alive while the pastor is actively typing in the editor (the editor calls
// this every few minutes after recent typing). Answers 401 once the login has expired, so the
// editor can say so while the text is still safe in the browser.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

send_admin_headers();
header('Content-Type: application/json');
$signedIn = current_admin() !== null;
http_response_code($signedIn ? 200 : 401);
echo json_encode(['signedIn' => $signedIn]);
