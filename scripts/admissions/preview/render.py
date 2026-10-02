#!/usr/bin/env python3
"""Before/after screenshots of /admissions/ pages, without touching the live site.

    python3 scripts/admissions/preview/render.py OUTDIR [--pages harvard,miami-university] [--sizes 390x844,1440x900]
        [--before] [--after] [--local http://localhost:8890] [--setup JS] [--eval JS] [--fragment]

"Before" is the live page as saved by the snapshot workflow (data/admissions/preview/snapshot, from
.github/workflows/admissions-snapshot.yml). "After" is the same saved page with its content area (and its title,
description and structured data) replaced by what a local WordPress renders from this checkout's child theme:
scripts/admissions/preview/wp/ has the stand-in parent theme that marks the content area, and compose_state.py
rebuilds the live college data for it. The header, footer and every other part of the page stay exactly as live.
Child-theme files are served from this checkout, the rest from the snapshot; a stylesheet or script the local page
loads from the child theme that the live page doesn't is added in the same place. Ads, analytics and other
third-party requests are blocked; Google Fonts come from a local cache.

Pages are given by their path under /admissions/ ("hub" is /admissions/ itself), or for another saved page by its
snapshot name (gpa-scale__3-8-gpa), which renders "before" only. Screenshots are full-page:
OUTDIR/<page>-<before|after>-<size>.png.
"""
import argparse
import hashlib
import mimetypes
import os
import re
import sys
import urllib.parse
import urllib.request

from playwright.sync_api import sync_playwright

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..")
SNAPSHOT = os.path.join(ROOT, "data", "admissions", "preview", "snapshot")
THEME = os.path.join(ROOT, "child-theme", "generatepress-child")
THEME_PATH = "/wp-content/themes/generatepress-child/"
SITE = "https://gpacalculator.net"
FONT_UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"
OPEN = '<div class="site-content" id="content">'


def font_file(url):
    cache = os.path.join(SNAPSHOT, ".font-cache")
    os.makedirs(cache, exist_ok=True)
    path = os.path.join(cache, hashlib.sha1(url.encode()).hexdigest())
    if not os.path.exists(path):
        req = urllib.request.Request(url, headers={"User-Agent": FONT_UA})
        with urllib.request.urlopen(req, timeout=30) as r, open(path + ".tmp", "wb") as f:
            f.write(r.read())
        os.replace(path + ".tmp", path)
    with open(path, "rb") as f:
        return f.read()


def local_get(local, path, data=None, headers=None):
    req = urllib.request.Request(local + path, data=data, headers=headers or {})
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    with opener.open(req, timeout=120) as r:
        return r.read().decode("utf-8"), r.headers.get("Content-Type", "text/html")


def to_live(text, local):
    return text.replace(local, SITE).replace(local.replace("/", "\\/"), SITE.replace("/", "\\/"))


def content_bounds(html):
    """The live page's content area: from the site-content opening tag to the two closing divs before the footer."""
    s = html.index(OPEN) + len(OPEN)
    e = html.index('<div class="site-footer">')
    e = html.rindex("</div>", s, e)
    e = html.rindex("</div>", s, e)
    return s, e


HEAD_PARTS = [r"<title>.*?</title>", r'<meta name="description"[^>]*>', r'<meta property="og:title"[^>]*>',
              r'<meta property="og:description"[^>]*>', r'<meta name="twitter:title"[^>]*>',
              r'<meta name="twitter:description"[^>]*>', r'<script type="application/ld\+json"[^>]*>.*?</script>']


def theme_file(tag):
    m = re.search(r"href=['\"]([^'\"?]+)", tag)
    return m.group(1).split(THEME_PATH, 1)[1] if m and THEME_PATH in m.group(1) else None


def after_page(saved, path, local):
    page, _ = local_get(local, path)
    page = to_live(page, local)
    body = page[page.index("<!--GPA-PREVIEW-CONTENT-START-->") + 32:page.index("<!--GPA-PREVIEW-CONTENT-END-->")]
    s, e = content_bounds(saved)
    html = saved[:s] + "\n" + body + "\n" + saved[e:]
    head = page[:page.index("</head>")]
    for rx in HEAD_PARTS:  # title, description and structured data as the local page has them (no Rank Math locally)
        mine = re.search(rx, head, re.S)
        if mine:
            html = re.sub(rx, lambda m: mine.group(0), html, count=1, flags=re.S)
    # Body classes: the live ones (the parent theme adds most) plus any the child theme adds locally
    mine = re.search(r'<body[^>]*class="([^"]*)"', page).group(1).split()
    live = re.search(r'<body[^>]*class="([^"]*)"', html)
    extra = [c for c in mine if c not in live.group(1).split()]
    if extra:
        html = html[:live.start(1)] + live.group(1) + " " + " ".join(extra) + html[live.end(1):]
    # Child-theme stylesheets: drop the ones the local page no longer loads (style.css comes from the parent theme),
    # add the ones it loads that the live page doesn't, after the last child-theme stylesheet they share
    links = re.compile(r"<link[^>]+rel=['\"]stylesheet['\"][^>]*>")
    local_links = [t for t in links.findall(head) if theme_file(t)]
    local_files = [theme_file(t) for t in local_links]
    for tag in links.findall(html[:html.index("</head>")]):
        f = theme_file(tag)
        if f and f != "style.css" and f not in local_files:
            html = html.replace(tag, "", 1)
    for i, tag in enumerate(local_links):
        if THEME_PATH + local_files[i] in html:
            continue
        anchor = None
        for prev in reversed(local_files[:i]):
            m = re.search(r"<link[^>]+" + re.escape(THEME_PATH + prev) + r"[^>]*>", html)
            if m:
                anchor = m
                break
        if anchor:
            html = html[:anchor.end()] + "\n" + tag + html[anchor.end():]
    # Footer: the child theme's scripts the live page doesn't load, and localized script data (nonces) from local
    foot = page[page.index("<!--GPA-PREVIEW-FOOTER-START-->"):page.index("<!--GPA-PREVIEW-FOOTER-END-->")]
    for tag in re.findall(r"<script[^>]+src=['\"][^'\"]*" + re.escape(THEME_PATH) + r"[^>]*></script>", foot):
        src = re.search(r"src=['\"]([^'\"?]+)", tag).group(1)
        if src not in html:
            html = html.replace("</body>", tag + "\n</body>", 1)
    for m in re.finditer(r"<script[^>]*id=['\"]([\w-]+-js-extra)['\"][^>]*>.*?</script>", page, re.S):
        html = re.sub(r"<script[^>]*id=['\"]" + re.escape(m.group(1)) + r"['\"][^>]*>.*?</script>",
                      lambda _m: m.group(0), html, count=1, flags=re.S)
    return html


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("out")
    ap.add_argument("--pages", default="hub,harvard,miami-university,lone-star-college-system,fairfax-university-of-america,pitt")
    ap.add_argument("--sizes", default="390x844,1440x900")
    ap.add_argument("--before", action="store_true")
    ap.add_argument("--after", action="store_true")
    ap.add_argument("--local", default="http://localhost:8890")
    ap.add_argument("--setup", help="JavaScript to run on each page before its screenshot (a promise is awaited), "
                                    "such as a search typed into the hub")
    ap.add_argument("--eval", help="JavaScript expression to evaluate on each page; its result is printed")
    ap.add_argument("--viewport", action="store_true", help="first screen only instead of the full page")
    a = ap.parse_args()
    kinds = [k for k in ("before", "after") if getattr(a, k)] or ["before", "after"]
    os.makedirs(a.out, exist_ok=True)
    proxy = os.environ.get("HTTPS_PROXY") or os.environ.get("https_proxy")

    def handle(route, page_html, kind):
        req = route.request
        u = urllib.parse.urlsplit(req.url)
        if u.netloc in ("fonts.googleapis.com", "fonts.gstatic.com"):
            ctype = "text/css" if u.netloc == "fonts.googleapis.com" else "font/woff2"
            return route.fulfill(status=200, content_type=ctype, body=font_file(req.url),
                                 headers={"Access-Control-Allow-Origin": "*"})
        if not u.netloc.endswith("gpacalculator.net"):
            return route.abort()
        path = urllib.parse.unquote(u.path)
        if req.resource_type == "document":
            return route.fulfill(status=200, content_type="text/html; charset=utf-8", body=page_html)
        if path == "/wp-admin/admin-ajax.php":
            body, ctype = local_get(a.local, path + ("?" + u.query if u.query else ""), data=req.post_data_buffer,
                                    headers={"Content-Type": req.headers.get("content-type", "")})
            return route.fulfill(status=200, content_type=ctype, body=to_live(body, a.local))
        local = None
        if kind == "after" and path.startswith(THEME_PATH) and os.path.isfile(os.path.join(THEME, path[len(THEME_PATH):])):
            local = os.path.join(THEME, path[len(THEME_PATH):])
        if not local and os.path.isfile(os.path.join(SNAPSHOT, "site", path.lstrip("/"))):
            local = os.path.join(SNAPSHOT, "site", path.lstrip("/"))
        if not local:
            return route.fulfill(status=404, body="")
        with open(local, "rb") as f:
            return route.fulfill(status=200, content_type=mimetypes.guess_type(local)[0] or "application/octet-stream",
                                 body=f.read())

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=os.environ.get("CHROMIUM", "/opt/pw-browsers/chromium"),
                                    proxy={"server": proxy} if proxy else None)
        for name in a.pages.split(","):
            if name == "hub":
                path, saved_name = "/admissions/", "admissions"
            elif os.path.exists(os.path.join(SNAPSHOT, "pages", f"admissions__{name}.html")):
                path, saved_name = f"/admissions/{name}/", f"admissions__{name}"
            else:
                path, saved_name = "/" + name.replace("__", "/") + "/", name
            with open(os.path.join(SNAPSHOT, "pages", saved_name + ".html"), encoding="utf-8") as f:
                saved = f.read()
            for kind in kinds:
                if kind == "after" and not path.startswith("/admissions/"):
                    continue
                html = saved if kind == "before" else after_page(saved, path, a.local)
                for size in a.sizes.split(","):
                    w, h = map(int, size.split("x"))
                    ctx = browser.new_context(viewport={"width": w, "height": h}, ignore_https_errors=True,
                                              device_scale_factor=1, is_mobile=w < 768, has_touch=w < 768)
                    page = ctx.new_page()

                    def safe(route, _request=None, html=html, kind=kind):
                        try:
                            handle(route, html, kind)
                        except Exception as e:  # noqa: BLE001 - one failed file shouldn't stop the run
                            print("route error", route.request.url[:90], e, file=sys.stderr)
                            route.abort()
                    page.route("**/*", safe)
                    page.goto(SITE + path, wait_until="domcontentloaded", timeout=60000)
                    try:
                        page.wait_for_load_state("load", timeout=15000)
                    except Exception:  # noqa: BLE001
                        pass
                    page.wait_for_timeout(1200)
                    if a.setup:
                        page.evaluate(a.setup)
                        page.wait_for_timeout(600)
                    shot = os.path.join(a.out, f"{name}-{kind}-{size}.png")
                    page.screenshot(path=shot, full_page=not a.viewport)
                    hscroll = page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth")
                    print(f"{shot}{'  HORIZONTAL SCROLL' if hscroll else ''}")
                    if a.eval:
                        print("  ", page.evaluate(a.eval))
                    ctx.close()
        browser.close()


if __name__ == "__main__":
    sys.exit(main())
