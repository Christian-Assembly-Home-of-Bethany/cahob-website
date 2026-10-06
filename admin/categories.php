<?php
// Categories: add one, rename one, or delete one (after a confirmation page). The editor's
// "Manage categories" link opens this page; the message list shows the categories as a sidebar.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';

const CATEGORY_NAME_MAX = 60;
const CATEGORY_NOTICES = ['category_added', 'category_saved', 'category_deleted'];

$admin = require_admin();
$pdo = db();

$name = fn(string $field): string => mb_substr(trim(preg_replace('/\s+/u', ' ', mb_scrub(post_text($field), 'UTF-8'))), 0, CATEGORY_NAME_MAX);
$id = fn(mixed $value): int => is_string($value) && ctype_digit($value) ? (int) $value : 0;

$error = null;
$typed = ['name_zh' => '', 'name_en' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post_text('action');
    $category = find_category($pdo, $id($_POST['id'] ?? ''));
    $names = ['name_zh' => $name('name_zh'), 'name_en' => $name('name_en')];
    if (!csrf_ok()) {
        $error = 'stale_form';
    } elseif ($action === 'delete' && $category !== null) {
        delete_category($pdo, (int) $category['id']);
        redirect(admin_url('categories', ['notice' => 'category_deleted']));
    } elseif (in_array($action, ['add', 'rename'], true) && ($names['name_zh'] === '' || $names['name_en'] === '')) {
        $error = 'error_category_names';
        $typed = $action === 'add' ? $names : $typed;
    } elseif ($action === 'add') {
        add_category($pdo, $names['name_en'], $names['name_zh']);
        redirect(admin_url('categories', ['notice' => 'category_added']));
    } elseif ($action === 'rename' && $category !== null) {
        rename_category($pdo, (int) $category['id'], $names['name_en'], $names['name_zh']);
        redirect(admin_url('categories', ['notice' => 'category_saved']));
    }
}

$categories = all_categories($pdo);
$usage = category_usage($pdo);
$notice = in_array($_GET['notice'] ?? '', CATEGORY_NOTICES, true) ? $_GET['notice'] : null;
$deleting = isset($_GET['delete']) ? find_category($pdo, $id($_GET['delete'])) : null;

admin_header(at('categories_title'), $admin);
?>
      <div class="admin-heading">
        <div>
          <a href="/admin/" class="admin-text-link">&larr; <?= at('back_to_list') ?></a>
          <h1><?= at('categories_title') ?></h1>
        </div>
      </div>
<?php
if ($notice !== null) {
    admin_alert('notice', at($notice));
}
if ($error !== null) {
    admin_alert('error', at($error));
}
?>
<?php if ($deleting !== null): ?>
      <section class="admin-card confirm-card">
        <h2><?= e(at('delete_category_question', category_name($deleting, admin_lang()))) ?></h2>
        <p class="admin-intro"><?= e(at('delete_category_warning', $usage[$deleting['id']] ?? 0)) ?></p>
        <form method="post" action="<?= admin_url('categories') ?>" class="confirm-actions">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" value="<?= (int) $deleting['id'] ?>" />
          <button type="submit" class="btn admin-btn btn-danger"><?= at('delete_confirm') ?></button>
          <a href="<?= admin_url('categories') ?>" class="btn btn-ghost admin-btn"><?= at('cancel') ?></a>
        </form>
      </section>
<?php endif; ?>
      <p class="admin-intro"><?= e(at('categories_intro')) ?></p>
<?php if (!$categories): ?>
      <p class="admin-intro"><?= at('no_categories') ?></p>
<?php endif; ?>
      <div class="category-rows">
<?php foreach ($categories as $category):
    $cid = (int) $category['id'];
?>
        <form method="post" action="<?= admin_url('categories') ?>" class="admin-card category-row">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="rename" />
          <input type="hidden" name="id" value="<?= $cid ?>" />
          <label><span><?= at('col_name_zh') ?></span><input type="text" name="name_zh" value="<?= e($category['name_zh']) ?>" maxlength="<?= CATEGORY_NAME_MAX ?>" lang="zh-Hant" required /></label>
          <label><span><?= at('col_name_en') ?></span><input type="text" name="name_en" value="<?= e($category['name_en']) ?>" maxlength="<?= CATEGORY_NAME_MAX ?>" lang="en" required /></label>
          <span class="category-row-count"><?= at('col_count', (int) ($usage[$cid] ?? 0)) ?></span>
          <span class="category-row-actions">
            <button type="submit" class="btn btn-ghost admin-btn"><?= at('save') ?></button>
            <a href="<?= e(admin_url('categories', ['delete' => $cid])) ?>" class="danger-link"><?= at('delete') ?></a>
          </span>
        </form>
<?php endforeach; ?>
      </div>

      <form method="post" action="<?= admin_url('categories') ?>" class="admin-card category-row category-row--new">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add" />
        <h2><?= at('add_category') ?></h2>
        <label><span><?= at('col_name_zh') ?></span><input type="text" name="name_zh" value="<?= e($typed['name_zh']) ?>" maxlength="<?= CATEGORY_NAME_MAX ?>" lang="zh-Hant" required /></label>
        <label><span><?= at('col_name_en') ?></span><input type="text" name="name_en" value="<?= e($typed['name_en']) ?>" maxlength="<?= CATEGORY_NAME_MAX ?>" lang="en" required /></label>
        <span class="category-row-actions">
          <button type="submit" class="btn btn-primary admin-btn">＋ <?= at('add_category') ?></button>
        </span>
      </form>
<?php
admin_footer();
