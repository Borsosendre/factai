#!/usr/bin/env python3
"""FACT.AI website builder.

Reads content/site.json (UI texts, 3 languages) and content/news.json (news),
and writes the deployable static site into ./site/ :

    site/index.html        language redirect (default: English)
    site/en/index.html
    site/hu/index.html
    site/ro/index.html
    site/assets/...        css, js, fonts, images

Run:  python3 build.py
The result needs no server-side code: upload the contents of ./site/ to any web
space (for example as a /factai/ subfolder). All paths are relative.
"""
import html, json, shutil, glob, os, datetime
from urllib.parse import urlparse
from pathlib import Path

ROOT = Path(__file__).parent
OUT = ROOT / os.environ.get("FA_OUT", "site")
IDX = "index.html" if os.environ.get("FA_INDEX") else ""
LANGS = ["en", "hu", "ro"]
e = html.escape

site = json.loads((ROOT / "content/site.json").read_text(encoding="utf-8"))
news = json.loads((ROOT / "content/news.json").read_text(encoding="utf-8"))["items"]
cfg = site["config"]

MONTHS = {
    "en": ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    "hu": ["jan.", "febr.", "márc.", "ápr.", "máj.", "jún.", "júl.", "aug.", "szept.", "okt.", "nov.", "dec."],
    "ro": ["ian.", "feb.", "mar.", "apr.", "mai", "iun.", "iul.", "aug.", "sept.", "oct.", "nov.", "dec."],
}


def fmt_date(iso, lang):
    d = datetime.date.fromisoformat(iso)
    m = MONTHS[lang][d.month - 1]
    if lang == "hu":
        return f"{d.year}. {m} {d.day}."
    return f"{d.day} {m} {d.year}"


def tr(obj, lang):
    """Pick a language from {en,hu,ro}; fall back to English."""
    if isinstance(obj, dict):
        return obj.get(lang) or obj.get("en") or ""
    return obj or ""


def find_img(stem):
    for ext in ("svg", "png", "jpg", "jpeg", "webp"):
        p = ROOT / "static" / "img" / f"{stem}.{ext}"
        if p.exists():
            return f"{stem}.{ext}"
    return None


CHECK = ('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" '
         'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>')


def paragraphs(text):
    return "".join(f"<p>{e(p.strip())}</p>" for p in text.split("\n\n") if p.strip())


def news_item(it, lang, t):
    title = tr(it["title"], lang)
    out = [f'<article class="news-item" id="news-{e(it["id"])}">',
           f'<time datetime="{e(it["date"])}">{e(fmt_date(it["date"], lang))}</time><div class="news-body">',
           f"<h3>{e(title)}</h3><p>{e(tr(it['summary'], lang))}</p>"]
    q = it.get("quote")
    if q:
        out.append(f'<blockquote class="human"><p>{e(tr(q["text"], lang))}</p><footer>{e(q.get("who", ""))}</footer></blockquote>')
    im = it.get("image")
    if im:
        out.append(f'<figure><img src="../assets/img/news/{e(im["src"])}" alt="{e(tr(im.get("alt", ""), lang))}" loading="lazy"></figure>')
    if it.get("body"):
        out.append(f'<details class="more"><summary>{e(t["news_read"])}</summary>{paragraphs(tr(it["body"], lang))}</details>')
    lk = it.get("link")
    if lk:
        out.append(f'<p><a class="textlink" href="{e(lk["url"])}" rel="noopener">{e(tr(lk["label"], lang))}</a></p>')
    out.append("</div></article>")
    return "".join(out)


def partner_card(p, t):
    info = t["partners"][p["id"]]
    img = find_img(f"partners/{p['id']}")
    if img:
        plate = f'<div class="plate"><img src="../assets/img/{img}" alt="{e(info["name"])}"></div>'
    else:
        plate = (f'<div class="plate plate-empty"><span class="abbr">{e(p["abbr"])}</span>'
                 f'<span class="tiny">{e(t["logo_missing"])}</span></div>')
    site_link = ""
    if p.get("url"):
        host = urlparse(p["url"]).netloc.removeprefix("www.")
        site_link = f'<p class="site"><a href="{e(p["url"])}" rel="noopener">{e(host)}</a></p>'
    return f'<li class="partner">{plate}<h3>{e(info["name"])}</h3><p>{e(info["role"])}</p>{site_link}</li>'


def page(lang):
    t = site[lang]
    items = sorted(news, key=lambda x: x["date"], reverse=True)
    shown, older = items[:5], items[5:]
    news_html = "".join(news_item(i, lang, t) for i in shown) or f'<p class="muted">{e(t["news_empty"])}</p>'
    if older:
        news_html += (f'<details class="older"><summary>{e(t["news_more"])} ({len(older)})</summary>'
                      + "".join(news_item(i, lang, t) for i in older) + "</details>")

    base = cfg.get("base_url", "").rstrip("/")
    alt = ""
    if base:
        alt = "".join(f'<link rel="alternate" hreflang="{l}" href="{base}/{l}/">' for l in LANGS)
        alt += f'<link rel="alternate" hreflang="x-default" href="{base}/en/"><link rel="canonical" href="{base}/{lang}/">'

    cur = ' aria-current="true"'
    langsw = "".join(
        f'<a href="../{l}/{IDX}" lang="{l}" hreflang="{l}" data-setlang="{l}" title="{e(site[l]["lang_name"])}"'
        f'{cur if l == lang else ""}>{l.upper()}</a>' for l in LANGS)

    nav = "".join(f'<a href="#{k}">{e(v)}</a>' for k, v in t["nav"].items())

    about_list = "".join(f"<li>{e(x)}</li>" for x in t["about_list"])
    steps = "".join(f'<li><h3>{e(s["t"])}</h3><p>{e(s["p"])}</p></li>' for s in t["steps"])
    partners = "".join(partner_card(p, t) for p in cfg["partners"])

    contact = ""
    if cfg.get("contact_email"):
        m = e(cfg["contact_email"])
        contact = f'<p class="contact">{e(t["join_contact"])} <a href="mailto:{m}">{m}</a></p>'

    eu = find_img("eu-emblem")
    eu_html = (f'<div class="plate foot-plate"><img class="eu" src="../assets/img/{eu}" alt="European Union"></div>'
               if eu else f'<div class="plate foot-plate plate-empty"><span class="tiny">{e(t["eu_missing"])}</span></div>')
    foot_partners = ""
    for p in cfg["partners"]:
        img = find_img(f"partners/{p['id']}")
        nm = t["partners"][p["id"]]["name"]
        if img:
            foot_partners += f'<div class="plate foot-plate"><img src="../assets/img/{img}" alt="{e(nm)}"></div>'
        else:
            foot_partners += f'<div class="plate foot-plate plate-empty"><span class="abbr">{e(p["abbr"])}</span></div>'

    return f"""<!doctype html>
<html lang="{lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>{e(t["meta_title"])}</title>
<meta name="description" content="{e(t["meta_desc"])}">
<meta property="og:title" content="{e(t["meta_title"])}">
<meta property="og:description" content="{e(t["meta_desc"])}">
<meta property="og:type" content="website">
<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">
{alt}
<link rel="preload" href="../assets/fonts/bricolage-grotesque-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="../assets/css/fonts.css">
<link rel="stylesheet" href="../assets/css/site.css">
<script src="../assets/js/site.js" defer></script>
</head>
<body>
<a class="skip" href="#main">{e(t["skip"])}</a>
<header class="nav">
  <div class="nav-in">
    <a class="brand" href="#top" aria-label="FACT.AI">
      <picture><source media="(prefers-color-scheme: dark)" srcset="../assets/img/factai-logo-dark.svg">
      <img src="../assets/img/factai-logo-light.svg" alt="FACT.AI" width="156" height="38"></picture>
    </a>
    <nav class="menu" aria-label="Main">{nav}</nav>
    <div class="langs" role="group" aria-label="{e(t["lang_label"])}">{langsw}</div>
  </div>
</header>
<main id="main">
<section class="hero" id="top">
  <div class="wrap">
    <h1 class="voices">
      <span class="bubble a" aria-hidden="true"></span>
      <span class="bubble b" aria-hidden="true"></span>
      <span class="lens" aria-hidden="true">{CHECK}</span>
      <span class="t a">{e(t["hero_a"])}</span>
      <span class="t b">{e(t["hero_b"])}</span>
    </h1>
    <p class="lead">{e(t["hero_lead"])}</p>
    <p class="note">{CHECK}<span>{e(t["hero_note"])}</span></p>
    <p class="cta"><a class="btn btn-primary" href="#news">{e(t["cta_news"])}</a><a class="btn btn-ghost" href="#join">{e(t["cta_join"])}</a></p>
  </div>
</section>

<section class="sec" id="about">
  <div class="wrap about">
    <h2>{e(t["about_h"])}</h2>
    <div class="prose">
      <p>{e(t["about_p1"])}</p>
      <p>{e(t["about_p2"])}</p>
      <ul class="lenslist">{about_list}</ul>
    </div>
  </div>
</section>

<section class="sec band" id="how">
  <div class="wrap">
    <h2>{e(t["how_h"])}</h2>
    <ol class="steps">{steps}</ol>
  </div>
</section>

<section class="sec" id="news">
  <div class="wrap">
    <h2>{e(t["news_h"])}</h2>
    <div class="news">{news_html}</div>
  </div>
</section>

<section class="sec sec-tight" id="partners">
  <div class="wrap">
    <h2>{e(t["partners_h"])}</h2>
    <ul class="partners">{partners}</ul>
  </div>
</section>

<section class="sec sec-tight" id="join">
  <div class="wrap">
    <div class="join">
      <p class="badge">{e(t["join_badge"])}</p>
      <h2>{e(t["join_h"])}</h2>
      <p>{e(t["join_p"])}</p>
      {contact}
    </div>
  </div>
</section>
</main>

<footer class="foot">
  <div class="wrap">
    <div class="foot-logos">
      <div class="foot-funders">{eu_html}{foot_partners}</div>
      <a class="foot-brand" href="#top" aria-label="FACT.AI"><img src="../assets/img/factai-logo-dark.svg" alt="FACT.AI" width="156" height="38"></a>
    </div>
    <p class="disclaimer">{e(t["disclaimer"])}</p>
  </div>
</footer>
</body>
</html>
"""


ROOT_PAGE = """<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FACT.AI</title>
<meta http-equiv="refresh" content="0; url=en/">
<script>
try { var l = localStorage.getItem('fa-lang'); if (l === 'hu' || l === 'ro' || l === 'en') location.replace(l + '/'); } catch (e) {}
</script>
</head>
<body>
<p><a href="en/">English</a> · <a href="hu/">Magyar</a> · <a href="ro/">Română</a></p>
</body>
</html>
"""


def main():
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir()
    shutil.copytree(ROOT / "static", OUT / "assets")
    (OUT / "index.html").write_text(ROOT_PAGE, encoding="utf-8")
    for l in LANGS:
        (OUT / l).mkdir()
        (OUT / l / "index.html").write_text(page(l), encoding="utf-8")
    missing = [n for n in ("eu-emblem", "partners/ami", "partners/ffp") if not find_img(n)]
    print("Built:", ", ".join(LANGS), "| news items:", len(news))
    if missing:
        print("Placeholders still shown for:", ", ".join(missing))
    if not cfg.get("contact_email"):
        print("Note: config.contact_email is empty, so no contact line is shown.")


if __name__ == "__main__":
    main()
