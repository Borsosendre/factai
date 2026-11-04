# FACT.AI website

Three-language (EN default, HU, RO) static website with a PHP dashboard at `/admin` for news and images.

- `content/site.json`: page texts in 3 languages, partners, contact e-mail (`config.contact_email`).
- `content/news.json`: seed news. On the live server the dashboard owns this file.
- `static/`: CSS, fonts, logos. Add `static/img/eu-emblem.png`, `static/img/partners/ami.png`, `static/img/partners/ffp.png` and the placeholders disappear on the next build.
- `admin/`: dashboard (PHP 7.4+, gd, mbstring). `build.py` builds the site into `site/`.
- `deploy/DEPLOY.md`: server installation (Hungarian); `deploy/deploy.sh`: build and copy without touching live news, images or users.
