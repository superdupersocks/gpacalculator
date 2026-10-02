#!/usr/bin/env python3
"""The fresh federal data for the college pages (Admissions Phase 2, checkpoint E).

    python3 scripts/admissions/phase2_e_import.py

Digant (2026-10-02): "Update confidently matched posts with verified Phase 1 values, source citations and reporting
years. Review uncertain matches separately. Confirm the templates support the new fields and labels before
importing." A post gets the import when:

- match.csv matched it confidently (exact, renamed or alias) and the audit didn't correct the match;
- checkpoints C and D left it published (not in phase2_cd_actions.csv) and C didn't hold it (phase2_c_held.csv);
- College Scorecard (June 2026) lists the college as operating. IPEDS 2024 still lists colleges that closed in
  2024-25, so a college Scorecard marks closed or no longer lists waits, as does one the audit left unconfirmed;
- IPEDS 2024 has a record of its own. A branch campus that only College Scorecard lists (its parent college reports
  for it) has no fresh figures, so its page waits for a decision on how to show the parent's data.

Each imported page's fields are replaced as the Phase 1 plan approved, and every value comes from one named source
and year; a field with no fresh value is emptied rather than left with the old, unsourced one:

- location, owning (control and level), acceptance rate, applicant and admit counts, open admission: IPEDS;
- SAT and ACT 25th/50th/75th percentiles and the share of enrollees submitting each: IPEDS ADM (fall entrants);
- the average SAT: College Scorecard's own estimate, labeled as such (the plan's mapping);
- undergraduate enrollment and average net price: IPEDS only. College Scorecard's fallback values are left out
  because their year isn't stated in its file; they are counted in the report;
- the admission factors (ADMCON) and credit policies: IPEDS, in IPEDS's own wording.

Writes data/admissions/audit/phase2_e_import.csv (one row per post, the fields scripts/admissions/phase2_e_live.sh
writes), phase2_e_held.csv (confident matches that wait, with the reason) and phase2_e_summary.md (counts).
"""
import csv
import json
import re
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from common import STATES  # noqa: E402

DATA = Path(__file__).resolve().parents[2] / "data" / "admissions"
AUDIT = DATA / "audit"
CONFIDENT = {"exact", "renamed", "alias"}
STATE_NAMES = {}
for _name, _code in STATES.items():
    STATE_NAMES.setdefault(_code, _name)  # "Virgin Islands" before "U.S. Virgin Islands"

CONTROL = {"Public": "Public", "Private not-for-profit": "Private nonprofit", "Private for-profit": "Private for-profit"}
LEVEL = {"Four or more years": "4-year", "At least 2 but less than 4 years": "2-year",
         "Less than 2 years (below associate)": "less than 2 years"}

# Theme field <- institutions.csv column, for the admission factors IPEDS reports (its wording, kept as is).
REQUIREMENTS = {
    "admission_requirements_test_scores": "req_test_scores",
    "admission_requirements_high_school_gpa": "req_gpa",
    "admission_requirements_high_school_class_rank": "req_class_rank",
    "admission_requirements_completion_of_college_preparatory_program": "req_prep_program",
    "admission_requirements_recommendations": "req_recommendations",
    "admission_requirements_demonstration_of_competencies": "req_competencies",
    # not on the pages before E
    "admission_requirements_secondary_school_record": "req_hs_record",
    "admission_requirements_personal_statement_or_essay": "req_essay",
    "admission_requirements_legacy_status": "req_legacy",
    "admission_requirements_work_experience": "req_work_experience",
    "admission_requirements_other_test": "req_other_test",
}
SCORES = {  # theme field <- institutions.csv column
    "sat_reading_25": "sat_erw_p25", "sat_reading_50": "sat_erw_p50", "sat_reading_75": "sat_erw_p75",
    "sat_math_25": "sat_math_p25", "sat_math_50": "sat_math_p50", "sat_math_75": "sat_math_p75",
    "act_composite_25": "act_comp_p25", "act_composite_50": "act_comp_p50", "act_composite_75": "act_comp_p75",
    "act_english_25": "act_english_p25", "act_english_50": "act_english_p50", "act_english_75": "act_english_p75",
    "act_math_25": "act_math_p25", "act_math_50": "act_math_p50", "act_math_75": "act_math_p75",
}
# Old fields with no fresh source: emptied on imported pages (the plan's drops and renames).
EMPTIED = ["sat_composite_25", "sat_composite_75", "sat_range", "act_reading_25", "act_reading_75",
           "average_act_score", "dual_credit"]

COLUMNS = (["slug", "post_title", "ipeds_unitid", "ipeds_name", "college_city", "college_state", "college_control",
            "college_level", "location", "owning", "adm_year", "adm_open_admission", "open_admission_year",
            "adm_applicants", "adm_admits",
            "acceptance_rate", "enrollment", "enrollment_year", "net_price", "net_price_year", "net_price_scope",
            "applicants_submitting_sat", "applicant_submitting_act", "act_range", "average_sat_score",
            "average_sat_score_source"]
           + list(SCORES) + list(REQUIREMENTS) + ["ap_credit", "credit_for_life_experiences", "credits_year"]
           + EMPTIED + ["ipeds_release", "scorecard_release"])
HELD_COLUMNS = ["slug", "post_title", "unitid", "ipeds_name", "why"]


def read(path):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def whole(v):
    return str(int(round(float(v)))) if v not in ("", None) else ""


def pct_text(rate):
    """0.0364 -> '3.6%', 0.43 -> '43%': one decimal under 10%, so very selective colleges keep their precision."""
    p = float(rate) * 100
    if round(p, 1) < 10:
        return f"{round(p, 1):.1f}".rstrip("0").rstrip(".") + "%"
    return f"{round(p):.0f}%"


def academic_year(year):
    """2024 -> '2024–25'."""
    y = int(year)
    return f"{y}–{str(y + 1)[-2:]}"


def meaning_year(meaning):
    """'Average net price-..., 2022-23' -> '2022–23'."""
    m = re.search(r"(\d{4})-(\d{2})\b", meaning or "")
    return f"{m.group(1)}–{m.group(2)}" if m else ""


def row_for(m, i, years):
    out = {"slug": m["slug"], "post_title": m["title"], "ipeds_unitid": i["unitid"], "ipeds_name": i["name"],
           "college_city": i["city"], "college_state": i["state"], "college_control": i["control"],
           "college_level": i["level"], "ipeds_release": years["ipeds_release"],
           "scorecard_release": years["scorecard_release"]}
    state = STATE_NAMES.get(i["state"], i["state"])
    out["location"] = f"{i['city']}, {state}" if i["city"] and state else ""
    out["owning"] = ", ".join(x for x in (CONTROL.get(i["control"], ""), LEVEL.get(i["level"], "")) if x)

    out["adm_open_admission"] = i["open_admission"]
    out["open_admission_year"] = years["credits"] if i["open_admission"] else ""  # IPEDS IC, like the credit policies
    ipeds_adm = i["admissions_source"] == "IPEDS ADM"
    out["adm_year"] = i["admissions_year"] if ipeds_adm else ""
    out["adm_applicants"] = whole(i["applicants"]) if ipeds_adm else ""
    out["adm_admits"] = whole(i["admits"]) if ipeds_adm else ""
    # A college that admitted none of a handful of applicants has no meaningful rate: the counts still show it
    admitted = ipeds_adm and i["admit_rate"] and float(i["admits"] or 0) > 0
    out["acceptance_rate"] = pct_text(i["admit_rate"]) if admitted else ""
    out["applicants_submitting_sat"] = f"{whole(i['sat_submit_pct'])}%" if ipeds_adm and i["sat_submit_pct"] else ""
    out["applicant_submitting_act"] = f"{whole(i['act_submit_pct'])}%" if ipeds_adm and i["act_submit_pct"] else ""
    for field, col in SCORES.items():
        out[field] = whole(i[col]) if ipeds_adm else ""
    lo, hi = out["act_composite_25"], out["act_composite_75"]
    out["act_range"] = f"{lo}-{hi}" if lo and hi else ""
    out["average_sat_score"] = whole(i["sat_avg"])
    out["average_sat_score_source"] = "College Scorecard" if out["average_sat_score"] else ""
    for field, col in REQUIREMENTS.items():
        out[field] = i[col] if ipeds_adm else ""

    enr = i["undergrad_enrollment"]
    if enr and i["undergrad_enrollment_source"] == "IPEDS" and int(float(enr)) > 0:
        out["enrollment"], out["enrollment_year"] = f"{int(float(enr)):,}", years["enrollment"]
    else:
        out["enrollment"], out["enrollment_year"] = "", ""
    price = i["net_price"]
    if price and i["net_price_source"] == "IPEDS" and int(float(price)) > 0:
        out["net_price"], out["net_price_year"] = f"${int(float(price)):,}", years["net_price"]
        out["net_price_scope"] = "in-state" if i["control"] == "Public" else ""
    else:
        out["net_price"], out["net_price_year"], out["net_price_scope"] = "", "", ""

    out["ap_credit"] = i["ap_credit"]
    out["credit_for_life_experiences"] = i["life_experience_credit"]
    out["credits_year"] = years["credits"] if (i["ap_credit"] or i["life_experience_credit"]) else ""
    for field in EMPTIED:
        out[field] = ""
    return out


def hold_reason(m, i, corrected, held_c, unconfirmed):
    """Why a confidently matched post waits instead of getting the import, or '' when it doesn't."""
    if m["slug"] in corrected:
        c = corrected[m["slug"]]
        return f"the audit corrected this match ({c['outcome']}, UNITID {c['unitid']}): review first"
    if m["slug"] in held_c:
        return "merged college held by checkpoint C until its successor has a page"
    if i is None:
        return f"UNITID {m['unitid']} isn't in institutions.csv"
    if i["closed_date"]:
        return f"IPEDS lists a closing date ({i['closed_date']})"
    if m["slug"] in unconfirmed:
        return "not operating according to College Scorecard, no closing date in IPEDS (audit: unconfirmed)"
    if i["operating"] == "No":
        return "College Scorecard (June 2026) says it no longer operates"
    if i["operating"] != "Yes":
        return "open in IPEDS 2024 but missing from College Scorecard's June 2026 release (may have closed since)"
    if not (i["control"] and i["level"]):
        return ("no IPEDS 2024 record of its own: only College Scorecard lists it, usually a branch campus "
                "whose parent college reports for it")
    return ""


def main():
    inst = {r["unitid"]: r for r in read(DATA / "institutions.csv")}
    for need in ("undergrad_enrollment_source", "net_price_source"):
        if need not in next(iter(inst.values())):
            raise SystemExit(f"institutions.csv has no {need} column: run build.py first")
    src = json.loads((DATA / "field_sources.json").read_text())
    manifest = json.loads((DATA / "manifest.json").read_text())
    scorecard = json.loads((DATA / "scorecard" / "source.json").read_text())
    adm_file = manifest["files"]["ipeds_adm"]
    years = {
        "enrollment": str(src["undergrad_enrollment"]["year"]),
        "net_price": meaning_year(src["net_price"]["meaning"]),
        "credits": academic_year(src["ap_credit"]["year"]),
        "ipeds_release": academic_year(adm_file["year"]) + (" provisional" if adm_file.get("release") == "provisional" else ""),
        "scorecard_release": scorecard.get("release", ""),
    }
    if not years["net_price"]:
        raise SystemExit(f"no academic year in the net price source: {src['net_price']['meaning']!r}")

    corrected = {r["slug"]: r for r in read(AUDIT / "corrections.csv")}
    acted = {r["slug"]: r for r in read(AUDIT / "phase2_cd_actions.csv")}
    held_c = {r["slug"] for r in read(AUDIT / "phase2_c_held.csv")}
    unconfirmed = {r["slug"] for r in read(AUDIT / "unconfirmed.csv")}

    rows, held, notes = [], [], Counter()
    for m in read(DATA / "match.csv"):
        if m["method"] not in CONFIDENT or not m["unitid"] or m["slug"] in acted:
            continue
        u = m["unitid"]
        i = inst.get(u)
        why = hold_reason(m, i, corrected, held_c, unconfirmed)
        if why:
            held.append({"slug": m["slug"], "post_title": m["title"], "unitid": u,
                         "ipeds_name": i["name"] if i else "", "why": why})
            continue
        r = row_for(m, i, years)
        rows.append(r)
        if i["undergrad_enrollment"] and i["undergrad_enrollment_source"] != "IPEDS":
            notes["enrollment from College Scorecard left out"] += 1
        if i["net_price"] and i["net_price_source"] != "IPEDS":
            notes["net price from College Scorecard left out"] += 1
        if i["admissions_source"] == "College Scorecard":
            notes["admissions figures from College Scorecard left out"] += 1

    by_unitid = Counter(r["ipeds_unitid"] for r in rows)
    shared = {u for u, n in by_unitid.items() if n > 1}
    if shared:
        raise SystemExit(f"two posts would get one college's data: {sorted(shared)}")

    with open(AUDIT / "phase2_e_import.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(sorted(rows, key=lambda r: r["slug"]))
    with open(AUDIT / "phase2_e_held.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=HELD_COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(sorted(held, key=lambda r: r["slug"]))

    filled = Counter(k for r in rows for k, v in r.items() if v)
    lines = ["# Checkpoint E: fresh data import", "",
             f"Years: admissions fall {next((r['adm_year'] for r in rows if r['adm_year']), '?')}, enrollment fall "
             f"{years['enrollment']}, net price {years['net_price']}, credit policies {years['credits']}; IPEDS "
             f"{years['ipeds_release']} release, College Scorecard {years['scorecard_release']}.", "",
             f"{len(rows):,} pages get the import; {len(held):,} confident matches wait (phase2_e_held.csv).", "",
             "| Held because | Pages |", "| --- | --- |"]
    lines += [f"| {k} | {v} |" for k, v in Counter(h["why"].split(" (")[0].split(":")[0] for h in held).most_common()]
    lines += ["", "| Imported pages with a value | Pages |", "| --- | --- |"]
    lines += [f"| {k} | {filled[k]:,} |" for k in COLUMNS if k not in ("slug", "post_title") and k not in EMPTIED]
    lines += ["", "Left out:"] + [f"- {k}: {v}" for k, v in notes.most_common()]
    (AUDIT / "phase2_e_summary.md").write_text("\n".join(lines) + "\n")
    print(f"import {len(rows)}, held {len(held)}; " + ", ".join(f"{k} {v}" for k, v in notes.most_common()))


if __name__ == "__main__":
    main()
