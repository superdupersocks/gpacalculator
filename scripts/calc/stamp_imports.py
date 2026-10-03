#!/usr/bin/env python3
"""Version-tag the imports between the calculator modules (plugin/.../assets/calc-assets).

    python3 scripts/calc/stamp_imports.py           # rewrite the tags in place
    python3 scripts/calc/stamp_imports.py --check   # exit 1 if any tag is missing or stale

WordPress gives the entry module (e.g. gpa/college-gpa.js) a ?ver=, but the files it imports
(core/calc-core.js, gpa/gpa-app.js …) are fetched by their plain paths, and the server and Cloudflare
cache them for a year. After a deploy a visitor could get a new gpa-app.js with an old cached
calc-core.js, and the screen would not load. So every relative import carries ?v=<tag>, one tag for
the whole folder, taken from the files' contents (tags left out). A change to any module changes the
tag, which changes every entry file, so WordPress serves the entries with a new ?ver= and the browser
fetches a matching new set. Run it before committing a module change; the QA (tests/gpa_v2_qa.py)
fails while a tag is stale.
"""
import hashlib
import re
import sys
from pathlib import Path

CALC = Path(__file__).resolve().parents[2] / "plugin/gpacalculator-manager/assets/calc-assets"
# from './x.js' / from "../core/calc-core.js?v=abc" / import('../core/chart-kit.js')
SPEC = re.compile(r"""(\bfrom\s+|\bimport\s*\(\s*)(['"])(\.{1,2}/[^'"?]+?\.js)(\?v=[0-9a-f]*)?\2""")


def code_line(line):
    s = line.lstrip()
    return not (s.startswith("*") or s.startswith("//") or s.startswith("/*"))


def rewrite(text, tag):
    out = []
    for line in text.splitlines(keepends=True):
        if code_line(line):
            line = SPEC.sub(lambda m: f"{m[1]}{m[2]}{m[3]}{'?v=' + tag if tag else ''}{m[2]}", line)
        out.append(line)
    return "".join(out)


def modules():
    return sorted(p for p in CALC.rglob("*.js") if "node_modules" not in p.parts)


def current_tag():
    h = hashlib.sha1()
    for p in modules():
        h.update(p.relative_to(CALC).as_posix().encode() + b"\0")
        h.update(rewrite(p.read_text(encoding="utf-8"), "").encode() + b"\0")
    return h.hexdigest()[:10]


def stale():
    """Files whose imports don't carry the current tag (empty list = all current)."""
    tag = current_tag()
    return tag, [p for p in modules() if rewrite(p.read_text(encoding="utf-8"), tag) != p.read_text(encoding="utf-8")]


def main():
    tag, files = stale()
    if "--check" in sys.argv:
        for p in files:
            print(f"stale import tag: {p.relative_to(CALC)} (run scripts/calc/stamp_imports.py)")
        return 1 if files else 0
    for p in files:
        p.write_text(rewrite(p.read_text(encoding="utf-8"), tag), encoding="utf-8")
        print(f"stamped {p.relative_to(CALC)}")
    print(f"tag {tag}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
