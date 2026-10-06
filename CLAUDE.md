# CAHOB Website

This site has English and Traditional Chinese versions of each page (e.g.
`index.html` / `index-zh.html`, `who-we-are.html` / `who-we-are-zh.html`,
`faith-and-vision.html` / `faith-and-vision-zh.html`).

Whenever a content or structural change is requested, apply the same update to
both the English and Chinese version of the page.

The paragraph text on the Faith and Vision and Who We Are pages is not in the
HTML — it's loaded at runtime from `content/*.txt` files (see `script.js`).
Edit those `.txt` files, not the HTML, to change that text.

The Messages section (`messages.php`, `message.php`, `admin/`) is a PHP + SQLite app; see
`plans/messages-cms.md`. Visitors use clean URLs (`/messages/`, `/messages-zh/<slug>`): the
`messages/` and `messages-zh/` folders route to those PHP files, and the old `.php` addresses
301-redirect to them. Build links with `list_url()` / `message_url()`, never by hand. The
Messages pages draw the navbar and footer from `lib/render.php`, so a nav change goes in the
six `.html` files **and** `render.php`. All of the section's interface text lives in
`UI_TEXT` / `ADMIN_TEXT` in `lib/render.php`, and every key needs both English and Chinese.

Merging to `main` automatically deploys live to cahob.org (see
`.github/workflows/deploy.yml`). Don't merge or push to `main` without
confirming with the user first.

## Dev cycle

1. Branch off the latest `main` (`git fetch && git switch -c <branch> origin/main`).
2. Run the site locally with `make up` (http://localhost:8000) and stop it with `make down`.
3. Open a PR into `main`. The description has a short **Summary** and a **Tests** checklist
   of what was checked (e.g. `- [x] Pages render locally in EN and ZH`).
4. Merging needs one approval and a passing **Backend tests** check. Repo admins can bypass.

Every change is a new commit, so reviewers can see exactly what changed since they last
looked. Never amend, squash, rebase, or force-push a branch. To bring a branch up to date
(e.g. a stacked PR after the one below it merges), merge `main` into it.

Backend tests run `composer test` in CI (PHPUnit, in `tests/`). Run them locally with
`make test` after `composer install`.
