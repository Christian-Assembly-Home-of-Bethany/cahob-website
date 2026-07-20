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

## Previewing locally

Run any static server from the repo root, e.g.:

```
python3 -m http.server 4173
```

then open http://localhost:4173. (Opening the HTML files directly won't load
the `content/` text files.)
