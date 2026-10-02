#!/usr/bin/env python3
"""Render design previews from a live-page snapshot (scripts/design/snapshot.py), without touching the live site.

    python3 scripts/design/preview.py SNAPSHOT OUTDIR [--theme DIR] [--pages a,b] [--sizes 1366x768,390x844] [--full]

Without --theme the pages render exactly as saved (the "before"). With --theme, every child-theme file the
page loads is served from DIR instead (the "after"), and the stylesheets a phase adds are linked in the
same order functions.php enqueues them. Ads, analytics and other third-party requests are blocked in both,
so ad slots show empty. Google Fonts load normally.
"""
import argparse, hashlib, mimetypes, os, re, sys, urllib.parse, urllib.request
from playwright.sync_api import sync_playwright

THEME_PATH = "/wp-content/themes/generatepress-child/"
SIZES = "390x844,768x1024,1280x800,1366x768,1440x900,1920x1080"
NEW_AFTER_TOKENS = ["layout.css", "components.css"]


def theme_link(name, theme):
    ver = int(os.path.getmtime(os.path.join(theme, name)))
    handle = {"layout.css": "gpa-layout", "components.css": "gpa-components", "calc-theme.css": "gpa-calc-theme"}[name]
    return (f"<link rel='stylesheet' id='{handle}-css' href='https://gpacalculator.net{THEME_PATH}{name}?ver={ver}' "
            "media='all' />")


FONT_UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"


def font_file(url, cache):
    """Google Fonts CSS and font files, fetched once with Python and kept in a local cache (the browser's own
    connection through the sandbox proxy is unreliable for them)."""
    os.makedirs(cache, exist_ok=True)
    path = os.path.join(cache, hashlib.sha1(url.encode()).hexdigest())
    if not os.path.exists(path):
        req = urllib.request.Request(url, headers={"User-Agent": FONT_UA})
        with urllib.request.urlopen(req, timeout=30) as r, open(path + ".tmp", "wb") as f:
            f.write(r.read())
        os.replace(path + ".tmp", path)
    with open(path, "rb") as f:
        return f.read()


GP_TYPOGRAPHY = [r"body, button, input, select, textarea\{[^}]*\}", r"body\{line-height:[^}]*\}",
                 r"h1\{font-family[^}]*\}", r"h2\{font-family[^}]*\}", r"h3\{font-family[^}]*\}"]


def own_title(html):
    m = re.findall(r'"@type":"ListItem","position":\d+,"name":"([^"]*)"', html)
    return m[-1] if m else None


def mirror_markup(html, slug, pages_dir, theme):
    """Mirror the phase 4 markup that functions.php prints: logo badge + wordmark, the hero breadcrumb,
    and the hero class on blog posts. Only when the theme being previewed has those functions."""
    php = open(os.path.join(theme, "functions.php"), encoding="utf-8").read()
    if "gpa_logo_badge_svg" in php:
        badge = "".join(re.findall(r"'([^']*)'", re.search(r"function gpa_logo_badge_svg\(\) \{(.*?)\n\}", php, re.S).group(1)))
        html = re.sub(r'<div class="site-logo">.*?</div>',
                      f'<div class="site-logo"><a href="https://gpacalculator.net/" rel="home" aria-label="GPA Calculator">{badge}</a></div>',
                      html, count=1, flags=re.S)
        html = re.sub(r'(<p class="main-title">\s*<a [^>]*rel="home"[^>]*>)\s*GPA\b', r'\1<span class="gpa-wordmark-accent">GPA</span>', html, count=1)
    if "function gpa_faq_accordion" in php:
        js = re.search(r"function gpa_faq_accordion\(\).*?(<script>.*?</script>)", php, re.S).group(1)
        html = html.replace("</body>", js + "</body>", 1)
    body = re.search(r'<body[^>]*class="([^"]*)"', html)
    classes = body.group(1).split() if body else []
    if "gpa_hero_breadcrumb" in php and slug != "home" and "single-colleges" not in classes:
        is_post = "single-post" in classes
        if is_post and "gpa-hero-band" not in classes:
            html = html.replace(body.group(0), body.group(0)[:-1] + ' gpa-hero-band"', 1)
        if is_post or "gpa-hero-band" in classes or "page-template-template-calculator" in classes:
            parts = slug.split("__")
            crumbs = ['<li class="gpa-breadcrumb__item"><a class="gpa-breadcrumb__link" href="https://gpacalculator.net/">Home</a></li>']
            if not is_post:
                for i in range(1, len(parts)):
                    parent = "__".join(parts[:i])
                    f = os.path.join(pages_dir, parent + ".html")
                    name = own_title(open(f, encoding="utf-8").read()) if os.path.exists(f) else parts[i - 1].replace("-", " ").title()
                    crumbs.append(f'<li class="gpa-breadcrumb__item"><a class="gpa-breadcrumb__link" href="https://gpacalculator.net/{parent.replace("__", "/")}/">{name}</a></li>')
            crumbs.append(f'<li class="gpa-breadcrumb__item"><span class="gpa-breadcrumb__current" aria-current="page">{own_title(html) or ""}</span></li>')
            nav = '<nav class="gpa-breadcrumb" aria-label="Breadcrumb"><ol class="gpa-breadcrumb__list">' + "".join(crumbs) + "</ol></nav>"
            html = re.sub(r'(<header class="entry-header"[^>]*>)', lambda m: m.group(1) + nav, html, count=1)
    return html


def transform(html, theme, settings=False, slug=None, pages_dir=None):
    """Mirror the enqueue changes in functions.php for pages saved before they were deployed. With settings=True,
    also mirror the phase 3 site settings: Simple CSS emptied, GeneratePress Customizer typography reset."""
    if not theme:
        return html
    if slug and pages_dir:
        html = mirror_markup(html, slug, pages_dir, theme)
    if settings:
        html = re.sub(r"<link[^>]+so-css-generatepress\.css[^>]*>", "", html)
        m = re.search(r"(<style id=.generate-style-inline-css.>)(.*?)(</style>)", html, re.S)
        if m:
            css = m.group(2)
            for rx in GP_TYPOGRAPHY:
                css = re.sub(rx, "", css)
            html = html[:m.start(2)] + css + html[m.end(2):]
    links = "".join(theme_link(n, theme) for n in NEW_AFTER_TOKENS
                    if os.path.exists(os.path.join(theme, n)) and f"{THEME_PATH}{n}" not in html)
    if links:
        html = re.sub(r"(<link[^>]+gpa-design-tokens\.css[^>]*>)", lambda m: m.group(1) + links, html, count=1)
    if os.path.exists(os.path.join(theme, "calc-theme.css")) and "calc-theme.css" not in html:
        calc = list(re.finditer(r"<link[^>]+/calc-assets/[^>]+\.css[^>]*>", html))
        if calc:
            i = calc[-1].end()
            html = html[:i] + theme_link("calc-theme.css", theme) + html[i:]
    return html


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("snapshot"); ap.add_argument("out")
    ap.add_argument("--theme"); ap.add_argument("--pages"); ap.add_argument("--sizes", default=SIZES)
    ap.add_argument("--full", action="store_true", help="full-page screenshots instead of the first screen")
    ap.add_argument("--settings", action="store_true",
                    help="also preview the phase 3 settings changes (Simple CSS emptied, Customizer typography reset)")
    ap.add_argument("--eval", help="JavaScript expression to evaluate on each page; its result is printed")
    a = ap.parse_args()
    pages_dir = os.path.join(a.snapshot, "pages")
    slugs = a.pages.split(",") if a.pages else sorted(f[:-5] for f in os.listdir(pages_dir) if f.endswith(".html"))
    os.makedirs(a.out, exist_ok=True)
    proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")

    def handle(route, slug):
        u = urllib.parse.urlsplit(route.request.url)
        if u.netloc in ("fonts.googleapis.com", "fonts.gstatic.com"):
            body = font_file(route.request.url, os.path.join(a.snapshot, ".font-cache"))
            ctype = "text/css" if u.netloc == "fonts.googleapis.com" else "font/woff2"
            return route.fulfill(status=200, content_type=ctype, body=body,
                                 headers={"Access-Control-Allow-Origin": "*"})
        if not u.netloc.endswith("gpacalculator.net"):
            return route.abort()
        path = urllib.parse.unquote(u.path)
        if route.request.resource_type == "document":
            with open(os.path.join(pages_dir, slug + ".html"), encoding="utf-8") as f:
                return route.fulfill(status=200, content_type="text/html; charset=utf-8", body=transform(f.read(), a.theme, a.settings, slug, pages_dir))
        local = None
        if a.theme and path.startswith(THEME_PATH):
            cand = os.path.join(a.theme, path[len(THEME_PATH):])
            if os.path.isfile(cand):
                local = cand
        if not local:
            cand = os.path.join(a.snapshot, "site", path.lstrip("/"))
            if os.path.isfile(cand):
                local = cand
        if not local:
            return route.fulfill(status=404, body="")
        ctype = mimetypes.guess_type(local)[0] or "application/octet-stream"
        with open(local, "rb") as f:
            return route.fulfill(status=200, content_type=ctype, body=f.read())

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get("CHROMIUM", "/opt/pw-browsers/chromium"),
                                    proxy={"server": proxy} if proxy else None)
        for slug in slugs:
            for size in a.sizes.split(","):
                w, h = map(int, size.split("x"))
                ctx = browser.new_context(viewport={"width": w, "height": h}, ignore_https_errors=True,
                                          device_scale_factor=1, is_mobile=w < 768, has_touch=w < 768)
                page = ctx.new_page()
                def safe(route, _request=None, s=slug):
                    try:
                        handle(route, s)
                    except Exception as e:  # noqa: BLE001
                        print("route error", route.request.url[:90], e, file=sys.stderr)
                        route.abort()
                page.route("**/*", safe)
                page.goto("https://gpacalculator.net/" + ("" if slug == "home" else slug.replace("__", "/") + "/"),
                          wait_until="domcontentloaded", timeout=60000)
                try:
                    page.wait_for_load_state("load", timeout=15000)
                except Exception:  # noqa: BLE001 - a slow third-party file shouldn't stop the run
                    pass
                page.wait_for_timeout(1500)
                shot = os.path.join(a.out, f"{slug}-{size}.png")
                page.screenshot(path=shot, full_page=a.full)
                hscroll = page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth")
                print(f"{shot}{'  HORIZONTAL SCROLL' if hscroll else ''}")
                if a.eval:
                    print("  ", page.evaluate(a.eval))
                ctx.close()
        browser.close()


if __name__ == "__main__":
    sys.exit(main())
