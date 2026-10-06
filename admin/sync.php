<?php
// Sync from Blogger: reads the pastor's Blogger feed, shows what would change, and applies it
// when the Apply button is pressed. The rules are in lib/sync.php.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/sync.php';

$admin = require_admin();
$lang = admin_lang();

$error = null;
$plan = null;
try {
    $plan = sync_plan(db(), blogger_posts(blogger_feed_source()));
} catch (RuntimeException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $plan !== null) {
    if (!csrf_ok()) {
        $error = at('stale_form');
    } else {
        $done = sync_apply(db(), $plan);
        redirect(admin_url('sync', ['new' => $done['new'], 'updated' => $done['updated']]));
    }
}

/**
 * One row: a message's label and date, its language, and links to edit it and to the Blogger
 * post. $change (for an update) shows where the website's text and Blogger's differ.
 */
$row = function (array $message, string $code, ?string $url = null, ?array $change = null): void {
    $label = dashboard_label($message);
    ?>
            <li>
              <a href="<?= e(admin_url('edit', ['id' => (int) $message['id']])) ?>" lang="<?= t($label['lang'], 'html_lang') ?>"><?= e($label['text']) ?></a>
              <span class="sync-meta"><?= format_date($message['published_at'], admin_lang()) ?> · <?= at('section_' . $code) ?><?php if ($url !== null && $url !== ''): ?> · <a href="<?= e($url) ?>" target="_blank" rel="noopener">Blogger</a><?php endif; ?></span>
<?php if ($change !== null): ?>
              <dl class="sync-change" lang="<?= t($code, 'html_lang') ?>">
                <dt><?= at('sync_website') ?></dt><dd><?= e($change['old']) ?></dd>
                <dt>Blogger</dt><dd><?= e($change['new']) ?></dd>
              </dl>
<?php endif; ?>
            </li>
<?php
};
$sections = [
    'update' => 'sync_update', 'add' => 'sync_add', 'kept' => 'sync_kept', 'trash' => 'sync_trash', 'link' => 'sync_link',
];

admin_header(at('sync_title'), $admin);
?>
      <div class="admin-heading">
        <div>
          <a href="/admin/" class="admin-text-link">&larr; <?= at('back_to_list') ?></a>
          <h1><?= at('sync_title') ?></h1>
        </div>
      </div>
      <p class="admin-intro"><?= at('sync_intro') ?></p>
<?php
if (isset($_GET['new'])) {
    admin_alert('notice', at('sync_done', (int) $_GET['new'], (int) ($_GET['updated'] ?? 0)));
}
if ($error !== null) {
    admin_alert('error', at('sync_error', $error));
}
?>
<?php if ($plan !== null): ?>
<?php if (sync_change_count($plan) === 0 && !$plan['removed'] && !$plan['trash']): ?>
      <p class="admin-alert admin-alert--notice" role="status"><?= at('sync_up_to_date') ?></p>
<?php else: ?>
<?php if ($plan['new']): ?>
      <section class="admin-card sync-card">
        <h2><?= at('sync_new', count($plan['new'])) ?></h2>
        <ul class="sync-list">
<?php foreach ($plan['new'] as $item):
    $first = $item['title_' . $lang] !== '' ? $lang : other_lang($lang);
?>
          <li>
            <span lang="<?= t($first, 'html_lang') ?>"><?= e($item['title_' . $first] !== '' ? $item['title_' . $first] : format_date($item['published_at'], $lang)) ?></span>
            <span class="sync-meta"><?= format_date($item['published_at'], $lang) ?> · <?= implode(' + ', array_map(fn(string $code): string => at('section_' . $code), array_keys(array_filter(['zh' => $item['zh'], 'en' => $item['en']])))) ?></span>
          </li>
<?php endforeach; ?>
        </ul>
      </section>
<?php endif; ?>
<?php foreach ($sections as $key => $text):
    if (!$plan[$key]) {
        continue;
    }
?>
      <section class="admin-card sync-card">
        <h2><?= at($text, count($plan[$key])) ?></h2>
<?php if ($key === 'link'): ?>
        <p class="admin-intro"><?= at('sync_link_note') ?></p>
<?php endif; ?>
        <ul class="sync-list">
<?php foreach ($plan[$key] as $item) {
    $change = $key === 'update' ? text_difference($item['message']['body_' . $item['lang']], $item['post']['clean']) : null;
    $row($item['message'], $item['lang'], $item['post']['url'], $change);
} ?>
        </ul>
      </section>
<?php endforeach; ?>
<?php if ($plan['removed']): ?>
      <section class="admin-card sync-card">
        <h2><?= at('sync_removed', count($plan['removed'])) ?></h2>
        <ul class="sync-list">
<?php foreach ($plan['removed'] as $item) {
    $row($item['message'], $item['lang']);
} ?>
        </ul>
      </section>
<?php endif; ?>
<?php if (sync_change_count($plan) > 0): ?>
      <form method="post" action="<?= admin_url('sync') ?>" class="sync-apply">
        <?= csrf_field() ?>
        <button type="submit" class="btn btn-primary admin-btn"><?= at('sync_apply') ?></button>
      </form>
<?php endif; ?>
<?php endif; ?>
<?php if ($plan['unchanged'] > 0): ?>
      <p class="admin-intro"><?= at('sync_unchanged', $plan['unchanged']) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php
admin_footer();
