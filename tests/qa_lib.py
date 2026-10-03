"""Shared Playwright QA helpers for gpacalculator.net calculators.

- Serves the repo over a local HTTP server, so fixture pages load real calc-assets files.
- Serves Inter locally (Google Fonts is blocked in the sandbox and in CI) from @fontsource/inter.
- Optionally loads the LIVE page and routes its calc-assets requests to the repo files
  (live_route), which is how a calculator is checked on the real WordPress page.
- Collects page errors and console errors; every suite must end with zero.

Expected values in suites are computed in Python, never by calling the calculator's code.
"""
import base64
import functools
import http.server
import os
import socketserver
import threading
from decimal import ROUND_HALF_UP, Decimal
from pathlib import Path

from playwright.sync_api import sync_playwright

REPO = Path(__file__).resolve().parent.parent
THEME = REPO / "child-theme" / "generatepress-child"
PLUGIN = REPO / "plugin" / "gpacalculator-manager"
CALC_ASSETS = PLUGIN / "assets" / "calc-assets"   # calculators live in the plugin
THEME_CALC_ASSETS = THEME / "calc-assets"         # legacy location, kept until each move is verified
ARTIFACTS = REPO / "tests" / ".artifacts"
FONT_DIR = REPO / "node_modules" / "@fontsource" / "inter" / "files"


# ---------- number formatting (mirror of the UI contract, written independently) ----------

def round_half_up(x, d=2):
    return float(Decimal(repr(x)).quantize(Decimal(1).scaleb(-d), rounding=ROUND_HALF_UP))


def fmt_pct(x, d=2):
    s = f"{round_half_up(x, d):.{d}f}"
    if "." in s:
        s = s.rstrip("0").rstrip(".")
    return f"{s}%"


# ---------- local server ----------

class _Quiet(http.server.SimpleHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def end_headers(self):
        self.send_header("Cache-Control", "no-store")
        super().end_headers()


_Quiet.extensions_map = {**http.server.SimpleHTTPRequestHandler.extensions_map, ".js": "text/javascript", ".css": "text/css"}


def start_server():
    handler = functools.partial(_Quiet, directory=str(REPO))
    httpd = socketserver.ThreadingTCPServer(("127.0.0.1", 0), handler)
    httpd.daemon_threads = True
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    return httpd, f"http://127.0.0.1:{httpd.server_address[1]}"


# ---------- fonts ----------

def inter_css():
    if not FONT_DIR.exists():
        return "/* Inter not installed: run npm install */"
    out = []
    for w in (400, 500, 600, 700, 800):
        f = FONT_DIR / f"inter-latin-{w}-normal.woff2"
        if f.exists():
            b64 = base64.b64encode(f.read_bytes()).decode()
            out.append(
                f"@font-face{{font-family:'Inter';font-style:normal;font-weight:{w};font-display:swap;"
                f"src:url(data:font/woff2;base64,{b64}) format('woff2');}}"
            )
    return "\n".join(out)


def route_fonts(context):
    css = inter_css()
    context.route("**/fonts.googleapis.com/**", lambda r: r.fulfill(status=200, content_type="text/css", body=css))
    context.route("**/fonts.gstatic.com/**", lambda r: r.fulfill(status=204, body=""))


def live_route(context, files=None):
    """Route live calc-assets URLs (theme or plugin) to repo files, plugin copy first.

    files = {'grade-calculator.js': Path} overrides individual files.
    """
    files = files or {}

    def handler(route):
        name = route.request.url.split("?")[0].rsplit("/calc-assets/", 1)[-1]
        p = files.get(name)
        if p is None:
            p = next((c for c in (CALC_ASSETS / name, THEME_CALC_ASSETS / name) if c.exists()), None)
        if p is not None and p.exists():
            ctype = "text/javascript" if p.suffix == ".js" else "text/css" if p.suffix == ".css" else None
            route.fulfill(status=200, body=p.read_bytes(), content_type=ctype)
        else:
            route.continue_()
    context.route("**/generatepress-child/calc-assets/**", handler)
    context.route("**/gpacalculator-manager/assets/calc-assets/**", handler)


# ---------- browser ----------

def chromium_path():
    for p in (os.environ.get("CHROMIUM_PATH"), "/opt/pw-browsers/chromium"):
        if p and os.path.isfile(p):
            return p
    return None


class Session:
    """with Session() as s: page = s.page(...); ... s.errors holds page errors."""

    def __enter__(self):
        self.httpd, self.base = start_server()
        self.pw = sync_playwright().start()
        exe = chromium_path()
        self.browser = self.pw.chromium.launch(executable_path=exe) if exe else self.pw.chromium.launch()
        self.errors = []
        return self

    def context(self, width=1440, height=900, mobile=False, reduced_motion="reduce", init_script=None):
        ctx = self.browser.new_context(
            viewport={"width": width, "height": height},
            device_scale_factor=2 if mobile else 1,
            is_mobile=mobile,
            has_touch=mobile,
            reduced_motion=reduced_motion,
        )
        route_fonts(ctx)
        if init_script:
            ctx.add_init_script(init_script)
        return ctx

    def page(self, ctx, path):
        page = ctx.new_page()
        page.on("pageerror", lambda e: self.errors.append(f"pageerror: {e}"))
        page.on("console", lambda m: m.type == "error" and self.errors.append(f"console: {m.text}"))
        page.goto(self.base + path)
        return page

    def __exit__(self, *a):
        self.browser.close()
        self.pw.stop()
        self.httpd.shutdown()


class Results:
    def __init__(self, name):
        self.name = name
        self.passed = 0
        self.failed = []

    def check(self, label, got, want):
        if got == want:
            self.passed += 1
        else:
            self.failed.append(f"{label}: got {got!r}, want {want!r}")

    def ok(self, label, cond, detail=""):
        if cond:
            self.passed += 1
        else:
            self.failed.append(f"{label} {detail}".strip())

    def report(self):
        total = self.passed + len(self.failed)
        print(f"\n{self.name}: {self.passed}/{total} passed")
        for f in self.failed:
            print(f"  FAIL {f}")
        return not self.failed
