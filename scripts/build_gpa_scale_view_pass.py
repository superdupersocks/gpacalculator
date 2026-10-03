"""Build the WP-CLI script for the weighted-vs-unweighted pass (scripts/wp/gpa_scale_view_pass.php).

    python3 scripts/build_gpa_scale_view_pass.py PAGES_JSON [--with-theme-code] > run.php

PAGES_JSON: {slug: {"id": post ID}} for the /gpa-scale/<x-y>-gpa/ pages (homepage anchors are assigned in page-ID
order). Figures come from scripts/lib/gpa_rule.py; old figures from content/gpa-scale-intros.md; FAQs from
content/gpa-scale-faqs.json. --with-theme-code inlines the new theme functions so a preview can render before
the theme is deployed (the live row-marking filter is swapped for the new one).
"""
import json, re, sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO / "scripts" / "lib"))
from gpa_rule import figures  # noqa: E402

ANCHORS = ["check my GPA", "check your GPA", "GPA calculator", "online GPA calculator", "calculate your GPA", "calculate my GPA"]
pages = json.load(open(sys.argv[1]))
faqs = json.loads((REPO / "content" / "gpa-scale-faqs.json").read_text())
old = {}
for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
    m = re.match(r"\| /gpa-scale/([0-9]-[0-9]-gpa)/[^|]*\| ([^|]+) \| ([^|]+) \|", line)
    if m:
        old[m.group(1)] = (m.group(2).strip(), m.group(3).strip())

# Letter wording that has to follow the rule too (audit 2026-10-03): 2.8 is B−; 2.5 and 1.5 are ties.
EXTRA = {
    "2-8-gpa": {"82%, the bottom of the B range": "82%, the top of the B- range", "It means B work": "It means B− work"},
    "2-5-gpa": {"about 80%, a low B-": "about 80%, right on the line between a C+ and a B-", "It means B− work": "It means C+/B− work"},
    "1-5-gpa": {"about 70%, the bottom of the C- range": "about 70%, right on the line between a D+ and a C-",
                "C− work, about 70%": "D+/C− work, about 70%"},
}

config = {"gpa-scale": {}}
for i, slug in enumerate(sorted(pages, key=lambda s: pages[s]["id"])):
    gs = f"{slug[0]}.{slug[2]}"
    letter, pct = figures(gs)
    o_letter, o_pct = old[slug]
    swaps = {}
    new_pct = pct.lstrip("≈")
    if o_pct != new_pct:
        swaps[o_pct] = new_pct
    swaps.update(EXTRA.get(slug, {}))
    config[slug] = {"home_anchor": ANCHORS[i % len(ANCHORS)], "swaps": swaps, "faqs": faqs[slug], "letter": letter, "pct": pct}

php = (REPO / "scripts" / "wp" / "gpa_scale_view_pass.php").read_text()
head = "$config = json_decode( " + repr(json.dumps(config, ensure_ascii=False)) + ", true );\n"
if "--with-theme-code" in sys.argv:
    sc = (REPO / "child-theme" / "generatepress-child" / "gpa-shortcodes.php").read_text()
    block = sc[sc.index("/* ==========================================================================\n   /gpa-scale/ hub"):]
    block = re.sub(r"add_action\( 'init', function \(\) \{\s*(add_shortcode\([^;]*;)\s*(add_shortcode\([^;]*;)?\s*\} \);",
                   lambda m: m.group(1) + (m.group(2) or ""), block)
    fn = (REPO / "child-theme" / "generatepress-child" / "functions.php").read_text()
    a = fn.index("function gpa_scale_table_mark_rows( $html, $block ) {")
    b = fn.index("\n}\n", a) + 3
    mark = fn[a:b].replace("function gpa_scale_table_mark_rows(", "function gpa_scale_table_mark_rows_preview(")
    head = ("if ( ! function_exists( 'gpa_scale_figures' ) ) {\n" + block + "\n}\n" + mark +
            "remove_filter( 'render_block', 'gpa_scale_table_mark_rows', 10 );\nadd_filter( 'render_block', 'gpa_scale_table_mark_rows_preview', 10, 2 );\n" + head)
sys.stdout.write(php.replace("<?php\n", "<?php\n" + head, 1))
print(json.dumps({k: (v.get("home_anchor"), v.get("swaps"), v.get("letter"), v.get("pct")) for k, v in config.items() if k != "gpa-scale"}, ensure_ascii=False)[:600], file=sys.stderr)
