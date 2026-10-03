#!/usr/bin/env python3
"""Pages for the colleges that now report for held campuses, and redirects for those campuses (Admissions Phase 2,
after E). Nothing here runs until Digant decides.

    python3 scripts/admissions/phase2_s_pages.py

Checkpoint C held 25 merged colleges' pages because the college they merged into has no page here, and E held 27
branch campuses with no IPEDS record of their own, because their parent college reports for them. The recommendation
for both (phase2_c_held.csv, phase2_e_held.csv): give the college that reports for them a page with E's fresh data,
then 301 the old pages to it, or just the 301 where that college already has a page. This writes:

- phase2_s_new.csv: the six pages to add (slug, title), one per college in PAGES;
- phase2_s_pages.csv: E's fields for those six pages, and for fortis-institute, which C found is the same Cookeville
  campus under a new IPEDS ID (494436). Same columns as phase2_e_import.csv;
- phase2_s_actions.csv: checkpoint S, 301s to the new pages and 410s where the college a page merged into has closed,
  and checkpoint M, 301s to a parent college's existing page. Same columns as phase2_cd_actions.csv.

scripts/admissions/phase2_s_live.sh adds the pages, phase2_e_live.sh re-matches fortis-institute and
phase2_cd_live.sh runs S and M (docs/ADMISSIONS_PHASE2_RUNBOOK.md).
"""
import csv
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import phase2_e_import as e  # noqa: E402

AUDIT = e.AUDIT
SITE = "https://gpacalculator.net/admissions/"

# The pages to add: IPEDS UNITID -> (slug, title). Each is the college C's or E's held pages point to.
PAGES = {
    "168847": ("baker-college", "Baker College"),
    "484613": ("university-of-phoenix", "University of Phoenix"),
    "498562": ("commonwealth-university-of-pennsylvania", "Commonwealth University of Pennsylvania"),
    "150987": ("ivy-tech-community-college", "Ivy Tech Community College"),
    "145707": ("illinois-eastern-community-colleges", "Illinois Eastern Community Colleges"),
    "173735": ("minnesota-north-college", "Minnesota North College"),
}
# University of Phoenix's California and Texas units report 24 and 5 undergraduates: C recommended one University of
# Phoenix page, the Arizona unit's, for every campus page.
SAME_PAGE = {"484631": "484613", "484756": "484613"}
# C-held pages that are the college itself under a new IPEDS ID: they keep their page and get E's data.
REMATCH = {"fortis-institute": "494436"}
ACTION_COLUMNS = ["checkpoint", "slug", "action", "target", "reason"]


def c_action(row, inst, page_of):
    """What happens to one page C held: ("rematch", unitid), ("retire", reason) or ("301", unitid of the target)."""
    if row["slug"] in REMATCH:
        return "rematch", REMATCH[row["slug"]]
    u = SAME_PAGE.get(row["successor_unitid"], row["successor_unitid"])
    i = inst[u]
    if i["closed_date"]:
        return "retire", f"merged into {i['name']}, which closed {i['closed_date']} (IPEDS)"
    if i["operating"] != "Yes":
        return "retire", f"merged into {i['name']}, which College Scorecard (June 2026) doesn't list as operating"
    if u in page_of or u in PAGES:
        return "301", u
    raise SystemExit(f"{row['slug']}: no page for UNITID {u}")


def e_action(row, inst, page_of):
    """Where a branch campus E held goes: the UNITID of the college that reports for it, or '' (it stays held)."""
    if not row["why"].startswith("no IPEDS 2024 record"):
        return ""
    parent = e.parent_of(row["unitid"], inst)
    return parent if parent in page_of or parent in PAGES else ""


def build(c_held, e_held, inst, page_of, years):
    """The new pages, their E rows (and the re-matched ones) and the S and M redirect actions."""
    new, rows, actions = [], [], []
    for u, (slug, title) in PAGES.items():
        if u in page_of:
            raise SystemExit(f"UNITID {u} already has a page: /admissions/{page_of[u]}/")
        new.append({"slug": slug, "post_title": title})
        rows.append(e.row_for({"slug": slug, "title": title}, inst[u], years))
    target = {u: page_of.get(u) or PAGES[u][0] for u in set(page_of) | set(PAGES)}
    for r in c_held:
        what, value = c_action(r, inst, page_of)
        if what == "rematch":
            rows.append(e.row_for({"slug": r["slug"], "title": r["post_title"]}, inst[value], years))
        elif what == "retire":
            actions.append({"checkpoint": "S", "slug": r["slug"], "action": "retire", "target": "", "reason": value})
        else:
            actions.append({"checkpoint": "S" if value in PAGES else "M", "slug": r["slug"], "action": "301",
                            "target": f"{SITE}{target[value]}/",
                            "reason": f"merged into {inst[r['successor_unitid']]['name']} (C)"})
    for r in e_held:
        parent = e_action(r, inst, page_of)
        if parent:
            actions.append({"checkpoint": "S" if parent in PAGES else "M", "slug": r["slug"], "action": "301",
                            "target": f"{SITE}{target[parent]}/",
                            "reason": f"branch campus; {inst[parent]['name']} reports for it (IPEDS)"})
    return new, rows, sorted(actions, key=lambda a: (a["checkpoint"], a["slug"]))


def main():
    inst = e.institutions()
    years = e.source_years()
    page_of = {r["ipeds_unitid"]: r["slug"] for r in e.read(AUDIT / "phase2_e_import.csv")}
    title = {m["slug"]: m["title"] for m in e.read(e.DATA / "match.csv")}
    c_held = [dict(r, post_title=title[r["slug"]]) for r in e.read(AUDIT / "phase2_c_held.csv")]  # E rows need it
    new, rows, actions = build(c_held, e.read(AUDIT / "phase2_e_held.csv"), inst, page_of, years)

    for name, cols, data in (("phase2_s_new.csv", ["slug", "post_title"], new),
                             ("phase2_s_pages.csv", e.COLUMNS, rows),
                             ("phase2_s_actions.csv", ACTION_COLUMNS, actions)):
        with open(AUDIT / name, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=cols, lineterminator="\n")
            w.writeheader()
            w.writerows(data)
    n = Counter((a["checkpoint"], a["action"]) for a in actions)
    print(f"{len(new)} new pages, {len(rows) - len(new)} re-matched; S: {n[('S', '301')]} x 301, "
          f"{n[('S', 'retire')]} x 410; M: {n[('M', '301')]} x 301")


if __name__ == "__main__":
    main()
