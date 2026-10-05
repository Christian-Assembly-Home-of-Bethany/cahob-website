<?php
// Makes the bcrypt hash that goes in config.php as the admin's password_hash. The password is
// read from standard input, so it never ends up in shell history.
//
//   make password       prints a hash to paste into ~/cahob-data/config.php on the server
//   make dev-password   writes the hash into the local dev-data/config.php
//
// Directly: printf '%s' 'the password' | php lib/tools/hash_password.php [config.php to update]

declare(strict_types=1);

const MIN_LENGTH = 10;

/** config.php's contents with password_hash replaced. */
function with_password_hash(string $configPhp, string $hash): string
{
    $updated = preg_replace_callback(
        "/('password_hash'\s*=>\s*)'[^']*'/",
        fn(array $m): string => $m[1] . "'" . $hash . "'",
        $configPhp,
        1,
        $count,
    );
    if ($count !== 1) {
        throw new RuntimeException("No 'password_hash' => '...' line found in the config.");
    }
    return $updated;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return; // included by a test: only define the function
}

$password = rtrim((string) stream_get_contents(STDIN), "\r\n");
if (mb_strlen($password) < MIN_LENGTH) {
    fwrite(STDERR, 'Use at least ' . MIN_LENGTH . " characters.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_BCRYPT);

$configPath = $argv[1] ?? null;
if ($configPath === null) {
    echo $hash, "\n";
    exit(0);
}
file_put_contents($configPath, with_password_hash((string) file_get_contents($configPath), $hash));
echo "Updated the password in $configPath\n";
