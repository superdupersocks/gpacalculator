"""Build the WP-CLI script that converts the /gpa-scale/ pages to blocks (see convert_gpa_scale_pages.php).

    python3 scripts/build_gpa_scale_convert.py [--save] > convert.php
    ssh <server> 'cd applications/xwnzegvpyy/public_html && wp eval-file -' < convert.php

Intros come from content/gpa-scale-intros.md; the section-removal logic is the theme's own the_content
filter, lifted from functions.php so both always agree. Without --save it is a dry run.
"""
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
FUNCTIONS = REPO / "child-theme" / "generatepress-child" / "functions.php"

# Page text that disagrees with the site scale (calc-core.js STANDARD_SCALE). Each must match exactly once.
FIXES = {
    "3-8-gpa": {"3.8 GPA is equivalent to 93% or A letter grade.": "3.8 GPA is equivalent to 90-92% or an A- letter grade."},
    "3-5-gpa": {"<strong>90%</strong> or a </span><b>B+ letter grade.</b>": "<strong>89–90%</strong> or a </span><b>B+/A- letter grade.</b>"},
    "1-8-gpa": {"<strong>73%</strong> or </span>a <b>C- letter grade</b>": "<strong>72%</strong> or </span>a <b>C- letter grade</b>"},
}

intros = {}
for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
    m = re.match(r"\| /gpa-scale/([0-9-]+-gpa)/[^|]*\|[^|]*\|[^|]*\| (.+) \|$", line)
    if m and not m.group(2).startswith("Already has"):
        intros[m.group(1)] = m.group(2).strip()

src = FUNCTIONS.read_text()
start = src.index("hide the closing \"admission chances\" section")
body = src[src.index("add_filter( 'the_content', function ( $content ) {", start):]
body = body[: body.index("}, 9 );") + len("}, 9 );")]
hide = "$hide = function ( $content ) {" + body[len("add_filter( 'the_content', function ( $content ) {"):-len("}, 9 );")] + "};"

php = (REPO / "scripts" / "convert_gpa_scale_pages.php").read_text()
head = (
    "define( 'GPC_SAVE', " + ("true" if "--save" in sys.argv else "false") + " );\n"
    "$intros = json_decode( " + repr(json.dumps(intros, ensure_ascii=False)) + ", true );\n"
    "$fixes = json_decode( " + repr(json.dumps(FIXES, ensure_ascii=False)) + ", true );\n"
    + hide + "\n"
)
marker = "// Filled in by the build script"
php = php.replace(marker, head + marker, 1)
sys.stdout.write(php)
print(f"{len(intros)} intros, {len(FIXES)} fixed pages", file=sys.stderr)
