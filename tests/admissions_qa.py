"""QA for the admissions data scripts (scripts/admissions/build.py and match.py) on a small fixture.

Run: python3 tests/admissions_qa.py
The fixture (tests/fixtures/admissions/) mimics the IPEDS HD/ADM/IC files, their parsed dictionaries and the
College Scorecard institution file, with deliberate problems: suppressed values, an impossible score,
more admits than applicants, an alias, a typo and two posts for one college.
"""
import csv
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts" / "admissions"))
import build  # noqa: E402
import match  # noqa: E402

FIX = ROOT / "tests" / "fixtures" / "admissions"
fails = []


def eq(label, got, want):
    ok = got == want
    print(f"{'PASS' if ok else 'FAIL'} {label}: {got!r}" + ("" if ok else f" (want {want!r})"))
    if not ok:
        fails.append(label)


with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    build.main(["--raw", str(FIX / "raw"), "--out", str(tmp)])
    rows = {r["unitid"]: r for r in csv.DictReader(open(tmp / "institutions.csv"))}
    src = json.loads((tmp / "field_sources.json").read_text())
    report = (tmp / "qa_report.md").read_text()

    h = rows["166027"]
    eq("universe skips admin units (sector 0)", sorted(rows), ["100751", "166027", "888001", "999001", "999003"])
    eq("Harvard admit rate from IPEDS counts", h["admit_rate"], "0.0364")
    eq("Harvard yield", h["yield_rate"], "0.8377")
    eq("Harvard admissions source", (h["admissions_source"], h["admissions_year"]), ("IPEDS ADM", "2024"))
    eq("Harvard SAT ERW p25/p50/p75", (h["sat_erw_p25"], h["sat_erw_p50"], h["sat_erw_p75"]), ("740", "760", "780"))
    eq("Harvard ACT composite median", h["act_comp_p50"], "35")
    eq("Harvard test policy decoded", h["req_test_scores"], "Considered but not required")
    eq("Harvard essay requirement", h["req_essay"], "Required")
    eq("Harvard AP credit / dual credit", (h["ap_credit"], h["dual_credit"]), ("Yes", "No"))
    eq("Harvard control and level", (h["control"], h["level"]), ("Private not-for-profit", "Four or more years"))
    eq("Harvard Carnegie uses newest C??BASIC", h["carnegie"], "Doctoral Universities: Very High Research Activity")
    eq("Harvard negative longitude kept", h["lon"], "-71.118")
    eq("Harvard undergrads (not total enrollment)", h["undergrad_enrollment"], "7110")
    eq("Harvard net price falls back to private", h["net_price"], "14327")
    eq("Harvard religious affiliation not applicable -> empty", h["religious_affiliation"], "")
    eq("Harvard not-applicable dates empty", (h["closed_date"], h["merged_into"]), ("", ""))

    a = rows["100751"]
    eq("Alabama impossible SAT math 900 dropped", (a["sat_math_p25"], a["sat_math_p75"]), ("540", ""))
    eq("Alabama suppressed earnings empty, not 0", a["median_earnings_10yr"], "")
    eq("Alabama grad rate from C150_L4 when C150_4 is null", a["grad_rate"], "0.72")
    eq("Alabama public net price", a["net_price"], "22000")

    s = rows["888001"]
    eq("Scorecard-only row uses Scorecard admissions", (s["admissions_source"], s["admit_rate"]),
       ("College Scorecard", "0.65"))
    eq("Scorecard-only row has no IPEDS counts", s["applicants"], "")
    eq("Scorecard predominant degree decoded", s["predominant_degree"], "Associate's")

    c = rows["999003"]
    eq("more admits than applicants dropped", (c["applicants"], c["admits"], c["admit_rate"]), ("", "", ""))
    eq("religious affiliation decoded", c["religious_affiliation"], "Roman Catholic")
    eq("closed college flagged", (rows["999001"]["active"], rows["999001"]["closed_date"]), ("No", "2024-06-30"))

    eq("field sources cite ADM year and variable", (src["applicants"]["source"], src["applicants"]["variable"]),
       ("IPEDS ADM2024", "APPLCN"))
    eq("ADMCON mapped by dictionary title", src["req_legacy"]["variable"], "ADMCON12")
    eq("report lists dropped values", "more admits than applicants" in report and "outside 200-800" in report, True)

    res = {r["slug"]: r for r in match.main(["--colleges", str(FIX / "colleges"),
                                             "--institutions", str(tmp / "institutions.csv"),
                                             "--out", str(tmp)])}
    eq("exact match", (res["harvard"]["method"], res["harvard"]["unitid"]), ("exact", "166027"))
    eq("'The' and 'of' ignored", (res["alabama"]["method"], res["alabama"]["unitid"]), ("exact", "100751"))
    eq("St. -> Saint", (res["saint-example"]["method"], res["saint-example"]["unitid"]), ("exact", "999003"))
    eq("alias match", (res["example-college"]["method"], res["example-college"]["unitid"]), ("alias", "999003"))
    eq("two posts for one college flagged", res["example-college"].get("duplicate_of"), "saint-example")
    eq("typo matched fuzzily", (res["closed-example"]["method"], res["closed-example"]["unitid"]),
       ("fuzzy", "999001"))
    eq("no candidate", res["gone-forever"]["method"], "none")
    review = list(csv.DictReader(open(tmp / "match_review.csv")))
    eq("review list", sorted(r["slug"] for r in review), ["example-college", "gone-forever", "saint-example"])

print("\nALL PASSED" if not fails else f"\nFAILED: {len(fails)}")
sys.exit(1 if fails else 0)
