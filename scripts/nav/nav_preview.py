#!/usr/bin/env python3
"""Before/after previews of the header + footer reorganization, rendered from a live-page snapshot.

    python3 scripts/nav/nav_preview.py SNAPSHOT_PAGE THEME_DIR OUTDIR

The "after" swaps in the markup site-nav.php + the new menus print (mirrored here from scripts/wp/nav_reorg.php)
and links site-nav.css/.js from THEME_DIR. Shots: header at 1440/1024/800, dropdown open (keyboard), phone menu
open, footer at 1440 and 390 (closed, and one column opened). Ads and third parties are blocked.
"""
import os, re, sys, urllib.parse, mimetypes
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "design"))
import preview as base  # noqa: E402
from playwright.sync_api import sync_playwright  # noqa: E402

H = "https://gpacalculator.net"
GPA = [("College GPA Calculator", "/college-gpa-calculator/"), ("High School GPA Calculator", "/high-school-gpa-calculator/"),
       ("Weighted GPA Calculator", "/weighted-gpa-calculator/"), ("Middle School GPA Calculator", "/middle-school-gpa-calculator/"),
       ("CGPA Calculator", "/cumulative-cgpa-calculator/"), ("Raise GPA Calculator", "/how-to-raise-gpa/")]
GRADE = [("Grade Calculator", "/grade-calculator/"), ("Final Grade Calculator", "/final-grade-calculator/"),
         ("Weighted Grade Calculator", "/weighted-grade-calculator/"), ("Semester Grade Calculator", "/semester-grade-calculator/"),
         ("EZ Grader", "/ez-grader/")]
COLS = [
    ("GPA Calculators", GPA), ("Grade Calculators", GRADE),
    ("Popular GPAs", [(f"{g} GPA", f"/gpa-scale/{g.replace('.', '-')}-gpa/") for g in ("4.0", "3.9", "3.8", "3.7", "3.6", "3.5", "3.0")]
     + [("All GPAs →", "/gpa-scale/")]),
    ("Colleges", [("Browse all colleges", "/admissions/"), ("University of South Carolina", "/admissions/university-of-south-carolina-columbia/"),
                  ("University of Arkansas", "/admissions/university-of-arkansas/"), ("Chico State", "/admissions/california-state-university-chico/"),
                  ("George Mason", "/admissions/george-mason-university/"), ("UMKC", "/admissions/university-of-missouri-kansas-city/"),
                  ("Kennesaw State University", "/admissions/kennesaw-state-university/")]),
    ("International", [(c, f"/grade-conversion/{s}/") for c, s in (("UK", "united-kingdom"), ("Australia", "australia"),
                        ("Canada", "canada"), ("India", "india"), ("China", "china"), ("France", "france"), ("Germany", "germany"))]
     + [("SGPA to CGPA", "/sgpa-to-cgpa-conversion-calculator/"), ("CGPA to Percentage", "/cgpa-to-percentage-calculator/")]),
]
LEGAL = [("About", "/about-us/"), ("Contact", "/contact-us/"), ("Privacy", "/privacy-policy/"), ("Terms", "/terms/"),
         ("Data sources", "/data-sources/")]


def li(label, url, n):
    return (f'<li id="menu-item-9{n:03d}" class="menu-item menu-item-type-post_type menu-item-object-page menu-item-9{n:03d}">'
            f'<a href="{H}{url}">{label}</a></li>')


def header_ul(old_ul):
    arrow = re.search(r'<span role="presentation" class="dropdown-menu-toggle">.*?</svg></span></span>', old_ul, re.S).group(0)
    out, n = [], 0
    for title, kids in (("GPA Calculators", GPA), ("Grade Calculators", GRADE)):
        n += 1
        sub = "".join(li(l, u, (n := n + 1)) for l, u in kids)
        out.append(f'<li id="menu-item-8{n:03d}" class="menu-item menu-item-type-custom menu-item-object-custom menu-item-has-children">'
                   f'<a href="#" aria-haspopup="true" aria-expanded="false" role="button">{title}{arrow}</a><ul class="sub-menu">{sub}</ul></li>')
    for l, u in (("GPA Scale", "/gpa-scale/"), ("Grade Conversion", "/grade-conversion/"), ("Colleges", "/admissions/")):
        out.append(li(l, u, (n := n + 1)))
    return '<ul id="menu-top-nav" class=" menu sf-menu">' + "".join(out) + "</ul>"


def footer(html):
    cols, n = [], 100
    for i, (title, links) in enumerate(COLS, 1):
        items = "".join(li(l, u, (n := n + 1)) for l, u in links)
        cols.append(f'<div class="footer-widget-{i}"><details id="nav_menu-{i}" class="widget inner-padding widget_nav_menu gpa-foot-col" open>'
                    f'<summary class="gpa-foot-col__summary"><h2 class="widget-title">{title}</h2></summary>'
                    f'<div class="menu-c{i}-container"><ul class="menu">{items}</ul></div></details></div>')
    html = re.sub(r'(<div class="inside-footer-widgets">).*?(</div>\s*</div>\s*</div>\s*<footer class="site-info")',
                  lambda m: m.group(1) + "".join(cols) + "</div></div></div><footer class=\"site-info\"", html, count=1, flags=re.S)
    legal = "".join(f'<li class="menu-item"><a href="{H}{u}">{l}</a></li>' for l, u in LEGAL)
    bar = (f'<nav class="gpa-legal" aria-label="Site information"><ul id="gpa-legal" class="gpa-legal__list">{legal}</ul>'
           '<span class="gpa-legal__copy">&copy; 2026 GPA Calculator</span></nav>')
    return re.sub(r'(<div class="copyright-bar">).*?(</div>)', lambda m: m.group(1) + bar + m.group(2), html, count=1, flags=re.S)


def after(html, theme):
    html = re.sub(r'<ul id="menu-top-nav".*?</ul>(?=</div>)',
                  lambda m: header_ul(m.group(0)), html, count=1, flags=re.S)
    html = footer(html)
    v = int(os.path.getmtime(os.path.join(theme, "site-nav.css")))
    html = html.replace("</head>", f"<link rel='stylesheet' id='gpa-site-nav-css' href='{H}/wp-content/themes/generatepress-child/site-nav.css?ver={v}' media='all' /></head>", 1)
    html = html.replace("</body>", f"<script src='{H}/wp-content/themes/generatepress-child/site-nav.js?ver={v}'></script></body>", 1)
    return html


def main():
    page_file, theme, out = sys.argv[1:4]
    snapshot = os.path.dirname(os.path.dirname(page_file))
    os.makedirs(out, exist_ok=True)
    raw = open(page_file, encoding="utf-8").read()
    proxy = os.environ.get("HTTPS_PROXY")

    def handler(variant):
        def handle(route):
            try:
                u = urllib.parse.urlsplit(route.request.url)
                if u.netloc in ("fonts.googleapis.com", "fonts.gstatic.com"):
                    body = base.font_file(route.request.url, os.path.join(snapshot, ".font-cache"))
                    return route.fulfill(status=200, body=body, content_type="text/css" if "googleapis" in u.netloc else "font/woff2",
                                         headers={"Access-Control-Allow-Origin": "*"})
                if not u.netloc.endswith("gpacalculator.net"):
                    return route.abort()
                if route.request.resource_type == "document":
                    return route.fulfill(status=200, content_type="text/html; charset=utf-8",
                                         body=after(raw, theme) if variant == "after" else raw)
                path = urllib.parse.unquote(u.path)
                local = None
                if path.startswith(base.THEME_PATH):
                    c = os.path.join(theme, path[len(base.THEME_PATH):])
                    local = c if os.path.isfile(c) else None
                if not local:
                    c = os.path.join(snapshot, "site", path.lstrip("/"))
                    local = c if os.path.isfile(c) else None
                if not local:
                    return route.fulfill(status=404, body="")
                return route.fulfill(status=200, content_type=mimetypes.guess_type(local)[0] or "application/octet-stream",
                                     body=open(local, "rb").read())
            except Exception as e:  # noqa: BLE001
                print("route error", e, file=sys.stderr)
                route.abort()
        return handle

    with sync_playwright() as p:
        b = p.chromium.launch(executable_path="/opt/pw-browsers/chromium", proxy={"server": proxy} if proxy else None)

        def open_page(variant, w, h):
            ctx = b.new_context(viewport={"width": w, "height": h}, device_scale_factor=2 if w < 768 else 1,
                                is_mobile=w < 768, has_touch=w < 768, ignore_https_errors=True)
            pg = ctx.new_page()
            pg.route("**/*", handler(variant))
            pg.goto(H + "/admissions/harvard/", wait_until="domcontentloaded", timeout=60000)
            try:
                pg.wait_for_load_state("load", timeout=15000)
            except Exception:  # noqa: BLE001
                pass
            pg.wait_for_timeout(800)
            return ctx, pg

        def header_shot(pg, name, h=360):
            pg.screenshot(path=os.path.join(out, name), clip={"x": 0, "y": 0, "width": pg.viewport_size["width"], "height": h})

        def footer_shot(pg, name):
            el = pg.query_selector(".site-footer")
            el.scroll_into_view_if_needed()
            pg.wait_for_timeout(300)
            el.screenshot(path=os.path.join(out, name))

        for variant in ("before", "after"):
            for w, h in ((1440, 900), (1024, 768), (800, 1000)):
                ctx, pg = open_page(variant, w, h)
                header_shot(pg, f"{variant}-header-{w}.png", 90)
                wrap = pg.evaluate("(()=>{const u=document.querySelector('#menu-top-nav');const r=u.getBoundingClientRect();"
                                   "return {top:r.top,height:r.height,right:r.right,vw:innerWidth}})()")
                print(variant, w, "menu", wrap)
                if w == 1440:
                    # Keyboard: Tab to the first dropdown parent and open it
                    pg.focus("#menu-top-nav > li:first-child > a")
                    pg.keyboard.press("ArrowDown" if variant == "after" else "Enter")
                    pg.wait_for_timeout(300)
                    header_shot(pg, f"{variant}-header-1440-dropdown.png", 420)
                    if variant == "after":
                        print("aria", pg.evaluate("[...document.querySelectorAll('#menu-top-nav > li > a')].map(a=>a.textContent.trim()+':'+a.getAttribute('aria-expanded')+':'+a.getAttribute('aria-controls')).join(' | ')"),
                              "focus", pg.evaluate("document.activeElement.textContent"))
                        pg.keyboard.press("Escape")
                        pg.wait_for_timeout(200)
                        print("after Escape", pg.evaluate("document.activeElement.textContent.trim()+':'+document.activeElement.getAttribute('aria-expanded')"))
                    footer_shot(pg, f"{variant}-footer-1440.png")
                ctx.close()
            ctx, pg = open_page(variant, 390, 844)
            header_shot(pg, f"{variant}-header-390.png", 70)
            pg.click("#mobile-menu-control-wrapper .menu-toggle")
            pg.wait_for_timeout(400)
            if variant == "after":
                pg.click("#menu-top-nav > li:first-child .dropdown-menu-toggle")
                pg.wait_for_timeout(300)
            pg.screenshot(path=os.path.join(out, f"{variant}-menu-open-390.png"))
            pg.click("#mobile-menu-control-wrapper .menu-toggle")
            footer_shot(pg, f"{variant}-footer-390.png")
            if variant == "after":
                print("phone details open:", pg.evaluate("[...document.querySelectorAll('details.gpa-foot-col')].map(d=>d.open)"),
                      "links in HTML:", pg.evaluate("document.querySelectorAll('.site-footer a').length"))
                pg.click("details.gpa-foot-col >> nth=0 >> summary")
                pg.wait_for_timeout(300)
                footer_shot(pg, "after-footer-390-one-open.png")
            print(variant, "hscroll 390:", pg.evaluate("document.documentElement.scrollWidth > innerWidth"))
            ctx.close()
        b.close()


if __name__ == "__main__":
    main()
