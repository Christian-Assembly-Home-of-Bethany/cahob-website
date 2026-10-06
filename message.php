<?php
// One message, at /messages/<slug> or /messages-zh/<slug> (those folders' index.php set
// $lang and include this file). Shows the page's language, or the other one if this message
// wasn't written in it. When both are written, a link at the bottom goes to the other
// language's page. The old /message.php?slug=...&lang=zh addresses redirect to the new ones.

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/render.php';

$lang = ($lang ?? $_GET['lang'] ?? '') === 'zh' ? 'zh' : 'en';
$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';
redirect_legacy_url();

$message = $slug === '' ? null : find_published(db(), $slug);
if ($message === null) {
    not_found_page($lang);
    return;
}

$section = message_section($message, $lang);
$heading = display_title($section, $message, $lang);
$contentLang = t($section['lang'], 'html_lang');

page_header(
    $lang,
    $heading . t($lang, 'title_sep') . 'CAHOB',
    excerpt($section['body'], 300),
    message_url($message, other_lang($lang)),
);
page_hero(
    t($lang, 'messages'),
    $heading,
    $section['title'] !== '' ? format_date($message['published_at'], $lang) : '',
    'hero--message',
);
?>
<?php if (has_both_languages($message)): ?>

    <div class="container message-switch">
      <nav class="lang-toggle" aria-label="<?= t($lang, 'version_label') ?>">
<?php foreach (['zh', 'en'] as $code): ?>
        <a href="<?= e(message_url($message, $code)) ?>" class="btn btn-ghost" lang="<?= t($code, 'html_lang') ?>"<?= $code === $lang ? ' aria-current="page"' : '' ?>><?= t($code, 'version_name') ?></a>
<?php endforeach; ?>
      </nav>
    </div>
<?php endif; ?>

    <section class="section" aria-label="<?= e($heading) ?>">
      <div class="container">
<?php if ($section['fallback']): ?>
        <p class="content-narrow message-notice"><?= t($lang, 'only_other') ?></p>
<?php endif; ?>
        <article class="content-narrow message-body" lang="<?= $contentLang ?>">
<?= $section['body'] ?>

        </article>

        <nav class="content-narrow message-nav" aria-label="<?= t($lang, 'messages') ?>">
          <a href="<?= list_url($lang) ?>" class="explore-link"><span aria-hidden="true">&larr;</span> <?= t($lang, 'all') ?></a>
<?php if (has_both_languages($message)): ?>
          <a href="<?= e(message_url($message, other_lang($lang))) ?>" class="explore-link" lang="<?= t($lang, 'other_version_lang') ?>"><?= t($lang, 'other_version') ?> <span aria-hidden="true">&rarr;</span></a>
<?php endif; ?>
        </nav>
      </div>
    </section>
<?php
page_footer($lang);
