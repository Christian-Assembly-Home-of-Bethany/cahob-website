# Plan: "Messages" tab with a small PHP + SQLite CMS

## Goal

Add a **Messages / 信息** tab where the pastor can log in, write, and publish messages,
replacing the current Blogger workflow. Visitors see a paginated list of messages and a
page for each one. Everything stays on the existing cPanel host.

**Status (2026-10-04):** steps 1–6 are built on branch `sc/messages-tab` and go to `main` in
one PR. Next is the soft launch (step 7): merge, then the one-time server setup below.

## Feasibility

This is doable on the current setup:

- **cPanel runs PHP.** Confirmed on the host (see "Server check" below). No MySQL database
  setup is needed.
- **Deploys still work.** `.github/workflows/deploy.yml` already FTPs the whole repo, so new
  `.php` files go up the same way. The data that changes at runtime (the database, login
  sessions, and the security log) lives on the server only and is never in git, so a deploy
  can't overwrite it.
- **The static pages stay as they are.** Only the new messages pages and the admin area use PHP.
  The other `.html` pages just get a new nav link at go-live.

### Server check (done 2026-10-04)

A temporary check script on cahob.org confirmed:

- **PHP:** 8.2.33 (`ea-php82`, cPanel's EasyApache build), the same version CI tests on
- **Extensions:** `pdo_sqlite`, `sqlite3`, `mbstring`, and `session` are all enabled. `gd`
  and `exif` are on too, though this text-only plan doesn't use them
- **Web server:** LiteSpeed, which supports the `.htaccess` rules this plan uses
- **HTTPS:** working
- **Runs as:** PHP runs as the cPanel user, so it can write to that user's folders without
  permission changes
- **Data folder:** `~/cahob-data/` exists outside the web
  root, and SQLite can create and write a database there

The host also has MariaDB (via phpMyAdmin), which could be a fallback. Its default charset is
`latin1`, so any MariaDB tables would need `utf8mb4` to store Chinese text.

## What the current blog looks like

The pastor's Blogger site is https://johannavoice.blogspot.com ("曠野人聲 / A Voice in the
Wilderness"):

- **Text only.** Posts are long (often 1,000+ words), with scripture references, headings,
  numbered sections, and bold for key terms. There are no photos, so this plan has no image
  uploads.
- **Languages.** As of 2026-10-04 the public feed has 30 posts (2026-07-16 to 2026-10-02),
  and every one is half of an English/Chinese pair: 15 messages, each posted as two entries
  within about an hour of each other on the same day. Even the Bible reading notes have both.
  A future message could still be in one language only, so the CMS allows that.
- **No Blogger titles.** Every post's title field is empty. The title is usually the first
  line of the body, often bold, larger, or centered. A few posts start with something else:
  a "Last updated" date, the series name (讀經隨筆 / The Notes of Bible Reading), or a full
  opening sentence.
- **Pasted from Word.** The posts use Word's formatting: bold (`<b>` plus bold styles),
  italics, line breaks, centered titles and scripture lines, horizontal lines between
  sections, a few headings, and a table in the two *Classical Christian Education* posts. The
  CMS keeps all of these (see "Admin experience" and "Security").
- **Blogger features in use.** Labels (e.g. Romans, Bible Reading Notes), a monthly archive,
  comments (one in total), and share buttons. These are all out of scope for v1.

## Build vs. adopt

| Option | Pros | Cons |
|---|---|---|
| **Custom PHP + SQLite (chosen)** | Matches the site's look exactly, bilingual nav works the same way, about 3,600 lines of our own code (plus 1,800 lines of tests) that we fully understand, no plugin updates to keep up with | We own the security basics (auth, CSRF, sanitizing) |
| Flat-file blog (HTMLy, Bludit) | Admin UI is ready to use | Its own theme system to fight, a separate look, more attack surface to keep patched |
| WordPress via Softaculous | Most familiar to non-technical users | Heavy, needs MySQL and constant updates, overkill for one author |

There is one author and one content type, so a small custom app is the simplest thing that fits.
The security features it needs are well understood (see "Security" below).

## Architecture

```
/                         (web root, deployed from repo)
├── messages.php          public list, English chrome (messages-zh.php reuses it for Chinese)
├── messages-zh.php       public list, Chinese chrome
├── message.php           single message (?slug=...&lang=en|zh), both languages
├── admin/                .htaccess forces HTTPS; pages are never cached, framed, or indexed
│   ├── index.php         dashboard: all messages, plus the latest security log entries
│   ├── login.php / logout.php / language.php (中文 ⇄ English for the admin pages)
│   ├── edit.php          create/edit form with the Quill editor
│   ├── preview.php       preview of unsaved text in the public look (new tab)
│   ├── delete.php        delete confirmation (a delete only hides the message)
│   ├── restore.php       brings back a deleted message
│   ├── ping.php          keeps the login alive while the pastor is typing
│   └── editor.js, admin.js, admin.css
├── lib/                  not web-accessible (.htaccess: Require all denied)
│   ├── bootstrap.php     loads config, opens the database (no session for visitors)
│   ├── db.php            queries, saving, slugs, schema migrations
│   ├── auth.php          login, sessions, CSRF, lockout, security log
│   ├── render.php        navbar/footer/hero for PHP pages, admin layout, all UI text (en/zh)
│   ├── editor.php        editor form handling (kept out of the page so it can be tested)
│   ├── sanitize.php      HTML Purifier allowlist
│   ├── config.example.php    template for the server's config.php
│   ├── tools/hash_password.php   makes the password hash for config.php
│   └── vendor/htmlpurifier/   vendored HTML sanitizer (v4.19.1)

(repo only, never deployed: tests/, composer.json, composer.lock, phpunit.xml, and the local
tools lib/tools/dev_router.php, seed_dev.php, import_blogger.php)

/home/<cpanel-user>/cahob-data/     (OUTSIDE web root, never deployed)
├── config.php            admin username + password hash, DB path
├── messages.sqlite       every message
├── sessions/             login sessions (created automatically)
└── logs/security.log     login events (created automatically)
```

`lib/bootstrap.php` finds the config at `dirname($_SERVER['DOCUMENT_ROOT']) .
'/cahob-data/config.php'`, i.e. one folder above the web root. The `CAHOB_CONFIG` environment
variable overrides it (local dev and command-line tools). Nothing in `cahob-data/` can be
downloaded over HTTP, and FTP deploys never touch it.

### Data model (SQLite)

```sql
CREATE TABLE messages (
  id           INTEGER PRIMARY KEY,
  slug         TEXT NOT NULL UNIQUE,
  title_en     TEXT NOT NULL DEFAULT '',
  body_en      TEXT NOT NULL DEFAULT '',  -- sanitized HTML
  title_zh     TEXT NOT NULL DEFAULT '',
  body_zh      TEXT NOT NULL DEFAULT '',  -- sanitized HTML
  status       TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
  published_at TEXT,                   -- ISO 8601 UTC; allows back-dating imported posts
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  was_published INTEGER NOT NULL DEFAULT 0,  -- 1 once ever published: the slug is then fixed
  deleted_at   TEXT                        -- set when deleted; NULL again when restored
);
CREATE INDEX idx_messages_pub ON messages (status, published_at DESC);

-- A copy of a message each time it's saved, so an earlier version can be brought back.
CREATE TABLE message_revisions (
  id           INTEGER PRIMARY KEY,
  message_id   INTEGER NOT NULL REFERENCES messages (id) ON DELETE CASCADE,
  title_en     TEXT NOT NULL,
  body_en      TEXT NOT NULL,
  title_zh     TEXT NOT NULL,
  body_zh      TEXT NOT NULL,
  status       TEXT NOT NULL,
  published_at TEXT,
  saved_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_revisions_message ON message_revisions (message_id, id);

CREATE TABLE login_attempts (
  ip           TEXT NOT NULL,
  attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_login_attempts ON login_attempts (ip, attempted_at);
```

The tables are created on first run, so there is no separate install step. SQLite's
`user_version` records which schema changes a database already has, so later changes can be
added as numbered migrations in `db.php`. Migration 1 is the first schema; migration 2 adds
`was_published`, `deleted_at`, and `message_revisions`, and gives each existing message its
first saved version. Times are stored in UTC and shown in Pacific time.

- **Version history.** Every save (from the editor or the import) adds a row to
  `message_revisions` with the message as it was saved, unless nothing changed since the last
  one. Restoring an old version means loading it into the editor and saving it, so it becomes
  the newest version and the history only ever grows.
- **Deletes can be undone.** Deleting sets `deleted_at` instead of removing the row. Every
  public page and the dashboard skip deleted messages, and the version history is kept.
  Restoring clears `deleted_at`, and the message comes back with the status it had. A deleted
  message keeps its slug, so restoring it never clashes with a newer message. Only the local
  tools (`make seed`, `import_blogger.php --replace`) really delete rows, and the versions go
  with them.

### Bilingual handling

The pastor writes in both English and Chinese. Each post has an English section and a
Chinese section, and either can be left empty. A message written in only one language just
has an empty section for the other. A section counts as written when it has a body.

**Titles are optional**, in both languages, for new and imported posts alike. A post can be
published once at least one language has a body. A post without a title is shown by its
date and opening words in the lists, and by its date as the heading of its own page.

The slug comes from the English title (or the date if there isn't one), cut to about 60
characters. If that slug is taken, a `-2`, `-3`, etc. is added. Until a post is first
published, its slug follows its title. Once it has been published, the slug never changes
(`was_published`), even if the title is edited or added later, or the post is unpublished,
renamed, and published again, so links already shared on LINE or WeChat keep working.

- `messages.php` lists posts using the English titles. `messages-zh.php` uses the Chinese
  titles. If a post has no section in that language, the list shows the other language with a
  small **中文** / **English** tag.
- `message.php` shows **the chosen language first, then the other language below it**, under
  its own heading, with a **中文版 ↓** / **English version ↓** link at the top that jumps to
  it. `lang=en` and `lang=zh` choose which comes first and the language of the page around
  it; the top bar's language switch flips between them.
- If the chosen language wasn't written, the page shows the other language alone, with a note
  ("This message is available in Chinese only"), so no post ever looks blank.

On Blogger each language is a separate post. Here both languages are one post, which keeps one post
per message and fits the CLAUDE.md rule that both language versions get
updated together.

### Shared chrome

The static pages repeat the navbar and footer in every file. The PHP pages use
`page_header()`, `page_hero()`, and `page_footer()` from `lib/render.php` instead, copied from
the current markup and using the same `styles.css` and `script.js`. If the nav changes later,
update the six `.html` files **and** `render.php`.

The list pages use the same page hero as the other pages, titled **Messages** with the old
blog's name, *A Voice in the Wilderness*, as the subtitle (**信息** and *曠野人聲* in
Chinese). A small **Sign in / 登入** button sits at the top right, just under the hero, and
goes to `/admin/`.

## Admin experience (the Blogger replacement)

- **Language.** The admin pages are in **Traditional Chinese**, with an **English** button in
  the header that switches every admin page to English (for testers). The choice is
  remembered in a cookie.
- **Login** at `cahob.org/admin/`. There is one admin account, for the pastor, with a
  username and a bcrypt hash in `config.php`. The password box has a show/hide button. A wrong
  login says **錯誤：密碼或用戶名不正確** / **Error: Wrong password or username**. After a session
  expires, logging back in returns to the page the pastor was on. There's no change-password
  page: when the pastor asks for a new password, we make a new hash with `make password` and
  replace it in `config.php` on the server.
- **Dashboard** shows every message newest first, marked 草稿 (draft) or 已發佈 (published),
  with its date, which languages are written (中 / EN), and Edit, View, and Delete links, plus a
  **新增信息** (new message) button. Posts without a title show their date and opening words
  instead. Under the list, a folded **已刪除的信息** (deleted messages) section lists deleted
  messages with a **還原** (restore) button; it only appears when something is deleted, and
  opens by itself right after a delete. Below that are the **latest 10 security log entries**
  (failed logins, lockouts, successful logins).
- **Editor** has a publish date (defaults to now, Pacific time), then two sections, **中文**
  on the left and **English** on the right, each with its own title (optional) and a WYSIWYG
  body. On a phone the sections stack. The toolbar has headings, bold/italic, numbered and
  bulleted lists, a labeled **經文** button (a scripture sidebar: the text moves right with a
  bar on the left, and lines in a row share one bar), links, centered text, a divider
  (horizontal line), tables (insert, add a row or column, delete), and clear formatting.
- **Buttons.** A draft has **儲存草稿** (save draft), **預覽** (preview), and **發佈** (publish).
  A published message has **更新** (update), **預覽**, and **改回草稿** (unpublish).
  **Preview** opens the text being edited in a new tab with the public look, both languages,
  without saving anything. **Delete** goes through a confirmation page that says the message
  can be restored later, and suggests unpublishing to just hide it for now.
- **Version history (版本記錄).** Under the editor, a dropdown lists every saved version of
  the message by date, time, and status, with the current one marked and greyed out.
  **載入這個版本** (load this version) puts that version into the editor without saving; a
  banner says so, with a link back to the current version. Pressing 更新 (or 儲存草稿 for a
  draft) restores it.
- **Pasting** from Word or Google Docs keeps headings, bold, italics, lists, centered text,
  dividers, and tables. Fonts, sizes, colors, and other formatting are dropped right away.
- **Quill details.** Quill 2.0.3 is loaded from jsDelivr, pinned to that version with
  Subresource Integrity hashes, so a changed file is refused by the browser. Quill has no
  divider built in, so it gets a small custom divider format; tables use Quill 2's table
  module. Alignment is saved as an inline `text-align` style. The editor saves
  `getSemanticHTML()`, not the raw editor HTML, because Quill 2 stores bullet lists in a form
  the sanitizer would turn into numbered lists. Quill 2.0.3's `getSemanticHTML()` writes every
  space as `&nbsp;`, which would stop English text from wrapping, so `sanitize_html()` turns
  them back into normal spaces.
- **No lost work.** While the pastor types, the editor keeps a copy in the browser and offers
  to restore it (還原) the next time that message is opened without having been saved. It
  also keeps the login alive while the pastor is actively typing, says so if the login expires
  anyway, and warns before leaving the page with unsaved changes.
- The editor works on a phone, so the pastor can post from a phone.

## Security

- **Passwords:** bcrypt via `password_hash` / `password_verify`. `make password` reads the
  password without echoing it (so it's never in shell history or the repo) and prints the hash
  to paste into the server-side `config.php`. Even a wrong username does the full password
  check, so both take the same time.
- **Sessions:** only admin pages (and nothing visitors see) start a session. Session files live
  in `~/cahob-data/sessions/`, not the server's shared temp folder, so the host's cleanup job
  can't end them early and other sites can't read them. Cookies are `Secure` (except on a
  local machine), `HttpOnly`, and `SameSite=Lax`. The session ID is regenerated on login.
  Sessions end after 2 hours of inactivity.
- **CSRF:** every admin form, including logout, carries a token that's checked on submit.
- **Lockout:** failed logins are rate-limited per IP address: 5 failures within 15 minutes
  locks login from that address for 15 minutes, tracked in the `login_attempts` table.
  Counting per address means someone else's failed attempts can't lock the pastor out.
- **Security log:** failed logins (`LOGIN_FAILED`, with "wrong password" or "unknown
  username"), lockouts (`LOCKED_OUT`), attempts while locked out (`BLOCKED`), and successful
  logins (`LOGIN_OK`) are written to `~/cahob-data/logs/security.log` with the time, IP
  address, and browser. It never records the username or password typed. Line breaks in
  request data are stripped so nobody can forge log lines. At 1 MB it moves to
  `security.log.1` and a new file starts. The dashboard shows the latest 10 entries.
- **HTML cleaning:** everything saved (from the editor, a direct form post, or the Blogger
  import) is cleaned with **HTML Purifier** using an allowlist: p, br, h2–h4, strong, em,
  ul/ol/li, a[href] (http, https, mailto), blockquote, hr, and table/thead/tbody/tr/th/td, plus
  `text-align: center` as the only allowed style. Word's `b` and `i` are converted to `strong`
  and `em`, and `h1` to `h2`. This blocks stored XSS even if the editor is bypassed.
- **Database and output:** every query that uses visitor input is a prepared statement.
  Every non-HTML value is escaped on output with `htmlspecialchars`.
- **Headers and paths:** admin pages send `Cache-Control: no-store`, `X-Frame-Options: DENY`,
  and `X-Robots-Tag: noindex`. After login, the app only redirects to `/admin/` paths, so the
  login link can't send someone to another site. `.htaccess` forces HTTPS for `/admin/` and
  denies `/lib/`.
- There are no file uploads, so a whole class of upload attacks doesn't apply.

## Deploy changes

`deploy.yml` excludes everything that's only for the repo: `.git*`, `.github/**`,
`README.md`, `Makefile`, `CLAUDE.md`, `plans/**`, `tests/**`, `composer.json`,
`composer.lock`, `phpunit.xml`, and the local tools `lib/tools/dev_router.php`,
`seed_dev.php`, and `import_blogger.php`. Composer's `vendor/` (PHPUnit) and `dev-data/` are
git-ignored, so they never reach the deploy. Anything above the web root, like
`~/cahob-data/`, is never touched by deploys.

If the PHP pages are deployed before `config.php` exists, every messages and admin page shows a
plain "Messages are coming soon / 信息即將推出" page (HTTP 503) that doesn't reveal any server
paths. Nobody can log in.

## Going live: one-time server setup

Do this once, right after the PR is merged and deployed. Steps 1 and 3 run locally in Git
Bash, from the repo folder; the rest is in cPanel.

1. **Make the password hash.** Run `make password`, type the pastor's password (at least 10
   characters; a few words works well), and copy the printed line that starts with `$2y$10$`.
2. **Create `config.php`.** In cPanel → **File Manager**, open your home folder (the one that
   contains `public_html`), then `cahob-data`. Click **+ File**, name it `config.php`, click
   **Edit**, and paste in the contents of `lib/config.example.php`. Put the hash between the
   quotes after `'password_hash' =>`. Keep `'username' => 'pastor'` (or choose another and tell
   the pastor) and `'debug' => false`. Save.
3. **Import the Blogger posts.** Locally, run
   `php lib/tools/import_blogger.php --db=dev-data/live-import.sqlite`. Do it right before
   uploading, so it includes the newest Blogger posts. Check the list it prints.
4. **Upload them.** In File Manager → `cahob-data`, click **Upload**, choose
   `dev-data/live-import.sqlite`, then rename it to `messages.sqlite` (replace the file if one
   is already there). Do this only before the pastor starts writing: uploading later would
   replace anything written on the new site since.
5. **Lock down permissions.** Right-click `config.php` and `messages.sqlite` → **Change
   Permissions** → **600**. Set the `cahob-data` folder to **700**.
6. **Check it.** `https://cahob.org/messages.php` should list the 15 imported messages (not
   "Messages are coming soon"; if it still says that, the app isn't finding
   `~/cahob-data/config.php`). At `https://cahob.org/admin/`, enter a wrong password once,
   then the right one. `cahob-data/logs/security.log` should now have a `LOGIN_FAILED` line
   and a `LOGIN_OK` line, and `cahob-data/sessions/` should exist. The security log and the
   sessions folder need no setup of their own.
7. **Set up the nightly backup.** In cPanel → **Cron Jobs**, add a job with the **Once Per
   Day** setting and this command (it keeps 30 days of copies in `~/backups/`):

   ```
   mkdir -p $HOME/backups && cp $HOME/cahob-data/messages.sqlite $HOME/backups/messages-$(date +\%F).sqlite && find $HOME/backups -name 'messages-*.sqlite' -mtime +30 -delete
   ```

   cPanel's own account backups also include `cahob-data/`. `messages.sqlite` is the only data
   that isn't in git.

## Migrating existing Blogger posts

`lib/tools/import_blogger.php` is a one-off command-line script, run locally. It reads the
blog's **public Atom feed** (`/feeds/posts/default`), which has the full text of every
published post, so no Blogger export is needed. Comments, drafts, and anything that isn't a
post are skipped.

- **Language** comes from the body text: a post goes into the Chinese section if its text
  is mostly Chinese, and into the English section otherwise. (Titles can't be used, because
  they're all empty.)
- **No titles.** Imported posts come in with empty titles, and each body is kept as written.
  They're **published** with their original dates, since they were already public on Blogger.
  A body's first line is usually the title the pastor wrote, so the posts read naturally
  without one. A title can be added in the editor at any time. When it is, that first line can
  be deleted from the body so it doesn't show twice.
- **Pairing.** On each Pacific-time day, each English post is paired with the Chinese post
  published closest in time, and each pair becomes one message with both sections, dated by
  the earlier post. Date alone isn't enough: 2026-08-07 has two pairs (*Who Are We 3-3* and
  *Marriage*). A post with no partner becomes a one-language message.
- **Word cleanup** runs before sanitizing: `<div>`s become paragraphs, `align="center"` and
  `text-align: center` become centering, bold and italic spans become `strong` and `em`,
  Word-only tags like `<o:p>` and bold wrapped around nothing but spaces are dropped, and
  **empty spacing paragraphs are removed** (the site's paragraphs already have space after
  them). The result then goes through the same HTML Purifier rules as new posts. On the
  current 30 posts this keeps all the bold text (1,097 bold runs), all dividers (72), both
  tables, and all 13 links, and loses no text.

`make import-preview` (a dry run) prints each planned message: its date, and the opening words
and URL of each Blogger post merged into it. `make import` imports into the local database,
replacing what's there. For the live site, `--db=` writes a new file to upload (see "Going
live"). The script refuses to write into a database that already has messages unless
`--replace` is given, so running it twice can't create duplicates. Its tests use a made-up
feed (`tests/fixtures/blogger-feed.xml`) that copies Blogger's and Word's markup, not the
pastor's writing.

## Site integration

Add **Messages** / **信息** to the navbar and footer links on all six `.html` pages, plus
`render.php`. `render.php` has the link from the start, so the messages pages show it as the
active tab. The six static pages only get it at go-live (see "Implementation steps"), so the
new pages stay hidden while they're tested.

## Local development

- `make up` serves the repo at http://localhost:8000 with PHP's built-in server, and
  `make down` stops it. On Windows, run `make` from Git Bash, because the Makefile's commands
  need a Unix shell. Locally this needs PHP 8.2+ with `pdo_sqlite` enabled.
- The first `make up` creates `dev-data/config.php` (git-ignored) with its own database and
  `'debug' => true`, and points the server at it through `CAHOB_CONFIG`. A small router,
  `lib/tools/dev_router.php`, blocks `lib/`, `tests/`, `vendor/`, and `dev-data/` the way the
  live server's `.htaccess` does.
- `make dev-password` sets the local admin password (username `pastor`).
- `make seed` fills the local database with sample messages; `make import-preview` and
  `make import` work with the real Blogger posts.
- `make test` (or `composer test`, after `composer install`) runs the PHPUnit tests. CI runs
  the same tests on every PR as the required **Backend tests** check.

## Implementation steps

Everything is built on one branch, one step at a time. After each step, we run it locally
with `make up`, review the changes in the browser, and commit the step once it's approved.
Every step includes tests for what it adds.

When steps 2–6 are done, **one PR into `main`** holds the whole feature, with one commit per
step. HTML Purifier gets a commit of its own, so reviewers can skip it. The PR description
says what each step adds, how to try it locally, and what was tested. Merged code does
nothing on the live site until `config.php` is added in step 7. Until then, the PHP pages only
show "Messages are coming soon" and nobody can log in.

1. ~~**Server check**~~ done (see above).
2. ~~**Skeleton**~~ done, in two commits: **2a.** HTML Purifier 4.19.1 by itself;
   **2b.** config loading, database schema, `.htaccess` rules, gitignore and deploy excludes,
   and PHPUnit, so the **Backend tests** check runs real tests.
3. ~~**Public pages**~~ done: both lists with pagination (10 per page), the two-language
   message page, and the sign-in button.
4. ~~**Auth**~~ done: login, logout, sessions, CSRF, lockout, the security log, and the
   Chinese/English admin pages.
5. ~~**Admin CRUD**~~ done: dashboard, Quill editor, HTML Purifier on save, drafts, preview,
   the in-browser copy of unsaved work, and delete with confirmation.
6. ~~**Blogger import**~~ done: the import script, tested on a made-up feed and dry-run on the
   real one.
7. **Soft launch:** open the one PR into `main`, and merge it once it's approved (after
   confirming). The static pages have no nav links yet, so visitors won't find the new pages.
   Do the one-time server setup above. We test on the live site first with the pastor's
   account. A delete only hides a post, so to clear the test posts, redo setup steps 3–4 (a
   fresh import and upload) before handing over. Review feedback on the PR added fixed links
   once published, version history, and deletes that can be undone.
8. **Show the pastor:** set a fresh password on the account, hand it over, and let the pastor
   try writing drafts. Make any changes from that feedback.
9. **Go live:** a small PR adds Messages / 信息 to the navbar and footer of all six static
   pages.

This replaces an earlier idea of a `./staging/` folder. That folder would sit under the same
web root, so it would read the same `~/cahob-data/config.php`, share the live database, and
be open to search engines.

## Out of scope for v1

These are left out on purpose and can be added later if needed: images, tags/labels, a
monthly archive, comments, share buttons, scheduled posts, email subscribers, RSS, latest
messages on the home page, redirects from old Blogger URLs, multiple authors, and a
change-password page.
