# FACT.AI website

Three-language (EN default, HU, RO) static website. The site is built from `content/` by `build.py`
and deployed to GitHub Pages automatically on every push to `main` (see `.github/workflows/pages.yml`).

## Edit the News
Open `content/news.json` on GitHub, click the pencil icon, add an item, commit. The site updates in about a minute.
Each item needs: `id`, `date` (YYYY-MM-DD), `title` and `summary` in `en`, `hu`, `ro`.
Optional: `body`, `quote`, `image`, `link`. A missing language falls back to English.

## Other content
- `content/site.json`: page texts in 3 languages, partners, contact e-mail (`config.contact_email`).
- `static/img/eu-emblem.png`, `static/img/partners/ami.png`, `static/img/partners/ffp.png`: add the official logos here, the placeholders disappear automatically.

## Local build
`python3 build.py` writes the site to `site/`.
