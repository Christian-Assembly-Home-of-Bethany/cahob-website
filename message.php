<?php
// One message: message.php?slug=...&lang=en|zh. Shows the requested language first, then the
// other language below it. If the message wasn't written in the requested language, the
// other one is shown alone.

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/render.php';

$lang = ($_GET['lang'] ?? '') === 'zh' ? 'zh' : 'en';
$slug = is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '';

$message = $slug === '' ? null : find_published(db(), $slug);
if ($message === null) {
    not_found_page($lang);
    return;
}

$section = message_section($message, $lang);
$heading = display_title($section, $message, $lang);
$contentLang = t($section['lang'], 'html_lang');

// The other language, shown under the first one when it was written too.
$secondLang = other_lang($section['lang']);
$second = !$section['fallback'] && !html_is_blank($message['body_' . $secondLang])
    ? message_section($message, $secondLang)
    : null;

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

    <section class="section" aria-label="<?= e($heading) ?>">
      <div class="container">
<?php if ($section['fallback']): ?>
        <p class="content-narrow message-notice"><?= t($lang, 'only_other') ?></p>
<?php endif; ?>
<?php if ($second !== null): ?>
        <p class="content-narrow message-jump"><a href="#<?= $secondLang ?>" lang="<?= t($secondLang, 'html_lang') ?>"><?= t($lang, 'other_version') ?> <span aria-hidden="true">&darr;</span></a></p>
<?php endif; ?>
        <article class="content-narrow message-body" lang="<?= $contentLang ?>">
<?= $section['body'] ?>

        </article>
<?php if ($second !== null): ?>

        <div class="content-narrow message-second" id="<?= $secondLang ?>" lang="<?= t($secondLang, 'html_lang') ?>">
          <p class="message-meta"><?= t($lang, 'other_version') ?></p>
          <h2><?= e(display_title($second, $message, $secondLang)) ?></h2>
        </div>
        <article class="content-narrow message-body" lang="<?= t($secondLang, 'html_lang') ?>">
<?= $second['body'] ?>

        </article>
<?php endif; ?>

        <nav class="content-narrow message-nav" aria-label="<?= t($lang, 'messages') ?>">
          <a href="<?= list_url($lang) ?>" class="explore-link"><span aria-hidden="true">&larr;</span> <?= t($lang, 'all') ?></a>
        </nav>
      </div>
    </section>
<?php
page_footer($lang);
