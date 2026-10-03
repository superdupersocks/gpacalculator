#!/usr/bin/env python3
"""GPA-band lists: "Colleges where a 3.5 GPA is typical" (Digant's admissions plan step 2, approved 2026-10-03 06:57).

    python3 scripts/admissions/gpa_bands.py      writes data/admissions/gpa-bands.json

One shared source for every page that lists colleges by GPA: the /gpa-scale/ pages, the college pages' "Colleges where
a [GPA] fits" button and the /admissions/?gpa= hub filter. Changes nothing on the site.

Source: the average high school GPA each college reported in its own Common Data Set (C12), as published on its page
(data/admissions/audit/phase2_b2_gpa.csv: cds_gpa, cds_gpa_year, cds_gpa_basis), for colleges whose page is tier A or B
and indexed (data/admissions/tiering/tiers.csv). Undergraduate enrollment from IPEDS (institutions.csv) orders lists.

Rule, per GPA page (3.0 to 4.0 in steps of 0.1, then 4.1 to 4.5):
- 3.0 to 4.0 use averages of 4.0 or below; 4.1 to 4.5 use weighted averages above 4.0 only.
- A college is in the window when its average is within 0.05 of the GPA; fewer than 10 such colleges widen it to
  0.10. The 3.0 page always uses 0.15.
- Within the window: tier A before B, then larger undergraduate enrollment first, then name; at most 15 shown, all of
  them counted for "See all" (/admissions/?gpa=<GPA>).
- 4.4 and 4.5 (too few weighted averages that high): the five highest weighted averages, introduced as "A 4.5 is above
  every average a college reports. The highest are:" when the GPA is above them all, else "Few colleges report an
  average this high. The highest are:".
- Any other list with fewer than 3 colleges is left out (no block).
Each college: name, page URL, the average as reported, its basis ("weighted" or "" when the college doesn't say), the
CDS year, tier, state and enrollment. Pages label it "as reported by the college" with the year and basis.
"""
import csv
import json
import os

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
DATA = os.path.join(ROOT, "data", "admissions")
OUT = os.path.join(DATA, "gpa-bands.json")
SHOWN, MIN_SHOWN, WIDEN_UNDER, TOP = 15, 3, 10, 5
PAGES = [round(3.0 + i / 10, 1) for i in range(11)] + [4.1, 4.2, 4.3, 4.4, 4.5]
TOP_PAGES = {4.4, 4.5}
NOTE = ("Colleges don't all say whether their average is weighted. Many count honors and AP courses, so compare with "
        "your weighted GPA if you have one.")


def read(path):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def colleges():
    tiers = read(os.path.join(DATA, "tiering", "tiers.csv"))
    by_slug = {r["slug"]: r for r in tiers}
    by_slug.update({r["old_slug"]: r for r in tiers if r["old_slug"] and r["old_slug"] not in by_slug})
    inst = {r["unitid"]: r for r in read(os.path.join(DATA, "institutions.csv"))}
    out = []
    for r in read(os.path.join(DATA, "audit", "phase2_b2_gpa.csv")):
        t = by_slug.get(r["slug"])
        if not t or t["tier"] not in ("A", "B") or t["recommendation"] != "index":
            continue
        gpa = float(r["cds_gpa"])
        basis = r["cds_gpa_basis"] or ("weighted" if gpa > 4.0 else "")
        enrollment = (inst.get(r["unitid"], {}).get("undergrad_enrollment") or "").replace(",", "")
        out.append({
            "name": t["title"],
            "url": f"/admissions/{t['slug']}/",
            "gpa": r["cds_gpa"],
            "basis": basis,
            "year": r["cds_gpa_year"].replace("-", "–"),
            "tier": t["tier"],
            "state": t["state"],
            "enrollment": int(float(enrollment)) if enrollment.replace(".", "", 1).isdigit() else None,
        })
    return out


def order(c):
    return (c["tier"], -(c["enrollment"] or 0), c["name"])


def band(page, pool):
    weighted = page > 4.0
    pool = [c for c in pool if (float(c["gpa"]) > 4.0 and c["basis"] == "weighted") == weighted]
    key = f"{page:.1f}"
    if page in TOP_PAGES:
        top = sorted(pool, key=lambda c: (-float(c["gpa"]), order(c)))[:TOP]
        above_all = all(page > float(c["gpa"]) for c in pool)
        intro = (f"A {key} is above every average a college reports. The highest are:" if above_all
                 else "Few colleges report an average this high. The highest are:")
        return {"mode": "highest", "intro": intro, "colleges": top}
    windows = [0.15] if page == 3.0 else [0.05, 0.10]
    for w in windows:
        inside = [c for c in pool if abs(float(c["gpa"]) - page) <= w + 1e-9]
        if len(inside) >= WIDEN_UNDER:
            break
    if len(inside) < MIN_SHOWN:
        return None
    return {"mode": "typical", "window": w, "total": len(inside), "see_all": f"/admissions/?gpa={key}",
            "colleges": sorted(inside, key=order)[:SHOWN]}


def main():
    pool = colleges()
    lists = {}
    for page in PAGES:
        b = band(page, pool)
        if b:
            lists[f"{page:.1f}"] = b
    doc = {
        "about": "Colleges where a GPA is typical, from each college's own Common Data Set (C12) average as published on "
                 "its gpacalculator.net page; tier A/B indexed pages only. Built by scripts/admissions/gpa_bands.py; "
                 "rule in its docstring and PROJECT.md.",
        "heading": "Colleges where a {gpa} GPA is typical",
        "label": "as reported by the college",
        "weighted_note": NOTE,
        "colleges_counted": len(pool),
        "lists": lists,
    }
    with open(OUT, "w", encoding="utf-8") as f:
        json.dump(doc, f, ensure_ascii=False, indent=1)
        f.write("\n")
    print(f"{len(pool)} colleges; lists: " + ", ".join(
        f"{k} {len(v['colleges'])}" + (f"/{v['total']}" if v["mode"] == "typical" else " (highest)")
        for k, v in lists.items()))


if __name__ == "__main__":
    main()
