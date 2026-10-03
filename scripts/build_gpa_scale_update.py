"""Build the WP-CLI script for the second /gpa-scale/ pass (see update_gpa_scale_pages.php).

    python3 scripts/build_gpa_scale_update.py [--all-headings] [--save] > update.php
    ssh <server> 'cd applications/xwnzegvpyy/public_html && wp eval-file -' < update.php

Each page's GPA chart image becomes a core Table block (class gpa-scale-table): the standard scale from the
/gpa-scale/ hub page, plus the page's own GPA as a row when it isn't already one (letter and percentage from
content/gpa-scale-intros.md, i.e. the page's own text). The theme highlights the matching row.
"""
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
MINUS = "−"

# The /gpa-scale/ hub page's table: (GPA, percentage, letter)
STANDARD = [
    ("4.0", "97–100%", "A+"), ("4.0", "93–96%", "A"), ("3.7", "90–92%", "A−"),
    ("3.3", "87–89%", "B+"), ("3.0", "83–86%", "B"), ("2.7", "80–82%", "B−"),
    ("2.3", "77–79%", "C+"), ("2.0", "73–76%", "C"), ("1.7", "70–72%", "C−"),
    ("1.3", "67–69%", "D+"), ("1.0", "65–66%", "D"), ("0.7", "60–64%", "D−"),
    ("0.0", "Below 60%", "F"),
]


def cell(tag, text):
    return f'<{tag} class="has-text-align-center" data-align="center">{text}</{tag}>'


def table_block(gpa, rows):
    head = "<thead><tr>" + "".join(cell("th", h) for h in ("GPA", "Percentage", "Letter grade")) + "</tr></thead>"
    body = "<tbody>" + "".join("<tr>" + cell("td", g) + cell("td", p) + cell("td", l) + "</tr>" for g, p, l in rows) + "</tbody>"
    caption = f"Where a {gpa} GPA sits on the standard 4.0 scale. Schools set their own cutoffs, so check yours."
    return ('<!-- wp:table {"className":"gpa-scale-table"} -->\n'
            f'<figure class="wp-block-table gpa-scale-table"><table class="has-fixed-layout">{head}{body}</table>'
            f'<figcaption class="wp-element-caption">{caption}</figcaption></figure>\n'
            '<!-- /wp:table -->')


page = {}
for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
    m = re.match(r"\| /gpa-scale/([0-9])-([0-9])-gpa/[^|]*\| ([^|]+) \| ([^|]+) \|", line)
    if m:
        page[f"{m.group(1)}-{m.group(2)}-gpa"] = (f"{m.group(1)}.{m.group(2)}", m.group(4).strip().replace("-", "–"), m.group(3).strip().replace("-", MINUS))

tables = {}
for slug, (gpa, pct, letter) in page.items():
    rows = list(STANDARD)
    if gpa not in {g for g, _, _ in STANDARD}:
        at = next(i for i, (g, _, _) in enumerate(rows) if float(g) < float(gpa))
        rows.insert(at, (gpa, pct, letter))
    tables[slug] = table_block(gpa, rows)

php = (REPO / "scripts" / "update_gpa_scale_pages.php").read_text()
head = (
    "define( 'GPC_SAVE', " + ("true" if "--save" in sys.argv else "false") + " );\n"
    "define( 'GPU_ALL_HEADINGS', " + ("true" if "--all-headings" in sys.argv else "false") + " );\n"
    "$tables = json_decode( " + repr(json.dumps(tables, ensure_ascii=False)) + ", true );\n"
)
marker = "// Filled in by the build script"
sys.stdout.write(php.replace(marker, head + marker, 1))
print(f"{len(tables)} tables", file=sys.stderr)
