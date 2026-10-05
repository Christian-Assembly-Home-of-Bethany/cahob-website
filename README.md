# cahob-website

Repository for the [cahob.org](https://www.cahob.org) website — plain HTML/CSS/JS, no build step.

## How it deploys

Merging a PR into `main` automatically uploads the site to cahob.org via FTP
(see `.github/workflows/deploy.yml`). No manual steps needed.

## Editing page text

The paragraphs on **Our Faith and Vision** and **Who We Are** live in plain
text files under [`content/`](content/):

- `content/faith-and-vision.txt`
- `content/who-we-are.txt`

Edit the file, separate paragraphs with one blank line, open a PR. Done.
Details in [`content/README.md`](content/README.md).

## Everything else

- Pages: `index.html`, `faith-and-vision.html`, `who-we-are.html`
- Styles: `styles.css` · Behavior (nav, animations, content loading): `script.js`
- Images: `images/` — compress photos to ≤500 KB before adding

## Messages (PHP)

The Messages section is a small PHP + SQLite app (see
[`plans/messages-cms.md`](plans/messages-cms.md)). Shared PHP code lives in `lib/`,
which the server blocks from the web. The database and the admin password live on the server
in `~/cahob-data/`, outside the web root, so deploys never touch them.

## Previewing locally

```
make up      # http://localhost:8000, with PHP if installed (on Windows, run from Git Bash)
make down    # stop it
```

The first `make up` creates `dev-data/config.php`, a local config with its own throwaway
database. `make seed` fills it with sample messages, and `make dev-password` sets the password
for the local admin login at http://localhost:8000/admin/ (username `pastor`). Without PHP it
falls back to Python, and only the static pages work. (Opening the HTML files directly won't
load the `content/` text files.)

To set the live admin password, run `make password` and paste the printed hash into
`~/cahob-data/config.php` on the server.

## Importing the old Blogger posts

`make import-preview` reads the public feed of johannavoice.blogspot.com and lists the messages
it would create, pairing each day's English and Chinese posts into one message. `make import`
imports them into the local database, replacing what's there (`make seed` restores the sample
messages). For the live site, import into a new file and upload it to `~/cahob-data/`:

```
php lib/tools/import_blogger.php --db=dev-data/live-import.sqlite
```

## Tests

PHP 8.2+ and Composer are needed. Run `composer install` once, then `make test` (or
`composer test`). CI runs the same tests on every PR.
