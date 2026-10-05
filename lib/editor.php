<?php
// The message editor's form handling: turning a submitted form into a clean message, checking
// it, and applying the button that was pressed (save draft, publish, update, unpublish).
// Kept out of admin/edit.php so it can be tested without a browser.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sanitize.php';
require_once __DIR__ . '/render.php';

const TITLE_MAX = 200;
const EDITOR_TIMEZONE = 'America/Los_Angeles';

/** "2026-10-04T17:30" typed in Pacific time → "2026-10-05T00:30:00Z", or null if it isn't a valid date and time. */
function local_to_utc(string $local): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone(EDITOR_TIMEZONE));
    if ($date === false || $date->format('Y-m-d\TH:i') !== $local) {
        return null;
    }
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** A stored UTC time as the value of a datetime-local field, in Pacific time. */
function utc_to_local_input(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(EDITOR_TIMEZONE))->format('Y-m-d\TH:i');
}

function now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

/** A blank message for the "new message" form. */
function new_message(): array
{
    return [
        'id' => null, 'slug' => '', 'status' => 'draft', 'published_at' => now_utc(),
        'title_en' => '', 'body_en' => '', 'title_zh' => '', 'body_zh' => '',
    ];
}

/** The submitted form as a message: titles trimmed and shortened, bodies sanitized. */
function message_from_form(array $post): array
{
    // mb_scrub replaces broken UTF-8 (which browsers never send, but anything can) instead of failing.
    $text = fn(string $name): string => is_string($post[$name] ?? null) ? mb_scrub($post[$name], 'UTF-8') : '';
    $title = fn(string $name): string => mb_substr(trim(preg_replace('/\s+/u', ' ', $text($name))), 0, TITLE_MAX);
    return [
        'title_en' => $title('title_en'),
        'title_zh' => $title('title_zh'),
        'body_en' => sanitize_html($text('body_en')),
        'body_zh' => sanitize_html($text('body_zh')),
        'published_at_input' => $text('published_at'),
        'published_at' => local_to_utc($text('published_at')),
    ];
}

/** Problems that stop this action, as ADMIN_TEXT keys. */
function message_errors(array $message, string $action): array
{
    $errors = [];
    if ($message['published_at'] === null) {
        $errors[] = 'error_date';
    }
    $hasBody = !html_is_blank($message['body_en']) || !html_is_blank($message['body_zh']);
    $hasTitle = $message['title_en'] !== '' || $message['title_zh'] !== '';
    if (in_array($action, ['publish', 'update'], true) && !$hasBody) {
        $errors[] = 'error_needs_body';
    } elseif (!$hasBody && !$hasTitle) {
        $errors[] = 'error_empty';
    }
    return $errors;
}

/**
 * Applies an editor button to a message. Returns ['id' => ..., 'notice' => key] on success, or
 * ['errors' => [...], 'message' => the form as submitted] so the form can be shown again.
 */
function apply_editor_action(PDO $pdo, ?array $existing, array $post, string $action): array
{
    $message = message_from_form($post);
    $errors = message_errors($message, $action);
    if ($errors) {
        return ['errors' => $errors, 'message' => $message + ['status' => $existing['status'] ?? 'draft']];
    }
    $status = match ($action) {
        'publish', 'update' => 'published',
        default => 'draft', // 'draft' and 'unpublish'
    };
    $id = save_message($pdo, $message + ['status' => $status], $existing);
    $notice = match ($action) {
        'publish' => 'notice_published',
        'update' => 'notice_updated',
        'unpublish' => 'notice_unpublished',
        default => 'notice_saved',
    };
    return ['id' => $id, 'notice' => $notice];
}

/** How a message is labeled on the dashboard: its title in the admin language, the other title, or its opening words. */
function dashboard_label(array $message): array
{
    $first = admin_lang() === 'zh' ? 'zh' : 'en';
    foreach ([$first, other_lang($first)] as $lang) {
        if (trim($message['title_' . $lang]) !== '') {
            return ['text' => $message['title_' . $lang], 'lang' => $lang, 'untitled' => false];
        }
    }
    foreach ([$first, other_lang($first)] as $lang) {
        if (!html_is_blank($message['body_' . $lang])) {
            return ['text' => excerpt($message['body_' . $lang], 70), 'lang' => $lang, 'untitled' => true];
        }
    }
    return ['text' => '', 'lang' => $first, 'untitled' => true];
}
