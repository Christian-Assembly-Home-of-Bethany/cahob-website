<?php
// Admin login. 5 failed attempts from one address in 15 minutes locks that address out.
// Failed logins, lockouts, and successful logins are written to ~/cahob-data/logs/security.log.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';

send_admin_headers();
// Where to go after logging in: back to the admin page that sent us here, or the dashboard.
$next = safe_admin_path($_POST['next'] ?? $_GET['next'] ?? '');
if (current_admin() !== null) {
    redirect($next);
}

$alert = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(post_text('username'));
    $ip = client_ip();
    $now = time();
    $lockedFor = lockout_remaining(db(), $ip, $now);
    $admin = config()['admin'] ?? [];

    if (!csrf_ok()) {
        $alert = ['error', at('stale_form')];
    } elseif ($lockedFor === 0 && credentials_valid($admin, $username, post_text('password'))) {
        clear_failed_logins(db(), $ip);
        log_in($username);
        security_log('LOGIN_OK');
        redirect($next);
    } else {
        if ($lockedFor > 0) {
            security_log('BLOCKED', 'tried to log in while locked out');
        } else {
            record_failed_login(db(), $ip, $now);
            security_log('LOGIN_FAILED', failure_reason($admin, $username));
            $lockedFor = lockout_remaining(db(), $ip, $now);
            if ($lockedFor > 0) {
                security_log('LOCKED_OUT', LOCKOUT_FAILURES . ' failed logins in 15 minutes; locked for ' . (int) ceil($lockedFor / 60) . ' minutes');
            }
        }
        $alert = ['error', $lockedFor > 0 ? at('locked_out', (int) ceil($lockedFor / 60)) : at('wrong_login')];
        if ($lockedFor === 0 && empty($admin['password_hash']) && !empty(config()['debug'])) {
            $alert = ['error', at('no_password')];
        }
    }
} elseif (isset($_GET['expired'])) {
    $alert = ['notice', at('expired')];
} elseif (isset($_GET['loggedout'])) {
    $alert = ['notice', at('logged_out')];
}

admin_header(at('login_title'));
?>
      <section class="admin-card login-card">
        <h1><?= at('login_title') ?></h1>
        <p class="admin-intro"><?= at('login_intro') ?></p>
<?php if ($alert !== null) {
    admin_alert(...$alert);
} ?>
        <form method="post" action="/admin/login.php" class="admin-form">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e($next) ?>" />
          <label for="username"><?= at('username') ?></label>
          <input id="username" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= e($username) ?>"<?= $username === '' ? ' autofocus' : '' ?> />
          <label for="password"><?= at('password') ?></label>
          <div class="password-field">
            <input id="password" name="password" type="password" autocomplete="current-password" required<?= $username !== '' ? ' autofocus' : '' ?> />
            <button type="button" class="password-toggle" aria-controls="password" aria-pressed="false" aria-label="<?= at('show_password') ?>" title="<?= at('show_password') ?>" data-show-label="<?= at('show_password') ?>" data-hide-label="<?= at('hide_password') ?>" hidden>
              <svg class="icon-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z" /><circle cx="12" cy="12" r="3" /></svg>
              <svg class="icon-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94" /><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19" /><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" /><line x1="1" y1="1" x2="23" y2="23" /></svg>
            </button>
          </div>
          <button type="submit" class="btn btn-primary"><?= at('log_in') ?></button>
        </form>
        <a href="<?= admin_lang() === 'zh' ? '/messages-zh.php' : '/messages.php' ?>" class="admin-back"><span aria-hidden="true">&larr;</span> <?= at('back_to_messages') ?></a>
      </section>
<?php
admin_footer();
