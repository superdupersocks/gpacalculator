#!/usr/bin/env python3
"""Checkpoint H (Admissions Phase 2): empty the old, unsourced figures on the pages still under review.

    python3 scripts/admissions/phase2_h_blank.py

The identity review (phase2_r_review.py) leaves 50 pages unchanged until the college's own website settles which
college each one is, and E held 3 more whose match the audit corrected. Those 53 pages still show the figures they had
before Phase 2, with no source and no year (Fairfax University of America: a 1% acceptance rate, so it tops the hub's
"lowest acceptance rate" sort). E's rule for a page it updates is that a field with no fresh value is emptied, never
left with the old one; H applies that rule to these pages now, without waiting for their review: every field E
manages is emptied except `location` and `owning`, which say where the college is and what kind it is. The pages
stay published and the template hides empty fields; when a page's review settles it, an import fills its fields as E
did.

Writes data/admissions/audit/phase2_h_blank.csv, in phase2_e_import.csv's columns with every field empty, for
scripts/admissions/phase2_e_live.sh (an empty value empties a field that has one and never creates one), and prints
how many of the pages hold a value it would empty, by the October 1 export in data/colleges/. Changes nothing on the
site.
"""
import csv
import json
import os

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
AUDIT = os.path.join(ROOT, "data", "admissions", "audit")
KEEP = ("location", "owning")


def rows(name):
    with open(os.path.join(AUDIT, name), newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def main():
    with open(os.path.join(AUDIT, "phase2_e_import.csv"), newline="", encoding="utf-8") as f:
        head = next(csv.reader(f))
    fields = [h for h in head[2:] if h not in KEEP]
    pages = [(r["slug"], r["title"]) for r in rows("phase2_r_review.csv") if r["outcome"] == "hold"]
    pages += [(r["slug"], r["post_title"]) for r in rows("phase2_e_held.csv")
              if r["next_step"].startswith("confirm the corrected match")]
    slugs = [s for s, _ in pages]
    assert len(set(slugs)) == len(slugs), "a page is listed twice"

    holding, empty = [], []
    for slug, title in pages:
        with open(os.path.join(ROOT, "data", "colleges", slug + ".json"), encoding="utf-8") as f:
            page = json.load(f)
        assert page["title"] == title, f"{slug}: the export's title is {page['title']!r}, not {title!r}"
        values = page.get("fields") or {}
        held = [k for k in fields if str(values.get(k) or "").strip()]
        (holding if held else empty).append((slug, held))

    with open(os.path.join(AUDIT, "phase2_h_blank.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f, lineterminator="\n")
        w.writerow(["slug", "post_title"] + fields)
        for slug, title in sorted(pages):
            w.writerow([slug, title] + [""] * len(fields))
    print(f"{len(pages)} pages, {len(fields)} fields: {len(holding)} hold a value to empty, "
          f"{len(empty)} hold none (expected plan: \"{len(holding)} posts would change, {len(empty)} already match, "
          f"0 skipped\")")
    for slug, held in empty:
        print(f"  holds none: {slug}")


if __name__ == "__main__":
    main()
