<?php
// One message, at /messages/<slug> or /messages-zh/<slug> (those folders' index.php set
// $lang and include this file). The folder sets the language of the page around the message
// (menu, header, footer, dates) and the message text. To read the other language, visitors use
// the 中文 / English switch at the top, like on every page. ?version=en|zh (from links shared
// while the page had its own 中文版 / English switch) still picks the text's language. If the
// message wasn't written in that language, the other one is shown with a note. The old
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
    // A link from before the message had a title (e.g. /messages/2026-09-28) goes to its new address.
    $moved = $slug === '' ? null : find_redirect(db(), $slug);
    if ($moved !== null) {
        header('Location: ' . message_url($moved, $lang, $version), true, 301);
        exit;
    }
    not_found_page($lang);
    return;
}

$section = message_section($message, $version);
$heading = display_title($section, $message, $lang);
$contentLang = t($section['lang'], 'html_lang');
// An untitled message's heading is its date, written in the page's language.
$headingLang = $section['title'] !== '' ? $section['lang'] : $lang;
$category = $message['category_id'] !== null ? find_category(db(), (int) $message['category_id']) : null;

page_header(
    $lang,
    $heading . t($lang, 'title_sep') . 'CAHOB',
    excerpt($section['body'], 300),
    message_url($message, other_lang($lang)),
);
page_hero(
    $category !== null ? category_name($category, $lang) : t($lang, 'messages'),
    $heading,
    $section['title'] !== '' ? format_date($message['published_at'], $lang) : '',
    'hero--message',
    $headingLang !== $lang ? t($headingLang, 'html_lang') : '',
);
?>

    <div class="container message-switch">
      <?php /* In the language of the text being printed (the other language when this one wasn't written) */ ?>
      <div class="message-actions" lang="<?= $contentLang ?>" hidden>
        <button type="button" class="btn btn-ghost" data-print><?= t($section['lang'], 'print') ?></button>
      </div>
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
<?php if ($category !== null): ?>
          <a href="<?= e(list_url($lang, 1, $category['slug'])) ?>" class="explore-link"><?= e(sprintf(t($lang, 'more_in'), category_name($category, $lang))) ?></a>
<?php endif; ?>
        </nav>
      </div>
    </section>
<?php
page_footer($lang);
