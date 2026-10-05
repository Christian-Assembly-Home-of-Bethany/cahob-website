<?php
// Settings for the Messages CMS. Copy this file to ~/cahob-data/config.php on the server
// (`make up` makes a local copy in dev-data/) and fill it in. Never commit a filled-in copy.

return [
    // The SQLite database. Keep it next to this file, outside the web root.
    'db_path' => __DIR__ . '/messages.sqlite',

    // The one admin account, for the pastor. password_hash is a bcrypt hash, never the
    // password itself: `make password` prints one (`make dev-password` sets the local one).
    'admin' => [
        'username' => 'pastor',
        'password_hash' => '',
    ],

    // Show error details on the page. Only ever true on a local machine.
    'debug' => false,
];
