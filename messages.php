<?php
// The message list, newest first, 10 per page, with a sidebar of categories; ?category=<slug>
// shows one category. Visitors reach it at /messages/ and
// /messages-zh/ (those folders' index.php set $lang and include this file). The old
// /messages.php and /messages-zh.php addresses redirect there.

declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/render.php';

const PER_PAGE = 10;

$lang = ($lang ?? 'en') === 'zh' ? 'zh' : 'en';
$page = max(1, (int) ($_GET['page'] ?? 1));
redirect_legacy_url();

// ?category=<slug> shows one category (the sidebar's links).
$categorySlug = is_string($_GET['category'] ?? null) ? $_GET['category'] : null;
$category = $categorySlug !== null ? find_category_by_slug(db(), $categorySlug) : null;
if ($categorySlug !== null && $category === null) {
    not_found_page($lang);
    return;
}
$categoryId = $category !== null ? (int) $category['id'] : null;

$total = count_published(db(), $categoryId);
$pages = page_count($total, PER_PAGE);
if ($page > $pages) {
    not_found_page($lang);
    return;
}
$messages = published_messages(db(), PER_PAGE, ($page - 1) * PER_PAGE, $categoryId);
$categories = categories_with_counts(db());
$allCount = $categoryId === null ? $total : count_published(db());

$title = ($category !== null ? category_name($category, $lang) . t($lang, 'title_sep') : '') . t($lang, 'messages') . t($lang, 'title_sep') . 'CAHOB';
page_header($lang, $title, t($lang, 'list_description'), list_url(other_lang($lang), $page, $categorySlug));
page_hero(t($lang, 'eyebrow'), $category !== null ? category_name($category, $lang) : t($lang, 'messages'), t($lang, 'tagline'));
?>

    <section class="section" aria-label="<?= t($lang, 'messages') ?>">
      <div class="container<?= $categories ? ' messages-layout' : '' ?>">
<?php if ($categories): ?>
        <nav class="category-nav" aria-label="<?= t($lang, 'categories') ?>">
          <h2><?= t($lang, 'categories') ?></h2>
          <ul>
            <li><a href="<?= list_url($lang) ?>"<?= $category === null ? ' aria-current="page"' : '' ?>><?= t($lang, 'all_categories') ?> <span class="category-count"><?= $allCount ?></span></a></li>
<?php foreach ($categories as $item): ?>
            <li><a href="<?= e(list_url($lang, 1, $item['slug'])) ?>"<?= $item['id'] === ($category['id'] ?? null) ? ' aria-current="page"' : '' ?>><?= e(category_name($item, $lang)) ?> <span class="category-count"><?= (int) $item['count'] ?></span></a></li>
<?php endforeach; ?>
          </ul>
        </nav>
<?php endif; ?>
        <div class="messages-main">
<?php if (!$messages): ?>
        <div class="content-narrow message-empty">
          <p><?= t($lang, 'empty') ?></p>
        </div>
<?php else: ?>
        <div class="message-list">
<?php foreach ($messages as $message):
    $section = message_section($message, $lang);
    $url = message_url($message, $lang);
    $contentLang = $section['fallback'] ? ' lang="' . t(other_lang($lang), 'html_lang') . '"' : '';
?>
          <article class="message-item reveal">
<?php if ($section['title'] !== '' || $section['fallback']): ?>
            <p class="message-meta">
<?php if ($section['title'] !== ''): ?>
              <time datetime="<?= iso_date($message['published_at']) ?>"><?= format_date($message['published_at'], $lang) ?></time>
<?php endif; ?>
<?php if ($section['fallback']): ?>
              <span class="message-lang-tag" lang="<?= t($lang, 'other_version_lang') ?>"><?= t($lang, 'in_other') ?></span>
<?php endif; ?>
            </p>
<?php endif; ?>
            <h2 class="message-title"<?= $section['title'] !== '' ? $contentLang : '' ?>>
              <a href="<?= e($url) ?>"><?= e(display_title($section, $message, $lang)) ?></a>
            </h2>
            <p class="message-excerpt"<?= $contentLang ?>><?= e(excerpt($section['body'])) ?></p>
            <a href="<?= e($url) ?>" class="explore-link"><?= t($lang, 'read') ?> <span aria-hidden="true">&rarr;</span></a>
          </article>
<?php endforeach; ?>
        </div>
<?php if ($pages > 1): ?>

        <nav class="pagination" aria-label="<?= t($lang, 'pages_label') ?>">
<?php if ($page > 1): ?>
          <a href="<?= e(list_url($lang, $page - 1, $categorySlug)) ?>" rel="prev"><span aria-hidden="true">&larr;</span> <?= t($lang, 'newer') ?></a>
<?php else: ?>
          <span></span>
<?php endif; ?>
          <span class="pagination-status"><?= sprintf(t($lang, 'page_of'), $page, $pages) ?></span>
<?php if ($page < $pages): ?>
          <a href="<?= e(list_url($lang, $page + 1, $categorySlug)) ?>" rel="next"><?= t($lang, 'older') ?> <span aria-hidden="true">&rarr;</span></a>
<?php else: ?>
          <span></span>
<?php endif; ?>
        </nav>
<?php endif; ?>
<?php endif; ?>
        </div>
      </div>
    </section>
<?php
page_footer($lang);
