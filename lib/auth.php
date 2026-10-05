<?php
// Admin login: the session, the password check, form tokens (CSRF), and the lockout after
// repeated failed logins. Only admin pages (and draft previews) use sessions, so visitors to
// the public pages never get a cookie.

declare(strict_types=1);

const SESSION_IDLE_LIMIT = 2 * 60 * 60;   // log out after 2 hours without a request
const LOCKOUT_FAILURES = 5;               // this many failed logins from one address...
const LOCKOUT_WINDOW = 15 * 60;           // ...within 15 minutes locks that address out
// A real bcrypt hash of a throwaway string, checked when no password is set up, so every
// login attempt does the same amount of work.
const DUMMY_HASH = '$2y$10$evZkxdv0aS9VNlFi8H0Y9OvD//7quiOQcB5QfC7PpO7Gk75.8ul0C';

// ---------- Pure helpers (no session or request state, so they're easy to test) ----------

function credentials_valid(array $admin, string $username, string $password): bool
{
    $hash = (string) ($admin['password_hash'] ?? '');
    // Always run password_verify, so a wrong username takes as long as a wrong password.
    $passwordOk = password_verify($password, $hash !== '' ? $hash : DUMMY_HASH);
    return $hash !== '' && hash_equals((string) ($admin['username'] ?? ''), $username) && $passwordOk;
}

/** Why a login failed, for the security log only. The page always shows the same message. */
function failure_reason(array $admin, string $username): string
{
    return hash_equals((string) ($admin['username'] ?? ''), $username) ? 'wrong password' : 'unknown username';
}

/**
 * One line of the security log, e.g.
 * 2026-10-04 16:30:12 PDT  LOCKED_OUT  203.0.113.5  5 failed logins in 15 minutes  "Mozilla/5.0 ..."
 * Anything from the request is stripped of line breaks, so a visitor can't forge log lines.
 */
function security_log_line(string $event, string $ip, string $details, int $time, string $userAgent): string
{
    $clean = fn(string $text, int $max): string => mb_substr(trim(preg_replace('/[\s\x00-\x1F\x7F"]+/u', ' ', $text)), 0, $max);
    $when = (new DateTimeImmutable('@' . $time))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d H:i:s T');
    return sprintf("%s  %-12s  %-15s  %s  \"%s\"\n", $when, $event, $clean($ip, 45), $details, $clean($userAgent, 150));
}

/** The newest entries of a security log file, newest first, split into their columns. */
function read_security_log(string $file, int $limit = 10): array
{
    if (!is_file($file)) {
        return [];
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $entries = [];
    foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
        if (preg_match('/^(\S+ \S+ \S+)  (\S+)\s+(\S+)\s+(.*?)\s*"(.*)"$/u', $line, $m)) {
            $entries[] = ['time' => $m[1], 'event' => $m[2], 'ip' => $m[3], 'details' => $m[4], 'agent' => $m[5]];
        }
    }
    return $entries;
}

/** Appends to logs/security.log, keeping one older file (security.log.1) once it passes 1 MB. */
function write_security_log(string $dir, string $line, int $maxBytes = 1_000_000): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $file = $dir . '/security.log';
    if (is_file($file) && filesize($file) > $maxBytes) {
        rename($file, $file . '.1');
    }
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

function session_expired(int $lastSeen, int $now): bool
{
    return $now - $lastSeen > SESSION_IDLE_LIMIT;
}

function csrf_valid(string $expected, mixed $submitted): bool
{
    return $expected !== '' && is_string($submitted) && hash_equals($expected, $submitted);
}

/**
 * Where to send someone back to after switching language: only a path inside /admin/, so
 * the link can't be used to bounce visitors to another site.
 */
function safe_admin_path(mixed $back): string
{
    if (!is_string($back) || !str_starts_with($back, '/admin/') || preg_match('#[\\\\\x00-\x1F]|/\.\.?(/|$)#', $back)) {
        return '/admin/';
    }
    return $back;
}

function utc_seconds(int $time): string
{
    return gmdate('Y-m-d H:i:s', $time);
}

function record_failed_login(PDO $pdo, string $ip, int $now): void
{
    $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, utc_seconds($now)]);
    // Keep the table small: nothing older than a day matters.
    $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([utc_seconds($now - 86400)]);
}

function clear_failed_logins(PDO $pdo, string $ip): void
{
    $pdo->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
}

/** Seconds until this address may try again, or 0 if it isn't locked out. */
function lockout_remaining(PDO $pdo, string $ip, int $now): int
{
    $query = $pdo->prepare('SELECT attempted_at FROM login_attempts WHERE ip = ? AND attempted_at > ? ORDER BY attempted_at');
    $query->execute([$ip, utc_seconds($now - LOCKOUT_WINDOW)]);
    $recent = $query->fetchAll(PDO::FETCH_COLUMN);
    if (count($recent) < LOCKOUT_FAILURES) {
        return 0;
    }
    // Unlocks when enough of the recent failures are older than the window.
    $unlocksAt = strtotime($recent[count($recent) - LOCKOUT_FAILURES] . ' UTC') + LOCKOUT_WINDOW;
    return max(1, $unlocksAt - $now);
}

// ---------- Session and request handling ----------

function start_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Sessions live next to config.php, not in the server's shared temp folder, so the host's
    // cleanup job can't end them before the 2-hour limit and other sites can't read them.
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_LIMIT);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('cahob_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        // The live site is HTTPS-only for admin pages; the local server is plain HTTP.
        'secure' => empty(config()['debug']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Headers for every admin page: never cached, never framed, never indexed. */
function send_admin_headers(): void
{
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: same-origin');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '" />';
}

function csrf_ok(): bool
{
    return csrf_valid($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? null);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

/**
 * Records a login event in ~/cahob-data/logs/security.log (outside the web root). Logging
 * must never stop someone from logging in, so a write failure only goes to PHP's error log.
 */
function security_log(string $event, string $details = ''): void
{
    try {
        $line = security_log_line($event, client_ip(), $details, time(), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        write_security_log(data_dir() . '/logs', $line);
    } catch (Throwable $e) {
        error_log('Could not write the security log: ' . $e->getMessage());
    }
}

function log_in(string $username): void
{
    session_regenerate_id(true);
    $_SESSION = ['admin' => $username, 'last_seen' => time()];
    csrf_token(); // a fresh token for the new session
}

function log_out(): void
{
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 3600,
        'path' => $params['path'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'],
    ]);
    session_destroy();
}

/** The signed-in admin's username, or null if nobody is signed in or the session went idle. */
function current_admin(): ?string
{
    start_admin_session();
    if (empty($_SESSION['admin']) || session_expired((int) ($_SESSION['last_seen'] ?? 0), time())) {
        return null;
    }
    $_SESSION['last_seen'] = time();
    return $_SESSION['admin'];
}

/** Start of every admin page: sends anyone who isn't signed in to the login page. */
function require_admin(): string
{
    send_admin_headers();
    $admin = current_admin();
    if ($admin === null) {
        $query = [];
        if (!empty($_SESSION['admin'])) {
            log_out();
            $query['expired'] = '1';
        }
        // Come back to this page after logging in (e.g. the editor, if the session ran out).
        $here = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($here !== '/admin/' && safe_admin_path($here) === $here) {
            $query['next'] = $here;
        }
        redirect('/admin/login.php' . ($query ? '?' . http_build_query($query) : ''));
    }
    return $admin;
}

/** A text field from the submitted form ('' if it's missing or not text). */
function post_text(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}
