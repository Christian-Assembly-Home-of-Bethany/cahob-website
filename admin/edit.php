<?php
// The message editor: /admin/edit.php for a new message, /admin/edit.php?id=N to edit one.
// Chinese and English sit side by side (stacked on a phone), each with an optional title and a
// Quill editor. The form handling itself is in lib/editor.php. Below the form, the version
// history loads an earlier saved version into the form (?id=N&revision=R); saving it restores it.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/editor.php';

// Quill is loaded from a CDN, pinned to one version and checked against these hashes, so a
// changed file is refused by the browser.
const QUILL_JS = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js';
const QUILL_JS_SRI = 'sha384-utBUCeG4SYaCm4m7GQZYr8Hy8Fpy3V4KGjBZaf4WTKOcwhCYpt/0PfeEe3HNlwx8';
const QUILL_CSS = 'https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css';
const QUILL_CSS_SRI = 'sha384-ecIckRi4QlKYya/FQUbBUjS4qp65jF/J87Guw5uzTbO1C1Jfa/6kYmd6dXUF6D7i';
const EDITOR_NOTICES = ['notice_saved', 'notice_published', 'notice_updated', 'notice_unpublished'];

$admin = require_admin();

$id = ctype_digit((string) ($_GET['id'] ?? '')) ? (int) $_GET['id'] : null;
$existing = $id !== null ? find_message(db(), $id) : null;
if ($id !== null && $existing === null) {
    http_response_code(404);
    admin_header(at('edit_title'), $admin);
    admin_alert('error', at('not_found_admin'));
    echo '      <a href="/admin/" class="admin-text-link">&larr; ' . at('back_to_list') . "</a>\n";
    admin_footer();
    return;
}

$errors = [];
$submitted = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $published = ($existing['status'] ?? '') === 'published';
    $allowed = $published ? ['update', 'unpublish'] : ['draft', 'publish'];
    $action = in_array(post_text('action'), $allowed, true) ? post_text('action') : $allowed[0];

    if (!csrf_ok()) {
        $errors = ['stale_form'];
        $submitted = message_from_form($_POST);
    } else {
        $result = apply_editor_action(db(), $existing, $_POST, $action);
        if (isset($result['id'])) {
            redirect('/admin/edit.php?id=' . $result['id'] . '&notice=' . $result['notice'] . ($existing === null ? '&created=1' : ''));
        }
        $errors = $result['errors'];
        $submitted = $result['message'];
    }
}

// An earlier version to show in the form instead of the current one. Nothing changes until it's saved.
$revision = null;
if ($existing !== null && $submitted === null && ctype_digit((string) ($_GET['revision'] ?? ''))) {
    $revision = find_revision(db(), (int) $existing['id'], (int) $_GET['revision']);
}

$message = $existing ?? new_message();
$shown = $revision ?? $message;
$values = [
    'title_zh' => $submitted['title_zh'] ?? $shown['title_zh'],
    'title_en' => $submitted['title_en'] ?? $shown['title_en'],
    'body_zh' => $submitted['body_zh'] ?? $shown['body_zh'],
    'body_en' => $submitted['body_en'] ?? $shown['body_en'],
    'published_at' => $submitted['published_at_input'] ?? utc_to_local_input($shown['published_at'] ?? now_utc()),
];
$isPublished = $message['status'] === 'published';
$saveButton = at($isPublished ? 'btn_update' : 'btn_save_draft');
$notice = in_array($_GET['notice'] ?? '', EDITOR_NOTICES, true) ? $_GET['notice'] : null;
$formAction = '/admin/edit.php' . ($existing !== null ? '?id=' . (int) $existing['id'] : '');
$publicLang = admin_lang() === 'zh' ? 'zh' : 'en';

$labels = ['locale' => admin_lang() === 'zh' ? 'zh-TW' : 'en-US'];
foreach (array_keys(ADMIN_TEXT['en']) as $key) {
    if (str_starts_with($key, 'tb_') || $key === 'restore_found') {
        $labels[$key] = at($key);
    }
}

admin_header(
    at($existing !== null ? 'edit_title' : 'new_title'),
    $admin,
    '    <link rel="stylesheet" href="' . QUILL_CSS . '" integrity="' . QUILL_CSS_SRI . '" crossorigin="anonymous" />',
);
?>
      <div class="admin-heading">
        <div>
          <a href="/admin/" class="admin-text-link">&larr; <?= at('back_to_list') ?></a>
          <h1><?= at($existing !== null ? 'edit_title' : 'new_title') ?></h1>
        </div>
<?php if ($existing !== null): ?>
        <p class="editor-status">
          <span class="status-badge status-badge--<?= $message['status'] ?>"><?= at($isPublished ? 'status_line_published' : 'status_line_draft') ?></span>
<?php if ($isPublished): ?>
          <a href="<?= e(message_url($message, $publicLang)) ?>" target="_blank" rel="noopener" class="admin-text-link"><?= at('view_public') ?> &nearr;<span class="visually-hidden"> <?= at('new_tab') ?></span></a>
<?php endif; ?>
        </p>
<?php endif; ?>
      </div>
<?php
if ($notice !== null) {
    admin_alert('notice', at($notice));
}
foreach ($errors as $error) {
    admin_alert('error', at($error));
}
?>
<?php if ($revision !== null): ?>
      <p class="admin-alert admin-alert--notice revision-banner" role="status">
        <span><?= e(at('revision_loaded', format_date_time($revision['saved_at'], admin_lang()), $saveButton)) ?></span>
        <a href="/admin/edit.php?id=<?= (int) $existing['id'] ?>"><?= at('revision_cancel') ?></a>
      </p>
<?php endif; ?>
      <p class="admin-alert admin-alert--error" id="session-lost" role="alert" hidden><?= at('session_lost') ?> <a href="/admin/login.php" target="_blank" rel="noopener"><?= at('log_in_again') ?></a></p>
      <div class="admin-alert admin-alert--notice restore-banner" id="restore-banner" role="status" hidden>
        <span id="restore-text"></span>
        <button type="button" class="admin-small-button" id="restore-button"><?= at('restore') ?></button>
        <button type="button" class="admin-small-button admin-small-button--plain" id="discard-button"><?= at('discard') ?></button>
      </div>
      <noscript><p class="admin-alert admin-alert--error"><?= at('needs_js') ?></p></noscript>

      <form method="post" action="<?= e($formAction) ?>" class="editor-form" id="editor-form"
            data-key="<?= $existing !== null ? (int) $existing['id'] : 'new' ?>"
            data-saved="<?= $notice !== null ? '1' : '0' ?>"
            data-created="<?= isset($_GET['created']) ? '1' : '0' ?>">
        <?= csrf_field() ?>
        <div class="editor-meta">
          <label for="published_at"><?= at('publish_date') ?> <span class="field-hint">(<?= at('publish_date_hint') ?>)</span></label>
          <input type="datetime-local" id="published_at" name="published_at" value="<?= e($values['published_at']) ?>" required />
        </div>

        <div class="editor-columns">
<?php foreach (['zh', 'en'] as $code):
    $contentLang = t($code, 'html_lang');
?>
          <section class="editor-lang" aria-labelledby="heading-<?= $code ?>">
            <h2 id="heading-<?= $code ?>"><?= at('section_' . $code) ?></h2>
            <label for="title_<?= $code ?>"><?= at('title_label') ?></label>
            <input type="text" id="title_<?= $code ?>" name="title_<?= $code ?>" value="<?= e($values['title_' . $code]) ?>" maxlength="<?= TITLE_MAX ?>" lang="<?= $contentLang ?>" />
            <span class="editor-label" id="label-body_<?= $code ?>"><?= at('body_label') ?></span>
            <div class="message-editor" data-input="body_<?= $code ?>" data-label="label-body_<?= $code ?>" lang="<?= $contentLang ?>"><?= $values['body_' . $code] ?></div>
            <input type="hidden" id="body_<?= $code ?>" name="body_<?= $code ?>" value="<?= e($values['body_' . $code]) ?>" />
          </section>
<?php endforeach; ?>
        </div>

        <div class="editor-actions">
<?php if ($isPublished): ?>
          <button type="submit" name="action" value="update" class="btn btn-primary admin-btn"><?= at('btn_update') ?></button>
          <button type="submit" name="action" value="preview" formaction="/admin/preview.php" formtarget="_blank" class="btn btn-ghost admin-btn"><?= at('btn_preview') ?></button>
          <button type="submit" name="action" value="unpublish" class="btn btn-ghost admin-btn"><?= at('btn_unpublish') ?></button>
<?php else: ?>
          <button type="submit" name="action" value="draft" class="btn btn-ghost admin-btn"><?= at('btn_save_draft') ?></button>
          <button type="submit" name="action" value="preview" formaction="/admin/preview.php" formtarget="_blank" class="btn btn-ghost admin-btn"><?= at('btn_preview') ?></button>
          <button type="submit" name="action" value="publish" class="btn btn-primary admin-btn"><?= at('btn_publish') ?></button>
<?php endif; ?>
        </div>
      </form>
<?php if ($existing !== null):
    $revisions = message_revisions(db(), (int) $existing['id']);
?>

      <section class="admin-card history-card" aria-labelledby="history-title">
        <h2 id="history-title"><?= at('history_title') ?></h2>
<?php if (count($revisions) < 2): ?>
        <p class="admin-intro"><?= at('history_only_one') ?></p>
<?php else: ?>
        <p class="admin-intro"><?= e(at('history_intro', $saveButton)) ?></p>
        <form method="get" action="/admin/edit.php" class="history-form">
          <input type="hidden" name="id" value="<?= (int) $existing['id'] ?>" />
          <label for="revision"><?= at('history_label') ?></label>
          <select id="revision" name="revision">
<?php foreach ($revisions as $i => $version): ?>
            <option value="<?= (int) $version['id'] ?>"<?= $i === 0 ? ' disabled' : '' ?><?= $version['id'] === ($revision['id'] ?? null) ? ' selected' : '' ?>><?= format_date_time($version['saved_at'], admin_lang()) ?> · <?= at($version['status'] === 'published' ? 'status_published' : 'status_draft') ?><?= $i === 0 ? at('history_current') : '' ?></option>
<?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-ghost admin-btn"><?= at('history_load') ?></button>
        </form>
<?php endif; ?>
      </section>
<?php endif; ?>
<?php
admin_footer(
    '    <script src="' . QUILL_JS . '" integrity="' . QUILL_JS_SRI . '" crossorigin="anonymous"></script>' . "\n"
    . '    <script>window.CAHOB_EDITOR = ' . json_encode($labels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";</script>\n"
    . '    <script src="/admin/editor.js?v=' . STYLES_VERSION . '"></script>'
);
