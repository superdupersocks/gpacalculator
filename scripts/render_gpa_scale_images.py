"""Render the /gpa-scale/ chart images (one per page) with Playwright.

    python3 scripts/render_gpa_scale_images.py OUT_DIR [slug ...]      (default: all 31 pages)

Each image matches the page's scale table (same rows as scripts/build_gpa_scale_update.py) with the page's own
GPA row highlighted, and is rendered at the exact pixel size of the image it replaces (SIZES), so WordPress
regenerates every sized copy under the same file names. Writes <x.x>-GPA.png plus alt.json (slug -> alt text).
"""
import json
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
REPO = Path(__file__).resolve().parent.parent

# Original attachment sizes on the live site (wp_get_attachment_metadata), 2026-10-01.
SIZES = {
    "4-0-gpa": (1803, 1761), "3-9-gpa": (1803, 2121), "3-8-gpa": (1803, 2121), "3-7-gpa": (1803, 2001),
    "3-6-gpa": (1803, 1761), "3-5-gpa": (1803, 2001), "3-4-gpa": (1803, 2001), "3-3-gpa": (1803, 1761),
    "3-2-gpa": (1803, 2001), "3-1-gpa": (1803, 2121), "3-0-gpa": (1803, 1761), "2-9-gpa": (1803, 2121),
    "2-8-gpa": (1803, 2121), "2-7-gpa": (1803, 1761), "2-6-gpa": (1803, 2001), "2-5-gpa": (1803, 2001),
    "2-4-gpa": (1803, 2001), "2-3-gpa": (1803, 1761), "2-2-gpa": (1803, 2001), "2-1-gpa": (1803, 2121),
    "2-0-gpa": (1803, 1761), "1-9-gpa": (1803, 2121), "1-8-gpa": (1803, 2121), "1-7-gpa": (1803, 1761),
    "1-6-gpa": (1803, 2001), "1-5-gpa": (1803, 2001), "1-4-gpa": (1803, 2001), "1-3-gpa": (1803, 1761),
    "1-2-gpa": (1803, 2001), "1-1-gpa": (1803, 1881), "1-0-gpa": (1803, 1761),
}

STANDARD = [
    ("4.0", "97–100%", "A+"), ("4.0", "93–96%", "A"), ("3.7", "90–92%", "A−"),
    ("3.3", "87–89%", "B+"), ("3.0", "83–86%", "B"), ("2.7", "80–82%", "B−"),
    ("2.3", "77–79%", "C+"), ("2.0", "73–76%", "C"), ("1.7", "70–72%", "C−"),
    ("1.3", "67–69%", "D+"), ("1.0", "65–66%", "D"), ("0.7", "60–64%", "D−"),
    ("0.0", "Below 60%", "F"),
]


def page_rows():
    page = {}
    for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
        m = re.match(r"\| /gpa-scale/([0-9])-([0-9])-gpa/[^|]*\| ([^|]+) \| ([^|]+) \|", line)
        if m:
            page[f"{m.group(1)}-{m.group(2)}-gpa"] = (f"{m.group(1)}.{m.group(2)}", m.group(4).strip().replace("-", "–"),
                                                     m.group(3).strip().replace("-", "−"))
    out = {}
    for slug, (gpa, pct, letter) in page.items():
        rows = list(STANDARD)
        if gpa not in {g for g, _, _ in STANDARD}:
            at = next(i for i, (g, _, _) in enumerate(rows) if float(g) < float(gpa))
            rows.insert(at, (gpa, pct, letter))
        out[slug] = (gpa, pct, letter, rows)
    return out


CSS = """
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:%(w)dpx;height:%(h)dpx}
body{font-family:'Lexend',system-ui,sans-serif;background:linear-gradient(135deg,#7c3aed,#4f46e5);padding:56px;display:flex}
.card{flex:1;background:#fff;border-radius:44px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 30px 80px rgba(30,27,75,.35)}
.top{padding:64px 72px 52px;text-align:center;background:#F5F3FF;border-bottom:2px solid #E9E5FF}
.top h1{font-size:150px;line-height:1;font-weight:700;color:#1e1b4b;letter-spacing:-3px}
.top p{margin-top:22px;font-size:46px;color:#4c1d95;font-weight:500}
.top p b{font-weight:700;color:#4f46e5}
.grid{flex:1;display:grid;grid-template-columns:1fr 1.3fr 1fr;grid-auto-rows:1fr;font-size:46px;color:#111827}
.grid>div{display:flex;align-items:center;justify-content:center;border-bottom:2px solid #EEF0F3;font-variant-numeric:tabular-nums}
.grid .h{font-size:34px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#6B7280;background:#F9FAFB}
.grid .g{font-weight:600}
.grid .l{font-weight:700}
.a{background:#F0FDF4}.a.l{color:#15803D}
.b{background:#EFF6FF}.b.l{color:#1D4ED8}
.c{background:#FFFBEB}.c.l{color:#B45309}
.d{background:#FFF7ED}.d.l{color:#C2410C}
.f{background:#FEF2F2}.f.l{color:#B91C1C}
.grid>div.cur{background:#2563EB;color:#fff !important;font-weight:700;font-size:56px;border-bottom-color:#2563EB}
.pill{margin-left:22px;padding:6px 18px;border-radius:999px;background:#fff;color:#2563EB;font-size:26px;font-weight:700;letter-spacing:1.5px}
.foot{display:flex;justify-content:space-between;align-items:center;padding:30px 60px;font-size:32px;color:#6B7280;border-top:2px solid #EEF0F3}
.foot b{color:#4f46e5;font-size:38px;font-weight:700}
"""


def html_for(slug, gpa, pct, letter, rows, w, h):
    cells = ['<div class="h">GPA</div><div class="h">Percentage</div><div class="h">Letter grade</div>']
    for g, p, l in rows:
        band = l[0].lower() if l[0].lower() in "abcdf" else ""
        cur = " cur" if g == gpa else ""
        pill = '<span class="pill">YOUR GPA</span>' if cur else ""
        cells.append(f'<div class="g {band}{cur}">{g}{pill}</div><div class="{band}{cur}">{p}</div><div class="l {band}{cur}">{l}</div>')
    grade = "a straight-A average" if gpa == "4.0" else f"{'an' if letter[0] in 'AF' else 'a'} <b>{letter}</b> average"
    return f"""<!doctype html><html><head><meta charset="utf-8">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;500;600;700&display=block" rel="stylesheet">
<style>{CSS % {'w': w, 'h': h}}</style></head><body><div class="card">
<div class="top"><h1>{gpa} GPA</h1><p>{grade} &middot; <b>{pct}</b> on the 4.0 scale</p></div>
<div class="grid">{''.join(cells)}</div>
<div class="foot"><span>Standard unweighted scale. Cutoffs vary by school.</span><b>GPAcalculator.net</b></div>
</div></body></html>"""


def alt_for(gpa, pct, letter):
    return f"GPA scale chart with a {gpa} GPA highlighted: {letter} letter grade, {pct}"


def main():
    out = Path(sys.argv[1])
    out.mkdir(parents=True, exist_ok=True)
    data = page_rows()
    slugs = sys.argv[2:] or list(SIZES)
    from playwright.sync_api import sync_playwright
    alts = {}
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for slug in slugs:
            gpa, pct, letter, rows = data[slug]
            w, h = SIZES[slug]
            page = browser.new_page(viewport={"width": w, "height": h}, device_scale_factor=1)
            page.set_content(html_for(slug, gpa, pct, letter, rows, w, h), wait_until="networkidle")
            page.evaluate("document.fonts.ready")
            page.screenshot(path=str(out / f"{gpa}-GPA.png"), clip={"x": 0, "y": 0, "width": w, "height": h})
            page.close()
            alts[slug] = alt_for(gpa, pct, letter)
            print(slug, f"{w}x{h}")
        browser.close()
    (out / "alt.json").write_text(json.dumps(alts, indent=2, ensure_ascii=False) + "\n")


if __name__ == "__main__":
    main()
