# CAHOB Website

This site has English and Traditional Chinese versions of each page (e.g.
`index.html` / `index-zh.html`, `who-we-are.html` / `who-we-are-zh.html`,
`faith-and-vision.html` / `faith-and-vision-zh.html`).

Whenever a content or structural change is requested, apply the same update to
both the English and Chinese version of the page.

The paragraph text on the Faith and Vision and Who We Are pages is not in the
HTML — it's loaded at runtime from `content/*.txt` files (see `script.js`).
Edit those `.txt` files, not the HTML, to change that text.

Merging to `main` automatically deploys live to cahob.org (see
`.github/workflows/deploy.yml`). Don't merge or push to `main` without
confirming with the user first.

## Dev cycle

1. Branch off the latest `main` (`git fetch && git switch -c <branch> origin/main`).
2. Run the site locally with `make up` (http://localhost:8000) and stop it with `make down`.
3. Open a PR into `main`. The description has a short **Summary** and a **Tests** checklist
   of what was checked (e.g. `- [x] Pages render locally in EN and ZH`).
4. Merging needs one approval and a passing **Backend tests** check. Repo admins can bypass.

Backend tests run `composer test` in CI once a `composer.json` exists; until then the check
passes trivially.
