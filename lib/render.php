<?php
// Shared pieces for the Messages pages: the navbar and footer (copied from the static pages),
// the page hero, and small helpers for showing a message.
//
// The static .html pages repeat the same navbar and footer, so a nav change has to be made in
// all six of them and here.

declare(strict_types=1);

const STYLES_VERSION = 7;
const HERO_IMAGE = '/images/meeting_hall_side.jpg';

/** Escape text for HTML. Message bodies are already sanitized HTML and are printed as-is. */
function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** All interface text, in both languages. Every key must exist in both. */
const UI_TEXT = [
    'en' => [
        'html_lang' => 'en',
        'title_sep' => ' | ',
        'brand_sub' => 'Home of Bethany',
        'nav_label' => 'Primary navigation',
        'nav_toggle' => 'Toggle navigation',
        'switch_label' => '中文',
        'switch_aria' => 'Switch to Traditional Chinese',
        'switch_lang' => 'zh-Hant',
        'home' => 'Home',
        'faith' => 'Our Faith and Vision',
        'who' => 'Who We Are',
        'messages' => 'Messages',
        'footer_label' => 'Footer navigation',
        'footer_name' => 'Christian Assembly &mdash; Home of Bethany',
        'footer_bottom' => '&copy; 2026 CAHOB &mdash; No longer I but Christ',
        'eyebrow' => 'Ministry of the Word',
        'tagline' => 'A Voice in the Wilderness',
        'list_description' => 'Messages from CAHOB: A Voice in the Wilderness.',
        'read' => 'Read message',
        'newer' => 'Newer messages',
        'older' => 'Older messages',
        'page_of' => 'Page %d of %d',
        'pages_label' => 'Message pages',
        'empty' => 'No messages yet. Please check back soon.',
        'all' => 'All messages',
        'other_version' => '中文版',
        'other_version_lang' => 'zh-Hant',
        'only_other' => 'This message is available in Chinese only.',
        'in_other' => '中文',
        'not_found' => 'Message not found',
        'not_found_text' => 'It may have been moved or removed.',
        'see_all' => 'See all messages',
        'sign_in' => 'Sign in',
    ],
    'zh' => [
        'html_lang' => 'zh-Hant',
        'title_sep' => '｜',
        'brand_sub' => '伯大尼之家',
        'nav_label' => '主要導覽',
        'nav_toggle' => '開啟選單',
        'switch_label' => 'English',
        'switch_aria' => '切換至英文版',
        'switch_lang' => 'en',
        'home' => '首頁',
        'faith' => '我們的信仰和異象',
        'who' => '我們是誰',
        'messages' => '信息',
        'footer_label' => '頁尾導覽',
        'footer_name' => '基督徒聚會──伯大尼之家',
        'footer_bottom' => '&copy; 2026 CAHOB──不再是我，乃是基督',
        'eyebrow' => '話語的職事',
        'tagline' => '曠野人聲',
        'list_description' => 'CAHOB 的信息：曠野人聲。',
        'read' => '閱讀信息',
        'newer' => '較新的信息',
        'older' => '較舊的信息',
        'page_of' => '第 %d 頁，共 %d 頁',
        'pages_label' => '信息分頁',
        'empty' => '目前還沒有信息，請稍後再來。',
        'all' => '所有信息',
        'other_version' => 'English version',
        'other_version_lang' => 'en',
        'only_other' => '此信息只有英文版。',
        'in_other' => 'English',
        'not_found' => '找不到這篇信息',
        'not_found_text' => '它可能已被移動或刪除。',
        'see_all' => '查看所有信息',
        'sign_in' => '登入',
    ],
];

function t(string $lang, string $key): string
{
    return UI_TEXT[$lang][$key];
}

function other_lang(string $lang): string
{
    return $lang === 'zh' ? 'en' : 'zh';
}

/** Static page links: index.html / index-zh.html, and so on. */
function static_page(string $name, string $lang): string
{
    return '/' . $name . ($lang === 'zh' ? '-zh' : '') . '.html';
}

function list_url(string $lang, int $page = 1): string
{
    $url = $lang === 'zh' ? '/messages-zh.php' : '/messages.php';
    return $page > 1 ? $url . '?page=' . $page : $url;
}

function message_url(array $message, string $lang): string
{
    $url = '/message.php?slug=' . rawurlencode($message['slug']);
    return $lang === 'zh' ? $url . '&lang=zh' : $url;
}

function page_count(int $total, int $perPage): int
{
    return max(1, (int) ceil($total / $perPage));
}

/** Plain text of an HTML body, with whitespace collapsed. */
function plain_text(string $html): string
{
    // A space at each paragraph-level tag keeps words from separate paragraphs apart. Inline
    // tags like <strong> get none, which would split Chinese text.
    $html = preg_replace('#<(/?(?:p|div|h[1-6]|li|ul|ol|blockquote|br|hr|table|thead|tbody|tr|td|th)\b)#i', ' <$1', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', $text));
}

/** True for bodies with no visible text, like the editor's empty "<p><br></p>". */
function html_is_blank(string $html): bool
{
    return plain_text($html) === '';
}

/** The opening words of a body. Width counts Chinese characters double, so both languages come out a similar length. */
function excerpt(string $html, int $width = 240): string
{
    return mb_strimwidth(plain_text($html), 0, $width, '…', 'UTF-8');
}

/** A UTC timestamp from the database, shown as a Pacific-time date. */
function format_date(string $utc, string $lang): string
{
    $date = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Los_Angeles'));
    return $lang === 'zh'
        ? sprintf('%d年%d月%d日', $date->format('Y'), $date->format('n'), $date->format('j'))
        : $date->format('F j, Y');
}

/** The value for a <time datetime="..."> attribute. */
function iso_date(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
}

function has_both_languages(array $message): bool
{
    return !html_is_blank($message['body_en']) && !html_is_blank($message['body_zh']);
}

/**
 * The part of a message to show for a page language. If that language has no body, the
 * other language is shown instead and 'fallback' is true, so no message ever looks blank.
 */
function message_section(array $message, string $lang): array
{
    $use = $lang;
    if (html_is_blank($message['body_' . $lang]) && !html_is_blank($message['body_' . other_lang($lang)])) {
        $use = other_lang($lang);
    }
    return [
        'lang' => $use,
        'title' => trim($message['title_' . $use]),
        'body' => $message['body_' . $use],
        'fallback' => $use !== $lang,
    ];
}

/** A message's title, or its date when it has none (titles are optional). */
function display_title(array $section, array $message, string $lang): string
{
    return $section['title'] !== '' ? $section['title'] : format_date($message['published_at'], $lang);
}

function page_header(string $lang, string $title, string $description, string $switchUrl, ?string $robots = null): void
{
    $nav = [
        ['href' => static_page('index', $lang), 'label' => t($lang, 'home'), 'active' => false],
        ['href' => static_page('faith-and-vision', $lang), 'label' => t($lang, 'faith'), 'active' => false],
        ['href' => static_page('who-we-are', $lang), 'label' => t($lang, 'who'), 'active' => false],
        ['href' => list_url($lang), 'label' => t($lang, 'messages'), 'active' => true],
    ];
    ?>
<!DOCTYPE html>
<html lang="<?= t($lang, 'html_lang') ?>">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($description) ?>" />
<?php if ($robots !== null): ?>
    <meta name="robots" content="<?= e($robots) ?>" />
<?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,400;0,500;0,600;0,700;1,400;1,600&amp;family=Nunito+Sans:ital,opsz,wght@0,6..12,300;0,6..12,400;0,6..12,600;0,6..12,700;1,6..12,400&amp;family=Noto+Serif+TC:wght@500;600;700&amp;family=Noto+Sans+TC:wght@300;400;500;700&amp;display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="/images/favicon.svg" />
    <link rel="icon" type="image/png" sizes="64x64" href="/images/favicon.png" />
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png" />
    <link rel="stylesheet" href="/styles.css?v=<?= STYLES_VERSION ?>" />
    <script>document.documentElement.classList.add("js");</script>
  </head>
  <body class="messages-page">
    <nav class="navbar" aria-label="<?= t($lang, 'nav_label') ?>">
      <div class="container">
        <div class="nav-brand-group">
          <a href="<?= static_page('index', $lang) ?>" class="nav-brand">
            <strong>CAHOB</strong>
            <span><?= t($lang, 'brand_sub') ?></span>
          </a>
          <a href="<?= e($switchUrl) ?>" class="lang-switch" lang="<?= t($lang, 'switch_lang') ?>" aria-label="<?= t($lang, 'switch_aria') ?>"><?= t($lang, 'switch_label') ?></a>
        </div>
        <button class="nav-toggle" aria-label="<?= t($lang, 'nav_toggle') ?>" aria-expanded="false">
          <span></span><span></span><span></span>
        </button>
        <div class="nav-links">
<?php foreach ($nav as $link): ?>
          <a href="<?= $link['href'] ?>"<?= $link['active'] ? ' class="active"' : '' ?>><?= $link['label'] ?></a>
<?php endforeach; ?>
        </div>
      </div>
    </nav>
<?php
}

function page_hero(string $eyebrow, string $title, string $tagline, string $modifier = ''): void
{
    ?>

    <header class="hero hero--page<?= $modifier !== '' ? ' ' . $modifier : '' ?>" style="background-image: url('<?= HERO_IMAGE ?>');">
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <p class="hero-eyebrow fade-up"><?= e($eyebrow) ?></p>
        <h1 class="hero-title fade-up"><?= e($title) ?></h1>
<?php if ($tagline !== ''): ?>
        <p class="hero-tagline fade-up"><?= e($tagline) ?></p>
<?php endif; ?>
      </div>
    </header>
<?php
}

function page_footer(string $lang): void
{
    ?>

    <footer class="site-footer">
      <div class="container">
        <div class="footer-top">
          <div class="footer-brand">
            <strong>CAHOB</strong>
            <span><?= t($lang, 'footer_name') ?></span>
            <address>
              418 South Pine Street<br />
              San Gabriel, CA 91776
            </address>
          </div>
          <nav class="footer-links" aria-label="<?= t($lang, 'footer_label') ?>">
            <a href="<?= static_page('index', $lang) ?>"><?= t($lang, 'home') ?></a>
            <a href="<?= static_page('faith-and-vision', $lang) ?>"><?= t($lang, 'faith') ?></a>
            <a href="<?= static_page('who-we-are', $lang) ?>"><?= t($lang, 'who') ?></a>
            <a href="<?= list_url($lang) ?>"><?= t($lang, 'messages') ?></a>
          </nav>
        </div>
        <div class="footer-bottom"><?= t($lang, 'footer_bottom') ?></div>
      </div>
    </footer>

    <script src="/script.js"></script>
  </body>
</html>
<?php
}

/** A 404 page inside the normal site chrome. */
function not_found_page(string $lang): void
{
    http_response_code(404);
    page_header($lang, t($lang, 'not_found') . t($lang, 'title_sep') . 'CAHOB', t($lang, 'not_found_text'), list_url(other_lang($lang)), 'noindex');
    page_hero(t($lang, 'messages'), t($lang, 'not_found'), '', 'hero--message');
    ?>

    <section class="section">
      <div class="container">
        <div class="content-narrow message-empty">
          <p><?= t($lang, 'not_found_text') ?></p>
          <a href="<?= list_url($lang) ?>" class="explore-link"><?= t($lang, 'see_all') ?> <span aria-hidden="true">&rarr;</span></a>
        </div>
      </div>
    </section>
<?php
    page_footer($lang);
}

// ---------- Admin pages ----------
// Admin pages are in Traditional Chinese, with a button in the header that switches them to
// English (remembered in a cookie). They share the site's fonts and colors but use their own
// simple header instead of the public navbar.

/** All admin interface text. Every key must exist in both languages. */
const ADMIN_TEXT = [
    'zh' => [
        'html_lang' => 'zh-Hant',
        'brand' => '信息管理',
        'switch_to' => 'English',
        'switch_lang' => 'en',
        'switch_aria' => 'Switch to English',
        'log_out' => '登出',
        // Login
        'login_title' => '登入',
        'login_intro' => '在 cahob.org 撰寫並發佈信息。',
        'username' => '用戶名',
        'password' => '密碼',
        'show_password' => '顯示密碼',
        'hide_password' => '隱藏密碼',
        'log_in' => '登入',
        'back_to_messages' => '信息頁面',
        'wrong_login' => '錯誤：密碼或用戶名不正確',
        'locked_out' => '嘗試次數過多，請在 %d 分鐘後再試。',
        'stale_form' => '頁面已過期，請再試一次。',
        'expired' => '閒置超過兩小時，已自動登出，請重新登入。',
        'logged_out' => '您已登出。',
        'no_password' => '尚未設定管理員密碼，請執行 make dev-password。',
        // Dashboard
        'messages_title' => '信息',
        'new_message' => '新增信息',
        'view_messages' => '查看信息頁面',
        'col_title' => '標題',
        'col_status' => '狀態',
        'col_date' => '日期',
        'col_languages' => '語言',
        'col_actions' => '操作',
        'status_draft' => '草稿',
        'status_published' => '已發佈',
        'untitled' => '無標題',
        'edit' => '編輯',
        'view' => '查看',
        'delete' => '刪除',
        'new_tab' => '（在新分頁開啟）',
        'no_messages' => '還沒有任何信息。按「新增信息」開始撰寫。',
        'notice_deleted' => '已刪除信息。',
        'security_title' => '安全紀錄',
        'security_intro' => '最近 %d 筆登入紀錄，最新的在最上面。完整紀錄存放在伺服器的 cahob-data/logs/security.log。',
        'security_empty' => '目前沒有紀錄。',
        'col_time' => '時間',
        'col_event' => '事件',
        'col_ip' => 'IP 位址',
        'col_details' => '說明',
        'event_LOGIN_OK' => '登入成功',
        'event_LOGIN_FAILED' => '登入失敗',
        'event_LOCKED_OUT' => '已鎖定',
        'event_BLOCKED' => '鎖定中仍嘗試',
        // Editor
        'new_title' => '新增信息',
        'edit_title' => '編輯信息',
        'back_to_list' => '返回信息列表',
        'publish_date' => '發佈日期',
        'publish_date_hint' => '太平洋時間',
        'section_zh' => '中文',
        'section_en' => '英文',
        'title_label' => '標題（可留空）',
        'body_label' => '內容',
        'needs_js' => '編輯器需要啟用 JavaScript。',
        'btn_save_draft' => '儲存草稿',
        'btn_publish' => '發佈',
        'btn_update' => '更新',
        'btn_unpublish' => '改回草稿',
        'btn_preview' => '預覽',
        'status_line_draft' => '草稿：尚未公開',
        'status_line_published' => '已發佈',
        'view_public' => '查看公開頁面',
        'notice_saved' => '已儲存草稿。',
        'notice_published' => '已發佈！',
        'notice_updated' => '已更新。',
        'notice_unpublished' => '已改回草稿，訪客看不到這篇信息了。',
        'error_date' => '請輸入正確的發佈日期和時間。',
        'error_needs_body' => '發佈前，至少要在一種語言寫下內容。',
        'error_empty' => '請先輸入標題或內容。',
        'not_found_admin' => '找不到這篇信息，可能已被刪除。',
        'restore_found' => '這篇信息有一份尚未儲存的版本（{time}）。',
        'restore' => '還原',
        'discard' => '捨棄',
        'session_lost' => '登入已過期。您的內容已暫存在這個瀏覽器中，重新登入後可以還原。',
        'log_in_again' => '重新登入',
        'tb_h2' => '大標題',
        'tb_h3' => '小標題',
        'tb_bold' => '粗體',
        'tb_italic' => '斜體',
        'tb_ol' => '編號清單',
        'tb_ul' => '項目清單',
        'tb_scripture' => '經文側欄：文字往右內縮，左側加一條線',
        'tb_scripture_label' => '經文',
        'tb_link' => '連結',
        'tb_center' => '置中',
        'tb_divider' => '分隔線',
        'tb_table' => '插入表格',
        'tb_row' => '在下方新增一列',
        'tb_col' => '在右邊新增一欄',
        'tb_table_delete' => '刪除表格',
        'tb_clean' => '清除格式',
        // Delete
        'delete_title' => '刪除信息',
        'delete_question' => '確定要刪除這篇信息嗎？',
        'delete_warning' => '刪除後無法復原。如果只是暫時不想公開，可以改回草稿。',
        'delete_confirm' => '刪除',
        'cancel' => '取消',
        // Preview
        'preview_title' => '預覽',
        'preview_notice' => '預覽：這是目前編輯中的內容，尚未儲存，訪客看不到。',
        'preview_empty' => '還沒有內容可以預覽。',
    ],
    'en' => [
        'html_lang' => 'en',
        'brand' => 'Messages',
        'switch_to' => '中文',
        'switch_lang' => 'zh-Hant',
        'switch_aria' => '切換至中文',
        'log_out' => 'Log out',
        // Login
        'login_title' => 'Log in',
        'login_intro' => 'Write and publish messages on cahob.org.',
        'username' => 'Username',
        'password' => 'Password',
        'show_password' => 'Show password',
        'hide_password' => 'Hide password',
        'log_in' => 'Log in',
        'back_to_messages' => 'Messages page',
        'wrong_login' => 'Error: Wrong password or username',
        'locked_out' => 'Too many failed attempts. Try again in %d minutes.',
        'stale_form' => 'This page was open too long. Please try again.',
        'expired' => 'You were logged out after 2 hours without activity. Please log in again.',
        'logged_out' => "You're logged out.",
        'no_password' => 'No admin password is set up yet. Run make dev-password.',
        // Dashboard
        'messages_title' => 'Messages',
        'new_message' => 'New message',
        'view_messages' => 'View the Messages page',
        'col_title' => 'Title',
        'col_status' => 'Status',
        'col_date' => 'Date',
        'col_languages' => 'Languages',
        'col_actions' => 'Actions',
        'status_draft' => 'Draft',
        'status_published' => 'Published',
        'untitled' => 'No title',
        'edit' => 'Edit',
        'view' => 'View',
        'delete' => 'Delete',
        'new_tab' => '(opens in a new tab)',
        'no_messages' => 'No messages yet. Click "New message" to write one.',
        'notice_deleted' => 'Message deleted.',
        'security_title' => 'Security log',
        'security_intro' => 'The latest %d login events, newest first. The full log is on the server in cahob-data/logs/security.log.',
        'security_empty' => 'Nothing logged yet.',
        'col_time' => 'Time',
        'col_event' => 'Event',
        'col_ip' => 'IP address',
        'col_details' => 'Details',
        'event_LOGIN_OK' => 'Logged in',
        'event_LOGIN_FAILED' => 'Failed login',
        'event_LOCKED_OUT' => 'Locked out',
        'event_BLOCKED' => 'Tried while locked out',
        // Editor
        'new_title' => 'New message',
        'edit_title' => 'Edit message',
        'back_to_list' => 'Back to messages',
        'publish_date' => 'Publish date',
        'publish_date_hint' => 'Pacific time',
        'section_zh' => 'Chinese',
        'section_en' => 'English',
        'title_label' => 'Title (optional)',
        'body_label' => 'Text',
        'needs_js' => 'The editor needs JavaScript turned on.',
        'btn_save_draft' => 'Save draft',
        'btn_publish' => 'Publish',
        'btn_update' => 'Update',
        'btn_unpublish' => 'Unpublish',
        'btn_preview' => 'Preview',
        'status_line_draft' => 'Draft: not public yet',
        'status_line_published' => 'Published',
        'view_public' => 'View the public page',
        'notice_saved' => 'Draft saved.',
        'notice_published' => 'Published!',
        'notice_updated' => 'Updated.',
        'notice_unpublished' => 'Unpublished. Visitors can no longer see this message.',
        'error_date' => 'Please enter a valid publish date and time.',
        'error_needs_body' => 'Write the text in at least one language before publishing.',
        'error_empty' => 'Please enter a title or some text first.',
        'not_found_admin' => "This message wasn't found. It may have been deleted.",
        'restore_found' => "There's an unsaved version of this message ({time}).",
        'restore' => 'Restore',
        'discard' => 'Discard',
        'session_lost' => 'Your login expired. Your text is kept in this browser and can be restored after you log in again.',
        'log_in_again' => 'Log in again',
        'tb_h2' => 'Heading',
        'tb_h3' => 'Subheading',
        'tb_bold' => 'Bold',
        'tb_italic' => 'Italic',
        'tb_ol' => 'Numbered list',
        'tb_ul' => 'Bulleted list',
        'tb_scripture' => 'Scripture sidebar: moves the text right with a bar on the left',
        'tb_scripture_label' => 'Scripture',
        'tb_link' => 'Link',
        'tb_center' => 'Center',
        'tb_divider' => 'Divider',
        'tb_table' => 'Insert table',
        'tb_row' => 'Add a row below',
        'tb_col' => 'Add a column to the right',
        'tb_table_delete' => 'Delete table',
        'tb_clean' => 'Clear formatting',
        // Delete
        'delete_title' => 'Delete message',
        'delete_question' => 'Delete this message?',
        'delete_warning' => "This can't be undone. To hide it for now instead, unpublish it.",
        'delete_confirm' => 'Delete',
        'cancel' => 'Cancel',
        // Preview
        'preview_title' => 'Preview',
        'preview_notice' => "Preview: this is the text you're editing. It isn't saved, and visitors can't see it.",
        'preview_empty' => "There's nothing to preview yet.",
    ],
];

const ADMIN_LANG_COOKIE = 'cahob_admin_lang';

function admin_lang(): string
{
    return ($_COOKIE[ADMIN_LANG_COOKIE] ?? '') === 'en' ? 'en' : 'zh';
}

/** Admin text in the chosen language, with any %s / %d filled in. */
function at(string $key, string|int ...$values): string
{
    $text = ADMIN_TEXT[admin_lang()][$key];
    return $values ? sprintf($text, ...$values) : $text;
}

/** A security log entry's details in the admin language (the log itself is in English). */
function security_details(string $details): string
{
    if (admin_lang() === 'en') {
        return $details;
    }
    if (preg_match('/^(\d+) failed logins in 15 minutes; locked for (\d+) minutes$/', $details, $m)) {
        return "15 分鐘內登入失敗 {$m[1]} 次，鎖定 {$m[2]} 分鐘";
    }
    return [
        'wrong password' => '密碼錯誤',
        'unknown username' => '用戶名錯誤',
        'tried to log in while locked out' => '在鎖定期間嘗試登入',
    ][$details] ?? $details;
}

/** $head is extra markup for <head>, e.g. the editor's stylesheet. It comes before admin.css, so admin.css can restyle it. */
function admin_header(string $title, ?string $admin = null, string $head = ''): void
{
    $other = admin_lang() === 'zh' ? 'en' : 'zh';
    $switchUrl = '/admin/language.php?to=' . $other . '&back=' . rawurlencode((string) ($_SERVER['REQUEST_URI'] ?? '/admin/'));
    ?>
<!DOCTYPE html>
<html lang="<?= at('html_lang') ?>">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title><?= e($title) ?> | CAHOB <?= at('brand') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Lora:wght@600;700&amp;family=Nunito+Sans:opsz,wght@6..12,400;6..12,600;6..12,700&amp;family=Noto+Serif+TC:wght@600&amp;family=Noto+Sans+TC:wght@400;500;700&amp;display=swap" rel="stylesheet" />
    <link rel="icon" type="image/svg+xml" href="/images/favicon.svg" />
    <link rel="stylesheet" href="/styles.css?v=<?= STYLES_VERSION ?>" />
<?= $head === '' ? '' : $head . PHP_EOL ?>
    <link rel="stylesheet" href="/admin/admin.css?v=<?= STYLES_VERSION ?>" />
  </head>
  <body class="admin-page messages-page">
    <header class="admin-bar">
      <div class="admin-bar-inner">
        <a href="/admin/" class="admin-brand"><strong>CAHOB</strong> <span><?= at('brand') ?></span></a>
        <div class="admin-user">
          <a href="<?= e($switchUrl) ?>" class="admin-lang" lang="<?= at('switch_lang') ?>" aria-label="<?= at('switch_aria') ?>"><?= at('switch_to') ?></a>
<?php if ($admin !== null): ?>
          <span class="admin-name"><?= e($admin) ?></span>
          <form method="post" action="/admin/logout.php">
            <?= csrf_field() ?>
            <button type="submit" class="admin-link-button"><?= at('log_out') ?></button>
          </form>
<?php endif; ?>
        </div>
      </div>
    </header>
    <main class="admin-main">
<?php
}

/** $scripts is extra markup before </body>, e.g. the editor's scripts. */
function admin_footer(string $scripts = ''): void
{
    ?>
    </main>
    <script src="/admin/admin.js?v=<?= STYLES_VERSION ?>"></script>
<?= $scripts === '' ? '' : $scripts . PHP_EOL ?>
  </body>
</html>
<?php
}

/** A status line, e.g. after logging out or when a form has a problem. */
function admin_alert(string $kind, string $text): void
{
    ?>
      <p class="admin-alert admin-alert--<?= $kind ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><?= e($text) ?></p>
<?php
}
