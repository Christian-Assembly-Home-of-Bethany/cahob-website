<?php
// One message, at /messages/<slug> or /messages-zh/<slug> (those folders' index.php set
// $lang and include this file). The folder sets the language of the page around the message
// (menu, header, footer, dates). ?version=en|zh is the language of the message text, set by
// the 中文版 / English version switch, and defaults to the page's language. If the message
// wasn't written in that language, the other one is shown with a note. The old
// /message.php?slug=...&lang=zh addresses redirect to the new ones.

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/render.php';

$lang = ($lang ?? $_GET['lang'] ?? '') === 'zh' ? 'zh' : 'en';
$version = in_array($_GET['version'] ?? null, ['en', 'zh'], true) ? $_GET['version'] : $lang;
$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';
redirect_legacy_url();

$message = $slug === '' ? null : find_published(db(), $slug);
if ($message === null) {
    not_found_page($lang);
    return;
}

$section = message_section($message, $version);
$heading = display_title($section, $message, $lang);
$contentLang = t($section['lang'], 'html_lang');
// An untitled message's heading is its date, written in the page's language.
$headingLang = $section['title'] !== '' ? $section['lang'] : $lang;
$otherVersion = other_lang($section['lang']);
// The browser names a saved PDF after the page title, so Download PDF sets this title first.
$pdfName = 'CAHOB ' . iso_date($message['published_at']) . ($section['title'] !== '' ? ' ' . $section['title'] : '');

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
    $headingLang !== $lang ? t($headingLang, 'html_lang') : '',
);
?>

    <div class="container message-switch">
      <?php /* In the language of the message being read, which the 中文版 / English switch can change */ ?>
      <div class="message-actions" role="group" aria-label="<?= t($section['lang'], 'actions_label') ?>" lang="<?= $contentLang ?>" hidden>
        <button type="button" class="btn btn-ghost" data-print><?= t($section['lang'], 'print') ?></button>
        <button type="button" class="btn btn-ghost" data-print data-filename="<?= e($pdfName) ?>" title="<?= e(t($section['lang'], 'download_hint')) ?>"><?= t($section['lang'], 'download_pdf') ?></button>
      </div>
<?php if (has_both_languages($message)): ?>
      <nav class="lang-toggle" aria-label="<?= t($lang, 'version_label') ?>">
<?php foreach (['zh', 'en'] as $code): ?>
        <a href="<?= e(message_url($message, $lang, $code)) ?>" class="btn btn-ghost" lang="<?= t($code, 'html_lang') ?>"<?= $code === $section['lang'] ? ' aria-current="page"' : '' ?>><?= t($code, 'version_name') ?></a>
<?php endforeach; ?>
      </nav>
<?php endif; ?>
    </div>

    <section class="section" aria-label="<?= e($heading) ?>">
      <div class="container">
<?php if ($section['fallback']): ?>
        <p class="content-narrow message-notice"><?= t($lang, $section['lang'] === $lang ? 'only_this' : 'only_other') ?></p>
<?php endif; ?>
        <article class="content-narrow message-body" lang="<?= $contentLang ?>">
<?= $section['body'] ?>

        </article>

        <nav class="content-narrow message-nav" aria-label="<?= t($lang, 'messages') ?>">
          <a href="<?= list_url($lang) ?>" class="explore-link"><span aria-hidden="true">&larr;</span> <?= t($lang, 'all') ?></a>
<?php if (has_both_languages($message)): ?>
          <a href="<?= e(message_url($message, $lang, $otherVersion)) ?>" class="explore-link" lang="<?= t($otherVersion, 'html_lang') ?>"><?= t($otherVersion, 'version_name') ?> <span aria-hidden="true">&rarr;</span></a>
<?php endif; ?>
        </nav>
      </div>
    </section>
<?php
page_footer($lang);
