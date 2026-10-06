<?php
// Deleting a message: the dashboard's Delete link opens this confirmation page, and only the
// form on it (a post with a token) actually deletes.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/editor.php';

$admin = require_admin();

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : 0;
$message = find_message(db(), $id);
if ($message === null) {
    redirect('/admin/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_ok()) {
    delete_message(db(), $id);
    redirect('/admin/?deleted=1');
}

$label = dashboard_label($message);
$date = $message['published_at'] ?? $message['created_at'];

admin_header(at('delete_title'), $admin);
?>
      <section class="admin-card confirm-card">
        <h1><?= at('delete_question') ?></h1>
        <p class="confirm-subject">
          <strong lang="<?= t($label['lang'], 'html_lang') ?>"><?= $label['untitled'] ? at('untitled') . ' · ' : '' ?><?= e($label['text']) ?></strong>
          <span><?= format_date($date, admin_lang()) ?> · <?= at($message['status'] === 'published' ? 'status_published' : 'status_draft') ?></span>
        </p>
        <p class="admin-intro"><?= at('delete_warning') ?></p>
        <form method="post" action="<?= e(admin_url('delete', ['id' => $id])) ?>" class="confirm-actions">
          <?= csrf_field() ?>
          <button type="submit" class="btn admin-btn btn-danger"><?= at('delete_confirm') ?></button>
          <a href="/admin/" class="btn btn-ghost admin-btn"><?= at('cancel') ?></a>
        </form>
      </section>
<?php
admin_footer();
