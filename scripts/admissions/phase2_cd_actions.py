#!/usr/bin/env python3
"""Turn the Phase 2 audit lists into the action list for checkpoints C and D.

    python3 scripts/admissions/phase2_cd_actions.py

Reads closed.csv, merged.csv and duplicates.csv in data/admissions/audit/ and writes phase2_cd_actions.csv there:
one row per post to unpublish, with what its addresses answer afterwards (410 Gone, or a 301 to the page named).
Merged posts whose successor's page is listed under a former name wait for E's identity review and get no row, nor
does anything in unmatched.csv or unconfirmed.csv. D_KEEP records which page of each duplicate pair stays (checkpoint
D); change it if Digant picks the other page. scripts/admissions/phase2_cd_live.sh applies the list.

Merged posts that would retire only because their successor has no page here are held (Digant, 2026-10-02 05:58 UTC)
and go to phase2_c_held.csv with a recommended treatment instead of the action list.

phase2_d_consolidate.csv lists what each surviving page in D takes from its duplicate before the 301 (Digant:
"Consolidate useful information into the surviving pages"): fields the survivor lacks, unless the Phase 1 record for
the college contradicts the value. B's four fields stay out, and the numbers that change every year (enrollment, net
price, scores) come from E's import with their year.
"""
import csv
import json
import re
import sys
from collections import Counter
from pathlib import Path

DATA = Path(__file__).resolve().parents[2] / "data"
AUDIT = DATA / "admissions" / "audit"
SITE = "https://gpacalculator.net/admissions/"
D_KEEP = {  # IPEDS ID shared by two posts -> the post that stays; the other gets a 301 to it
    "177302": "pinnacle-career-institute-north-kansas-city",
    "483212": "louisiana-delta-community-college",
    "485111": "georgia-military-college",
    "487320": "texas-state-technical-college",
}
COLS = ["checkpoint", "slug", "action", "target", "reason"]
HELD_COLS = ["slug", "successor_unitid", "successor_name", "recommendation"]
HELD_ADVICE = ("keep the page live and unchanged for now; when the fresh data import adds the successor's page, "
               "301 this one to it and move anything useful across")
# The simplest useful treatment by successor, from its Phase 1 record (data/admissions/institutions.csv).
UOP = "301 to one University of Phoenix page (IPEDS 484613, Phoenix AZ, 89,828 undergraduates) added with E's import"
HELD_BY_SUCCESSOR = {
    "168847": "add a page for Baker College (operating, 3,595 undergraduates) with E's import, then 301 this one to it",
    "498562": "add a page for Commonwealth University of Pennsylvania (operating, 9,847 undergraduates) with E's "
              "import, then 301 this one to it",
    "484613": UOP,
    "484631": UOP + "; University of Phoenix-California reports 24 undergraduates, too few for a page of its own",
    "484756": UOP + "; University of Phoenix-Texas reports 5 undergraduates, too few for a page of its own",
    "484710": "retire (410): University of Phoenix-Nevada closed 06/05/2023 (IPEDS HD2024 CLOSEDAT)",
    "494436": "keep the page and match it to IPEDS 494436 in E: the same Cookeville campus under a new ID",
    "133997": "retire (410): College Scorecard doesn't show Florida Career College-Miami as operating",
}
CONSOLIDATE_COLS = ["survivor", "field", "value", "from_slug", "check"]
# Page field -> the Phase 1 column that confirms or contradicts it (None: not in the Phase 1 data). The six admission
# requirements read "Not applicable" at open-admission colleges.
CONSOLIDATE = {
    "admission_requirements_completion_of_college_preparatory_program": "req_prep_program",
    "admission_requirements_demonstration_of_competencies": "req_competencies",
    "admission_requirements_high_school_class_rank": "req_class_rank",
    "admission_requirements_high_school_gpa": "req_gpa",
    "admission_requirements_recommendations": "req_recommendations",
    "admission_requirements_test_scores": "req_test_scores",
    "ap_credit": "ap_credit",
    "credit_for_life_experiences": "life_experience_credit",
    "dual_credit": None,
}


def read(name):
    with open(AUDIT / name, newline="") as f:
        return list(csv.DictReader(f))


def slug_of(url):
    m = re.fullmatch(re.escape(SITE) + r"([a-z0-9-]+)/", url)
    if not m:
        sys.exit(f"not a college page address: {url!r}")
    return m.group(1)


def build():
    pairs = {}
    for r in read("duplicates.csv"):
        pairs.setdefault(r["unitid"], []).append(r["slug"])
    if pairs.keys() != D_KEEP.keys() or any(D_KEEP[u] not in s for u, s in pairs.items()):
        sys.exit("duplicates.csv no longer matches D_KEEP; update D_KEEP first")
    kept = {s: D_KEEP[u] for u, slugs in pairs.items() for s in slugs}

    held = []
    rows = [["C", r["slug"], "retire", "", f"closed {r['closed_on']} (IPEDS {r['unitid']})"]
            for r in read("closed.csv")]
    for r in read("merged.csv"):
        t = r["treatment"]
        why = f"merged into {r['successor_name']} (IPEDS {r['successor_unitid']})"
        if t.startswith("301 to whichever of "):
            names = re.fullmatch(r"301 to whichever of (.+) survives checkpoint D", t).group(1).split(", ")
            target = {kept[n] for n in names}
            if len(target) != 1:
                sys.exit(f"{r['slug']}: no single page kept in D among {names}")
            rows.append(["C", r["slug"], "301", SITE + target.pop() + "/", why])
        elif t.startswith("301 to ") and "identity review" in t:
            continue
        elif t.startswith("301 to "):
            rows.append(["C", r["slug"], "301", r["successor_url"], why])
        elif t.startswith("retire like a closure"):
            if "is no longer listed either" in t:
                rows.append(["C", r["slug"], "retire", "", why + "; the successor closed too"])
            else:
                held.append([r["slug"], r["successor_unitid"], r["successor_name"],
                             HELD_BY_SUCCESSOR.get(r["successor_unitid"], HELD_ADVICE)])
        else:
            sys.exit(f"{r['slug']}: unknown treatment {t!r}")
    for u, slugs in sorted(pairs.items()):
        rows += [["D", s, "301", SITE + D_KEEP[u] + "/", f"same college as {D_KEEP[u]} (IPEDS {u})"]
                 for s in slugs if s != D_KEEP[u]]

    listed = Counter(r[1] for r in rows)
    twice = sorted(s for s, n in listed.items() if n > 1)
    if twice:
        sys.exit(f"posts listed twice: {twice}")
    chained = [r[1] for r in rows if r[3] and slug_of(r[3]) in listed]
    if chained:
        sys.exit(f"301s to pages this list also unpublishes: {chained}")
    return rows, held


def page_fields(slug):
    with open(DATA / "colleges" / f"{slug}.json") as f:
        return json.load(f)["fields"]


def consolidate(rows):
    with open(DATA / "admissions" / "institutions.csv", newline="") as f:
        inst = {r["unitid"]: r for r in csv.DictReader(f)}
    out = []
    for _, dup, _, target, reason in (r for r in rows if r[0] == "D"):
        keep = slug_of(target)
        have, give = page_fields(keep), page_fields(dup)
        rec = inst[re.search(r"\(IPEDS (\d+)\)", reason).group(1)]
        for field, col in CONSOLIDATE.items():
            value = give.get(field, "").strip()
            if have.get(field, "").strip() not in ("", "-") or value in ("", "-"):
                continue
            known = rec.get(col, "") if col else ""
            if value == "Not applicable":
                ok, check = rec["open_admission"] == "Yes", "open admission in IPEDS"
            elif known:
                ok, check = value == known, f"IPEDS {col}: {known}"
            else:
                ok, check = True, "not in the Phase 1 data"
            if ok:
                out.append([keep, field, value, dup, check])
            else:
                print(f"not copied to {keep}: {field} = {value!r} ({check})")
    return out


def main():
    rows, held = build()
    copies = consolidate(rows)
    for name, cols, data in (("phase2_cd_actions.csv", COLS, rows), ("phase2_c_held.csv", HELD_COLS, held),
                             ("phase2_d_consolidate.csv", CONSOLIDATE_COLS, copies)):
        with open(AUDIT / name, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(cols)
            w.writerows(data)
    print(f"held: {len(held)}; D fields to copy: {len(copies)}")
    for (cp, action), n in sorted(Counter((r[0], r[2]) for r in rows).items()):
        print(f"{cp} {action}: {n}")


if __name__ == "__main__":
    main()
