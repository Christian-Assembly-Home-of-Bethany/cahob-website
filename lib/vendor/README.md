# Vendored libraries

These are third-party libraries committed as-is, because the site deploys by copying the repo
over FTP with no build step (`composer install` never runs on the server).

## HTML Purifier

- **Version:** 4.19.1 (see `htmlpurifier/VERSION`)
- **Source:** https://github.com/ezyang/htmlpurifier (the `library/` folder from the release,
  installed with `composer require ezyang/htmlpurifier`)
- **License:** LGPL 2.1 (`htmlpurifier/LICENSE`)
- **Used by:** `lib/sanitize.php`, which cleans message HTML before it's saved

Don't edit these files. To update, install the new release in a scratch folder with
Composer, replace `htmlpurifier/library/`, `LICENSE`, and `VERSION` with the new copies, and
run `composer test`.
