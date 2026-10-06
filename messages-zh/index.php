<?php
// /messages-zh/ is the message list and /messages-zh/<slug> one message (.htaccess passes the slug).

declare(strict_types=1);

$lang = 'zh';
require __DIR__ . (isset($_GET['slug']) ? '/../message.php' : '/../messages.php');
