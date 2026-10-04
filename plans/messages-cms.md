# Plan: "Messages" tab with a small PHP + SQLite CMS

## Goal

Add a **Messages / 信息** tab where the pastor can log in, write, and publish messages,
replacing the current Blogger workflow. Visitors see a paginated list of messages and a
page for each one. Everything stays on the existing cPanel host.

## Feasibility

This is doable on the current setup:

- **cPanel runs PHP.** Confirmed on the host (see "Server check" below). No MySQL database
  setup is needed.
- **Deploys still work.** `.github/workflows/deploy.yml` already FTPs the whole repo, so new
  `.php` files go up the same way. The database is the only data that changes at runtime. It
  lives on the server only and is never in git, so a deploy can't overwrite it.
- **The static pages stay as they are.** Only the new messages pages and the admin area use PHP.
  The other `.html` pages just get a new nav link.

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
  comments, and share buttons. These are all out of scope for v1.

## Build vs. adopt

| Option | Pros | Cons |
|---|---|---|
| **Custom PHP + SQLite (recommended)** | Matches the site's look exactly, bilingual nav works the same way, roughly 1,600 lines of our own code that we fully understand, no plugin updates to keep up with | We own the security basics (auth, CSRF, sanitizing) |
| Flat-file blog (HTMLy, Bludit) | Admin UI is ready to use | Its own theme system to fight, a separate look, more attack surface to keep patched |
| WordPress via Softaculous | Most familiar to non-technical users | Heavy, needs MySQL and constant updates, overkill for one author |

There is one author and one content type, so a small custom app is the simplest thing that fits.
The security features it needs are well understood (see "Security" below).

## Architecture

```
/                         (web root, deployed from repo)
├── messages.php          public list, English chrome
├── messages-zh.php       public list, Chinese chrome
├── message.php           single message (?slug=...&lang=en|zh)
├── admin/
│   ├── index.php         dashboard: list of posts, drafts, edit/delete
│   ├── login.php / logout.php
│   ├── edit.php          create/edit form with WYSIWYG editor
│   └── editor.js         Quill setup, divider, tables, in-browser draft copy
├── lib/                  not web-accessible (.htaccess: Require all denied)
│   ├── bootstrap.php     loads config, opens PDO, starts session
│   ├── db.php            queries + schema migration
│   ├── auth.php          login, session, CSRF helpers
│   ├── render.php        shared header/nav/footer partials (en/zh)
│   ├── sanitize.php      HTML Purifier allowlist and Word cleanup
│   ├── config.example.php    template for the server's config.php
│   ├── tools/import_blogger.php   one-off Blogger import (CLI)
│   └── vendor/htmlpurifier/   vendored HTML sanitizer (v4.19.1)

(repo only, never deployed: tests/, composer.json, composer.lock, phpunit.xml)

/home/<cpanel-user>/cahob-data/     (OUTSIDE web root, never deployed)
├── config.php            admin password hash, DB path, secrets
└── messages.sqlite
```

`lib/bootstrap.php` finds the config at a fixed path above the web root, e.g.
`dirname($_SERVER['DOCUMENT_ROOT']) . '/cahob-data/config.php'`. Neither the database nor the
credentials can be downloaded over HTTP, and FTP deploys never touch them.

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
  published_at TEXT,                   -- ISO 8601; allows back-dating imported posts
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_messages_pub ON messages (status, published_at DESC);

CREATE TABLE login_attempts (
  ip           TEXT NOT NULL,
  attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_login_attempts ON login_attempts (ip, attempted_at);
```

The tables are created on first run (`CREATE TABLE IF NOT EXISTS`), so there is no separate
install step.

### Bilingual handling

The pastor writes in both English and Chinese. Each post has an English section and a
Chinese section, and either can be left empty. A message written in only one language just
has an empty section for the other. Publishing requires a title in at least one language. The
slug comes from the English title (or the date if there isn't one), cut to about 60
characters. If that slug is taken, a `-2`, `-3`, etc. is added. While a post is a draft, its
slug follows its title. Once it's published, the slug never changes, even if the title is
edited, so links to it keep working.

- `messages.php` lists posts using the English titles. `messages-zh.php` uses the Chinese
  titles.
- `message.php?lang=en` shows the English section with a **中文** link to the same post in
  Chinese, and `lang=zh` does the reverse. The site's existing language switch already works
  this way.
- If one section is empty, the list and the post page show the other language instead, so no
  post ever looks blank. The **中文** / **English** link only appears when both sections exist.

On Blogger each language is a separate post. Here both languages are one post, which keeps one post
per message and fits the CLAUDE.md rule that both language versions get
updated together.

### Shared chrome

The static pages repeat the navbar and footer in every file. The PHP pages use
`lib/render.php::header($lang, $active)` / `footer($lang)` instead, copied from the current
markup and using the same `styles.css` and `script.js`. If the nav changes later, update the
six `.html` files **and** `render.php`.

## Admin experience (the Blogger replacement)

- **Login** at `cahob.org/admin/`. There is one admin account, for the pastor, with a
  username and a bcrypt hash in `config.php`. There's no change-password page. When the
  pastor asks for a new password, we generate a new hash and replace it in `config.php` on
  the server.
- **Dashboard** shows posts newest first, marked Draft or Published, with Edit, View, and Delete
  links and a **New message** button. Posts without a title, like freshly imported ones, show
  their date and opening words instead.
- **Editor** has a publish date (defaults to now), then two sections, **English** and
  **中文**, each with its own title and a WYSIWYG body. On a phone the sections stack; on
  wider screens they sit side by side for easy comparison. The editor is Quill 2, loaded from
  a CDN, with the formatting the pastor uses: headings, bold/italic, numbered and bulleted
  lists, links, blockquote, centered text, and a divider (horizontal line). The buttons are
  **Save draft**, **Publish**, and **Preview** (opens the public page for the draft, visible
  only to the logged-in admin).
- **Pasting** from Word or Google Docs keeps headings, bold, italics, lists, centered text,
  dividers, and tables. Fonts, sizes, colors, and other formatting are dropped when the post
  is saved.
- **Quill details.** Quill has no divider built in, so it gets a small custom divider format.
  Tables use Quill 2's table module, which keeps tables that are pasted or imported and lets
  the pastor edit their text. Alignment is saved as an inline `text-align` style. Quill 2
  stores bullet lists in a form the sanitizer would turn into numbered lists, so the editor
  saves the output of `getSemanticHTML()`, not the raw editor HTML.
- **No lost work.** While the pastor types, the editor keeps a copy in the browser. If the
  session expires before saving, the copy is restored after logging back in.
- The editor works on a phone, so the pastor can post from a phone.

## Security

- `password_hash` / `password_verify` (bcrypt). The pastor never types the password into
  the repo. We generate the hash with `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`
  and paste it into the server-side `config.php`.
- Session cookies use `Secure`, `HttpOnly`, and `SameSite=Lax`. The session ID is regenerated
  on login. Sessions time out after 2 hours of inactivity.
- Every admin POST checks a CSRF token.
- Failed logins are rate-limited per IP address: 5 failures within 15 minutes locks login from
  that address for 15 minutes, tracked in a small `login_attempts` table. Counting per address
  means someone else's failed attempts can't lock the pastor out.
- HTML from the editor is cleaned with **HTML Purifier** using an allowlist: p, br, h2–h4,
  strong, em, ul/ol/li, a[href], blockquote, hr, and table/thead/tbody/tr/th/td, plus
  `text-align: center` as the only allowed style. Word's
  `b` and `i` are converted to `strong` and `em`, and `h1` to `h2`, so pasted formatting isn't
  lost. This blocks stored XSS even if the editor is bypassed.
- Every DB access uses prepared statements. Every non-HTML field is escaped on output with
  `htmlspecialchars`.
- `.htaccess` forces HTTPS for `/admin/` and denies `/lib/`.
- There are no file uploads, so a whole class of upload attacks doesn't apply.

## Deploy changes

`deploy.yml` already excludes repo-only files (`CLAUDE.md`, `Makefile`, `plans/**`). Add
`tests/**`, `composer.json`, `composer.lock`, and `phpunit.xml*` when the PHP code lands.
Composer's `vendor/` (PHPUnit) and `dev-data/` are git-ignored, so they never reach the deploy.
Anything above the web root, like `~/cahob-data/`, is never touched by deploys.

**One-time server setup** (done by hand in cPanel File Manager):

1. Add `config.php` to `~/cahob-data/` (the folder already exists), copied from
   `lib/config.example.php`, with the pastor's username and a password hash.
2. Visit `/admin/` once so the app creates the SQLite file and tables.
3. Back up `~/cahob-data/messages.sqlite` nightly with a cPanel cron job (e.g. a copy to
   `~/backups/`) or cPanel's own backups. It's the only data that isn't in git.

If the PHP pages are deployed before `config.php` exists, they show a plain "not set up yet"
page that doesn't reveal any server paths.

## Migrating existing Blogger posts

Write a one-off CLI script, `lib/tools/import_blogger.php`. It reads the Blogger export (Blogger →
Settings → Back up content → `.xml` Atom feed) and inserts each published post with its
original date. Comments, pages, settings, and drafts in the export are skipped. Until the
export is available, the script is developed against the blog's public feed
(`/feeds/posts/default`), which is also Atom and has every published post. If the export's
entry layout differs, the script reads both.

- **Language** comes from the body text: a post goes into the Chinese section if its text
  is mostly Chinese, and into the English section otherwise. (Titles can't be used, because
  they're all empty.)
- **No titles.** Imported posts come in with empty titles, and each body is kept as written.
  Titles are typed in the editor afterward. Publishing needs a title, so imported posts arrive
  as **drafts** with their original dates, and each one is published once it has a title.
  A body's first line is often the title the pastor wrote. When the title is added, that line
  can be deleted from the body so it doesn't show twice.
- **Pairing.** On each day, each English post is paired with the Chinese post published
  closest in time, and each pair becomes one post with both sections. Date alone isn't enough:
  2026-08-07 has two pairs (*Who Are We 3-3* and *Marriage*). The current pairs were posted
  0–67 minutes apart. A post with no partner becomes a one-language post.
- **Word cleanup** runs before sanitizing. Bold styled spans become `strong`, centered
  paragraphs keep `text-align: center`, and Word-only tags like `<o:p>` are dropped. The result
  then goes through the same HTML Purifier rules as new posts.

A `--dry-run` flag prints each planned post (its date, plus the opening words of each Blogger
post merged into it) so we can check it before importing. Run it locally against a throwaway
database first, check the result with `make up`, then run it for real and upload the
`.sqlite` to `~/cahob-data/`. The script finds the config through `CAHOB_CONFIG`, because
`DOCUMENT_ROOT` isn't set on the command line.

## Site integration

Add **Messages** / **信息** to the navbar and footer links on all six `.html` pages, plus
`render.php`. `render.php` has the link from the start, so the messages pages show it as the
active tab. The six static pages only get it at go-live (see "Implementation steps").

The list pages use the same page hero as the other pages, titled **Messages** with the old
blog's name, *A Voice in the Wilderness*, as the subtitle (**信息** and *曠野人聲* in
Chinese). Readers coming from Blogger will recognize it.

## Local development

`make up` serves the repo at http://localhost:8000 with PHP's built-in server, and `make
down` stops it. Locally this needs `php` and `php-sqlite`, with `pdo_sqlite` enabled in
`php.ini`. On Windows, run `make` from Git Bash, because the Makefile's commands need a Unix
shell.

Use a `config.php` path override (`CAHOB_CONFIG` env var) so local dev points at a throwaway
database in the repo's ignored `dev-data/` folder.

## Implementation steps

Everything is built on one branch, one step at a time. After each step, we run it locally
with `make up`, review the changes in the browser, and commit the step once it's approved.
Every step includes tests for what it adds.

When steps 2–6 are done, **one PR into `main`** holds the whole feature, with one commit per
step. HTML Purifier gets a commit of its own, so reviewers can skip it. The PR description
says what each step adds, how to try it locally, and what was tested. Merged code does
nothing on the live site until `config.php` is added in step 7. Until then, the PHP pages only
show "not set up yet" and nobody can log in.

1. ~~**Server check**~~ done (see above).
2. **Skeleton**, in two commits:
   - **2a. HTML Purifier:** add the library (v4.19.1: 379 files, about 30,000 lines) to
     `lib/vendor/htmlpurifier/` by itself. Reviewers only need to check the version, not
     read the code.
   - **2b. Skeleton:** `lib/bootstrap.php`, `db.php` with schema, `config.example.php`,
     `.htaccess` rules, gitignore and deploy excludes. Also `composer.json` and PHPUnit, so the
     required **Backend tests** CI check runs real tests from then on.
3. **Public pages:** `render.php` partials, `messages.php`, `messages-zh.php`, `message.php`
   with pagination (10 per page), styled with the existing `styles.css` plus a few new rules.
4. **Auth:** login, logout, session, CSRF, rate limiting.
5. **Admin CRUD:** dashboard, editor with Quill (divider, centered text, and tables),
   `sanitize.php` with HTML Purifier on save, draft and preview, the in-browser copy of
   unsaved work, and delete with confirmation.
6. **Blogger import:** script plus a dry run against the public feed now, and against the
   real export once we have it. Its tests use a few real posts to check pairing and the Word
   cleanup.
7. **Soft launch:** open the one PR into `main`, and merge it once it's approved (after
   confirming). The static pages have no nav links yet, so visitors won't find the new pages.
   Run the one-time server setup with the pastor's account, import the posts as untitled
   drafts, add their titles in the editor and publish them, and set up backups. We test on
   the live site first with that account, then delete any test posts.
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
