<?php
// /messages/ is the message list and /messages/<slug> one message (.htaccess passes the slug).

declare(strict_types=1);

$lang = 'en';
require __DIR__ . (isset($_GET['slug']) ? '/../message.php' : '/../messages.php');
