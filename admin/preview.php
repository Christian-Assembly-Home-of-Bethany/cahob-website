<?php
// Preview: the editor's Preview button posts the form here (in a new tab). It shows the text
// being edited with the public page's look, without saving anything.

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/render.php';
require_once __DIR__ . '/../lib/editor.php';

require_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_ok()) {
    redirect('/admin/');
}

$form = message_from_form($_POST);
$preview = $form + ['slug' => '', 'published_at' => $form['published_at'] ?? now_utc()];
$lang = admin_lang() === 'zh' ? 'zh' : 'en';
// The admin language's version first, then the other one, skipping any without text.
$sections = array_values(array_filter(
    [$lang, other_lang($lang)],
    fn(string $code): bool => !html_is_blank($preview['body_' . $code]),
));

$first = $sections[0] ?? $lang;
$firstTitle = trim($preview['title_' . $first]) !== '' ? trim($preview['title_' . $first]) : format_date($preview['published_at'], $lang);

page_header($lang, at('preview_title') . t($lang, 'title_sep') . 'CAHOB', '', '#', 'noindex, nofollow');
page_hero(t($lang, 'messages'), $firstTitle, trim($preview['title_' . $first]) !== '' ? format_date($preview['published_at'], $lang) : '', 'hero--message');
?>

    <section class="section">
      <div class="container">
        <p class="content-narrow message-notice"><?= at('preview_notice') ?></p>
<?php if (!$sections): ?>
        <p class="content-narrow message-empty"><?= at('preview_empty') ?></p>
<?php endif; ?>
<?php foreach ($sections as $i => $code):
    $title = trim($preview['title_' . $code]);
?>
<?php if ($i > 0): ?>
        <div class="content-narrow message-second" lang="<?= t($code, 'html_lang') ?>">
          <p class="message-meta"><?= at('section_' . $code) ?></p>
          <h2><?= e($title !== '' ? $title : format_date($preview['published_at'], $code)) ?></h2>
        </div>
<?php endif; ?>
        <article class="content-narrow message-body" lang="<?= t($code, 'html_lang') ?>">
<?= $preview['body_' . $code] ?>

        </article>
<?php endforeach; ?>
      </div>
    </section>
<?php
page_footer($lang);
