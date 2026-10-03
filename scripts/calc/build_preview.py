#!/usr/bin/env python3
"""Build calculator v2 preview pages from the live College GPA page snapshot, without touching the site.

    python3 scripts/calc/build_preview.py OUTDIR [--modules]

Writes OUTDIR/college-gpa-calculator.html and OUTDIR/ucla-gpa-calculator.html:
  - the live page's markup as a logged-out visitor gets it (data/admissions/preview/snapshot), with the
    theme's stylesheets inlined from the design branch at THEME_REF (the snapshot predates the phone
    spacing and component library deploys);
  - the old Bolt calculator's CSS/JS and its prerendered markup removed, the new calculator added;
  - ads, analytics and third-party scripts removed (window.gtag is a stub that records events in
    window.__events, so tests can read them).
The UCLA page reuses the College page's shell with the [gpcm_calculator id="ucla"] markup in the content.

Default: the calculator JS is bundled inline with esbuild (one self-contained file per page).
--modules: the page loads the real ES modules from the repo instead (served from the repo root by
tests/qa_lib.py), which is what the Playwright QA uses.
"""
import json, os, re, subprocess, sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SNAP = REPO / "data/admissions/preview/snapshot"
PAGE = SNAP / "pages/college-gpa-calculator.html"
SITE = SNAP / "site"
CALC = REPO / "plugin/gpacalculator-manager/assets/calc-assets"
CALC_URL = "/plugin/gpacalculator-manager/assets/calc-assets"
# Theme stylesheets come from the design branch at this commit: everything deployed since the snapshot
# (column tiers, phone spacing, component library) plus e3b337a, 5ca342b and 499c3f4 (hero), not yet deployed: library styles inside calculators,
# the standard's calculator tokens and 14px phone spacing for shortcode calculators.
THEME_REF = "499c3f4"
HOST = "https://gpacalculator.net"
DROP_INLINE = re.compile(r"freestar|pubfig|googletag|gtag\(|dataLayer|speculationrules", re.I)

GTAG_STUB = ("<script>window.__events=[];window.dataLayer=window.dataLayer||[];"
             "window.gtag=function(){window.__events.push(Array.prototype.slice.call(arguments));};</script>")


def balanced_div(html, start):
    """Index just past the </div> that closes the <div ...> opening at `start`."""
    depth, i = 0, start
    tag = re.compile(r"<(/?)div\b[^>]*>", re.I)
    for m in tag.finditer(html, start):
        depth += -1 if m.group(1) else 1
        if depth == 0:
            return m.end()
    raise ValueError("unbalanced div")


def site_file(url):
    path = url.split("?")[0].split("#")[0].replace(HOST, "").lstrip("/")
    return SITE / path


def theme_css(name):
    """A child-theme stylesheet as of THEME_REF (what is live plus the library-in-calculators change),
    or None when that commit doesn't have it (then the snapshot's copy is used)."""
    out = subprocess.run(["git", "show", f"{THEME_REF}:child-theme/generatepress-child/{name}"],
                         cwd=REPO, capture_output=True, text=True)
    return out.stdout if out.returncode == 0 else None


def absolutize_css(css, url):
    base = url.split("?")[0].rsplit("/", 1)[0] + "/"

    def fix(m):
        u = m.group(2)
        if re.match(r"^(data:|https?:|//|#)", u):
            return m.group(0)
        return f"url({m.group(1)}{base}{u}{m.group(1)})"
    return re.sub(r"url\((['\"]?)([^)'\"]+)\1\)", fix, css)


def inline_styles(html):
    def repl(m):
        tag = m.group(0)
        href = re.search(r"href=['\"]([^'\"]+)['\"]", tag).group(1).replace("&#038;", "&")
        hid = re.search(r"id=['\"]([^'\"]+)['\"]", tag)
        if HOST not in href:
            return tag if "fonts.googleapis.com" in href else ""
        theme = re.search(r"/generatepress-child/([\w.-]+\.css)$", href.split("?")[0])
        css = theme_css(theme.group(1)) if theme else None
        if css is None:
            f = site_file(href)
            if not f.exists():
                return tag
            css = f.read_text(encoding="utf-8", errors="replace")
        ident = f" id='{hid.group(1)}'" if hid else ""
        return f"<style{ident} data-src='{href.split('?')[0].replace(HOST, '')}'>\n{absolutize_css(css, href)}\n</style>"
    return re.sub(r"<link\b[^>]*rel=['\"]stylesheet['\"][^>]*>", repl, html)


def clean_scripts(html):
    def repl(m):
        tag = m.group(0)
        if 'application/ld+json' in tag:
            return tag
        src = re.search(r"\ssrc=['\"]([^'\"]+)['\"]", tag)
        if src:
            u = src.group(1).replace("&#038;", "&")
            if HOST in u and "/calc-assets/" not in u and "/themes/" in u:
                f = site_file(u)
                if f.exists():
                    return f"<script data-src='{u.split('?')[0].replace(HOST, '')}'>\n{f.read_text(encoding='utf-8')}\n</script>"
            return ""
        return "" if DROP_INLINE.search(tag) else tag
    html = re.sub(r"<script\b[^>]*>.*?</script>", repl, html, flags=re.S | re.I)
    # Freestar ad slots: keep the boxes (they reserve space live), drop their contents.
    return re.sub(r"(<div[^>]*data-freestar-ad[^>]*>).*?(</div>)", r"\1\2", html, flags=re.S)


def bundle(entry):
    exe = REPO / "node_modules/.bin/esbuild"
    out = subprocess.run([str(exe), str(entry), "--bundle", "--format=esm", "--minify", "--target=es2020",
                          "--legal-comments=none"], cwd=REPO, capture_output=True, text=True, check=True)
    return out.stdout


def calc_tags(entry, modules):
    css = "".join(f"<style data-src='{CALC_URL}/{p}'>\n{(CALC / p).read_text()}\n</style>"
                  for p in ("core/calc-core.css", "gpa/gpa-app.css"))
    if modules:
        js = f"<script type='module' src='{CALC_URL}/{entry}'></script>"
    else:
        js = f"<script type='module'>\n{bundle(CALC / entry)}\n</script>"
    return css, js


def build(out, modules):
    src = PAGE.read_text(encoding="utf-8")
    html = re.sub(r"<link[^>]*id='main-css-college-gpa-calculator-css'[^>]*>", "", src)
    html = re.sub(r"<script[^>]*id=\"main-js-college-gpa-calculator-js\"[^>]*></script>", "", html)
    html = inline_styles(html)
    html = clean_scripts(html)
    html = html.replace("<head>", "<head>\n" + GTAG_STUB, 1)
    a = html.index('<div id="root">')
    html = html[:a] + '<div id="root"></div>' + html[balanced_div(html, a):]

    pages = {}
    css, js = calc_tags("gpa/college-gpa.js", modules)
    pages["college-gpa-calculator.html"] = html.replace("</head>", css + "\n</head>", 1).replace("</body>", js + "\n</body>", 1)

    # UCLA: the same shell, content replaced by the university shortcode's markup.
    cfg = json.loads((REPO / "data/gpcm-university-profiles/ucla.json").read_text())
    host = ('<div class="gpcm-host" data-gpcm-profile-id="ucla" data-gpac-engine="v2">'
            f'<script type="application/json" data-gpcm-profile>{json.dumps(cfg)}</script></div>')
    u = html.replace("College GPA Calculator", "UCLA GPA Calculator").replace("College GPA calculator", "UCLA GPA calculator")
    e = u.index('<div class="entry-content">')
    intro = ("<p>Preview only: the College page's layout with the UCLA calculator in the content, as "
             "<code>[gpcm_calculator id=\"ucla\"]</code> prints it.</p>")
    u = u[:e] + '<div class="entry-content">\n' + intro + host + "\n</div>" + u[balanced_div(u, e):]
    css, js = calc_tags("gpa/uni-gpa.js", modules)
    pages["ucla-gpa-calculator.html"] = u.replace("</head>", css + "\n</head>", 1).replace("</body>", js + "\n</body>", 1)

    out.mkdir(parents=True, exist_ok=True)
    for name, body in pages.items():
        (out / name).write_text(body, encoding="utf-8")
    return [out / n for n in pages]


if __name__ == "__main__":
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        sys.exit(__doc__)
    for p in build(Path(args[0]).resolve(), "--modules" in sys.argv):
        print(p, f"{p.stat().st_size // 1024} KB")
