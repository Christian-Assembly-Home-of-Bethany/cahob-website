# Plan: "Messages" tab with a small PHP + SQLite CMS

## Goal

Add a **Messages / 信息** tab where the pastor can log in, write, and publish messages,
replacing the current Blogger workflow. Visitors see a paginated list of messages and a
page for each one. Everything stays on the existing cPanel host.

## Feasibility

This is doable on the current setup:

- **cPanel runs PHP.** Nearly every cPanel host ships PHP 8.x with `pdo_sqlite` enabled. No
  MySQL database setup is needed.
- **Deploys still work.** `.github/workflows/deploy.yml` already FTPs the whole repo, so new
  `.php` files go up the same way. The data that changes at runtime (the database and uploaded
  images) lives on the server only and is never in git, so a deploy can't overwrite it.
- **The static pages stay as they are.** Only the new messages pages and the admin area use PHP.
  The other `.html` pages just get a new nav link.

**Check before starting:** in cPanel → *MultiPHP Manager*, confirm the domain uses PHP ≥ 8.1. In
*Select PHP Version → Extensions*, confirm `pdo_sqlite`, `fileinfo`, and `mbstring` are on.

## Build vs. adopt

| Option | Pros | Cons |
|---|---|---|
| **Custom PHP + SQLite (recommended)** | Matches the site's look exactly, bilingual nav works the same way, about 600 lines we fully understand, no plugin updates to keep up with | We own the security basics (auth, CSRF, sanitizing) |
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
│   └── upload.php        image upload endpoint for the editor
├── lib/                  not web-accessible (.htaccess: Require all denied)
│   ├── bootstrap.php     loads config, opens PDO, starts session
│   ├── db.php            queries + schema migration
│   ├── auth.php          login, session, CSRF helpers
│   ├── render.php        shared header/nav/footer partials (en/zh)
│   └── vendor/htmlpurifier/   vendored HTML sanitizer
└── uploads/              server-only, created on host, excluded from git and deploy

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
```

The tables are created on first run (`CREATE TABLE IF NOT EXISTS`), so there is no separate
install step.

### Bilingual handling

The pastor writes each message in both English and Chinese, so every post has an English
section and a Chinese section. Publishing requires a title in at least one language. The slug
comes from the English title (or the date if there isn't one).

- `messages.php` lists posts using the English titles. `messages-zh.php` uses the Chinese
  titles.
- `message.php?lang=en` shows the English section with a **中文** link to the same post in
  Chinese, and `lang=zh` does the reverse. The site's existing language switch already works
  this way.
- If one section is empty (e.g. an imported post), the page shows the other section, so no
  post ever looks blank.

This keeps one post per message and fits the CLAUDE.md rule that both language versions get
updated together.

### Shared chrome

The static pages repeat the navbar and footer in every file. The PHP pages use
`lib/render.php::header($lang, $active)` / `footer($lang)` instead, copied from the current
markup and using the same `styles.css` and `script.js`. If the nav changes later, update the
six `.html` files **and** `render.php`.

## Admin experience (the Blogger replacement)

- **Login** at `cahob.org/admin/`. There is one admin account, with a username and a bcrypt
  hash in `config.php`. A second account can be added later as another entry in the config.
- **Dashboard** shows posts newest first, marked Draft or Published, with Edit, View, and Delete
  links and a **New message** button.
- **Editor** has a publish date (defaults to now), then two sections, **English** and
  **中文**, each with its own title and a WYSIWYG body. On a phone the sections stack; on
  wider screens they sit side by side so he can compare them. The editor is Quill, loaded from
  a CDN, with headings, bold/italic, lists, links, blockquote, and images. The buttons are **Save draft**, **Publish**, and **Preview** (opens
  the public page for the draft, visible only to the logged-in admin).
- **Images** go to `/uploads/YYYY/MM/<random>.jpg`. The server checks the real file type with
  `finfo` (jpeg/png/webp only), limits size to 5 MB, and resizes large photos with GD to a
  maximum width of 1600px.
- The editor works on a phone, so he can post from his phone.

## Security

- `password_hash` / `password_verify` (bcrypt). The pastor never types the password into
  the repo. We generate the hash with `php -r 'echo password_hash("...", PASSWORD_DEFAULT);'`
  and paste it into the server-side `config.php`.
- Session cookies use `Secure`, `HttpOnly`, and `SameSite=Lax`. The session ID is regenerated
  on login. Sessions time out after 2 hours of inactivity.
- Every admin POST checks a CSRF token.
- Failed logins are rate-limited: 5 failures within 15 minutes locks login for 15 minutes,
  tracked in a small `login_attempts` table.
- HTML from the editor is cleaned with **HTML Purifier** using an allowlist (p, h2–h4, strong,
  em, ul/ol/li, a[href], blockquote, img[src from /uploads or https]). This blocks stored XSS
  even if the editor is bypassed.
- Every DB access uses prepared statements. Every non-HTML field is escaped on output with
  `htmlspecialchars`.
- `.htaccess` forces HTTPS for `/admin/`, denies `/lib/`, and turns off PHP execution in
  `/uploads/`.

## Deploy changes

Update `.github/workflows/deploy.yml` excludes:

```yaml
exclude: |
  .git*
  .git*/**
  .github/**
  README.md
  CLAUDE.md
  plans/**
  uploads/**
```

FTP-Deploy-Action only deletes files it uploaded itself, so `uploads/` and anything above the
web root are safe. Add `uploads/` to `.gitignore` as well.

**One-time server setup** (done by hand in cPanel File Manager):

1. Create `~/cahob-data/` (outside `public_html`) with `config.php` from
   `lib/config.example.php`.
2. Create `public_html/uploads/` and make it writable by PHP.
3. Visit `/admin/` once so the app creates the SQLite file and tables.
4. Back up `~/cahob-data/messages.sqlite` and `public_html/uploads/` with a cPanel cron job
   (e.g. a nightly copy to `~/backups/`) or cPanel's own backups. This is the only state that
   isn't in git.

## Migrating existing Blogger posts

Write a one-off CLI script, `lib/tools/import_blogger.php`. It reads the Blogger export (Blogger →
Settings → Back up content → `.xml` Atom feed) and inserts each post with its original date.
Blogger posts aren't split by language, so the script puts the title and body into the Chinese
section if the title contains Chinese characters, and into the English section otherwise. The
pastor can split any post into both sections later in the editor. Bodies are sanitized the
same way new posts are. Images stay hosted on Blogger's CDN at
first. Copying them into `/uploads` can be a later step if needed. Run it on the server through
cPanel Terminal, or locally and then upload the resulting `.sqlite`.

## Site integration

Add **Messages** / **信息** to the navbar and footer links on all six `.html` pages, plus
`render.php`.

## Local development

```sh
php -S localhost:8000          # from repo root; needs php + php-sqlite locally
```

Use a `config.php` path override (`CAHOB_CONFIG` env var) so local dev points at a throwaway
database in the repo's ignored `dev-data/` folder.

## Implementation steps

1. **Server check:** confirm PHP version and extensions in cPanel. Create `~/cahob-data/`.
2. **Skeleton:** `lib/bootstrap.php`, `db.php` with schema, `config.example.php`, `.htaccess`
   rules, gitignore and deploy excludes.
3. **Public pages:** `render.php` partials, `messages.php`, `messages-zh.php`, `message.php`
   with pagination (10 per page), styled with the existing `styles.css` plus a few new rules.
4. **Auth:** login, logout, session, CSRF, rate limiting.
5. **Admin CRUD:** dashboard, editor with Quill, HTML Purifier on save, draft and preview,
   delete with confirmation.
6. **Image uploads:** validation, resize, `/uploads` storage.
7. **Nav links:** add Messages / 信息 to all six static pages.
8. **Blogger import:** script plus a dry run against a real export.
9. **Deploy to staging:** a subfolder or test branch with `server-dir: ./staging/` so the
   pastor can try it before it goes on `main`.
10. **Go live:** merge to `main` (after confirming), run the one-time server setup, import the
    posts, hand the pastor his login, and set up backups.

## Out of scope for v1

These are left out on purpose and can be added later if needed: tags/labels, comments,
scheduled posts, email subscribers, RSS, latest messages on the home page, redirects from old
Blogger URLs, and multiple authors.
