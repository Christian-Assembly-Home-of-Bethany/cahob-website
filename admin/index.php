<?php
// Admin dashboard: every message (drafts included) with links to edit, view, and delete, and
// the latest entries of the security log.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/editor.php';

const SECURITY_ROWS = 10;

$admin = require_admin();
$lang = admin_lang();
$messages = all_messages(db());
$events = read_security_log(data_dir() . '/logs/security.log', SECURITY_ROWS);
$publicLang = $lang === 'zh' ? 'zh' : 'en';

admin_header(at('messages_title'), $admin);
?>
      <div class="admin-heading">
        <h1><?= at('messages_title') ?></h1>
        <div class="admin-heading-actions">
          <a href="<?= list_url($publicLang) ?>" class="admin-text-link" target="_blank" rel="noopener"><?= at('view_messages') ?><span class="visually-hidden"> <?= at('new_tab') ?></span></a>
          <a href="/admin/edit.php" class="btn btn-primary admin-btn">＋ <?= at('new_message') ?></a>
        </div>
      </div>
<?php if (isset($_GET['deleted'])) {
    admin_alert('notice', at('notice_deleted'));
} ?>

<?php if (!$messages): ?>
      <section class="admin-card admin-empty">
        <p><?= at('no_messages') ?></p>
      </section>
<?php else: ?>
      <div class="admin-table-wrap">
        <table class="admin-table message-table">
          <thead>
            <tr>
              <th scope="col"><?= at('col_title') ?></th>
              <th scope="col"><?= at('col_status') ?></th>
              <th scope="col"><?= at('col_date') ?></th>
              <th scope="col"><?= at('col_languages') ?></th>
              <th scope="col"><span class="visually-hidden"><?= at('col_actions') ?></span></th>
            </tr>
          </thead>
          <tbody>
<?php foreach ($messages as $message):
    $label = dashboard_label($message);
    $published = $message['status'] === 'published';
    $date = $message['published_at'] ?? $message['created_at'];
?>
            <tr>
              <td class="message-table-title">
                <a href="/admin/edit.php?id=<?= (int) $message['id'] ?>" lang="<?= t($label['lang'], 'html_lang') ?>"><?php if ($label['untitled']): ?><span class="untitled-tag"><?= at('untitled') ?></span> <span class="untitled-text"><?= e($label['text']) ?></span><?php else: ?><?= e($label['text']) ?><?php endif; ?></a>
              </td>
              <td><span class="status-badge status-badge--<?= $message['status'] ?>"><?= at($published ? 'status_published' : 'status_draft') ?></span></td>
              <td class="message-table-date"><time datetime="<?= iso_date($date) ?>"><?= format_date($date, $lang) ?></time></td>
              <td class="message-table-langs">
<?php foreach (['zh' => '中', 'en' => 'EN'] as $code => $short): ?>
                <span class="lang-chip<?= html_is_blank($message['body_' . $code]) ? ' lang-chip--missing' : '' ?>" title="<?= at('section_' . $code) ?>"><?= $short ?></span>
<?php endforeach; ?>
              </td>
              <td class="message-table-actions">
                <a href="/admin/edit.php?id=<?= (int) $message['id'] ?>"><?= at('edit') ?></a>
<?php if ($published): ?>
                <a href="<?= e(message_url($message, $publicLang)) ?>" target="_blank" rel="noopener"><?= at('view') ?><span class="visually-hidden"> <?= at('new_tab') ?></span></a>
<?php endif; ?>
                <a href="/admin/delete.php?id=<?= (int) $message['id'] ?>" class="danger-link"><?= at('delete') ?></a>
              </td>
            </tr>
<?php endforeach; ?>
          </tbody>
        </table>
      </div>
<?php endif; ?>

      <section class="security-panel" aria-labelledby="security-title">
        <h2 id="security-title"><?= at('security_title') ?></h2>
        <p class="admin-intro"><?= e(at('security_intro', SECURITY_ROWS)) ?></p>
<?php if (!$events): ?>
        <p class="security-empty"><?= at('security_empty') ?></p>
<?php else: ?>
        <div class="admin-table-wrap">
          <table class="admin-table security-table">
            <thead>
              <tr>
                <th scope="col"><?= at('col_time') ?></th>
                <th scope="col"><?= at('col_event') ?></th>
                <th scope="col"><?= at('col_ip') ?></th>
                <th scope="col"><?= at('col_details') ?></th>
              </tr>
            </thead>
            <tbody>
<?php foreach ($events as $event):
    $known = isset(ADMIN_TEXT['en']['event_' . $event['event']]);
?>
              <tr>
                <td class="security-time"><?= e($event['time']) ?></td>
                <td><span class="event-badge event-badge--<?= e(strtolower($event['event'])) ?>"><?= $known ? at('event_' . $event['event']) : e($event['event']) ?></span></td>
                <td class="security-ip"><?= e($event['ip']) ?></td>
                <td title="<?= e($event['agent']) ?>"><?= e(security_details($event['details'])) ?></td>
              </tr>
<?php endforeach; ?>
            </tbody>
          </table>
        </div>
<?php endif; ?>
      </section>
<?php
admin_footer();
