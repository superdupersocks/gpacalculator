"""QA for the admissions data scripts (scripts/admissions/build.py and match.py) on a small fixture.

Run: python3 tests/admissions_qa.py
The fixture (tests/fixtures/admissions/) mimics the IPEDS HD/ADM/IC files, their parsed dictionaries and the
College Scorecard institution file, with deliberate problems: suppressed values, an impossible score,
more admits than applicants, an alias, a typo and two posts for one college.
"""
import csv
import json
import shutil
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / "scripts" / "admissions"))
import build  # noqa: E402
import cds  # noqa: E402
import fetch  # noqa: E402
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
    eq("Harvard undergrads from IPEDS (not total enrollment)", h["undergrad_enrollment"], "7240")
    eq("Harvard tuition from the latest IC_AY year", (h["tuition_in_state"], h["tuition_out_of_state"]),
       ("59320", "59320"))
    eq("Harvard net price from the private variable", h["net_price"], "13900")
    eq("Harvard grad and retention rates as 0-1", (h["grad_rate"], h["retention_rate"]), ("0.97", "0.98"))
    eq("Harvard religious affiliation not applicable -> empty", h["religious_affiliation"], "")
    eq("Harvard not-applicable dates empty", (h["closed_date"], h["merged_into"]), ("", ""))

    a = rows["100751"]
    eq("Alabama impossible SAT math 900 dropped", (a["sat_math_p25"], a["sat_math_p75"]), ("540", ""))
    eq("Alabama suppressed earnings empty, not 0", a["median_earnings_10yr"], "")
    eq("Alabama grad rate falls back to Scorecard when IPEDS is empty", a["grad_rate"], "0.72")
    eq("Alabama imputed retention dropped, Scorecard fallback", a["retention_rate"], "0.87")
    eq("Alabama public net price", a["net_price"], "21500")

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
    eq("measure sources name the variable", (src["tuition_in_state"]["variable"], src["net_price"]["variable"]),
       ("CHG2AY3", "NPGRN2 / NPIST2"))
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

# Without the Scorecard file (its host refuses GitHub's runners) the build still runs on IPEDS alone.
with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    shutil.copytree(FIX, tmp / "fix")
    shutil.rmtree(tmp / "fix" / "raw" / "scorecard")
    build.main(["--raw", str(tmp / "fix" / "raw"), "--out", str(tmp)])
    rows = {r["unitid"]: r for r in csv.DictReader(open(tmp / "institutions.csv"))}
    h = rows["166027"]
    eq("IPEDS-only build keeps admissions and cost", (h["admit_rate"], h["tuition_in_state"]), ("0.0364", "59320"))
    eq("IPEDS-only build leaves Scorecard columns empty", (h["median_earnings_10yr"], h["accreditor"]), ("", ""))
    eq("IPEDS-only universe", sorted(rows), ["100751", "166027", "999001", "999003"])

# Common Data Set parsing: the three-column C11 table (total column wins) and C12 split across lines.
CDS_TEXT = """C11 Percentage of all enrolled, degree-seeking, first-time, first-year (freshman) students ...
Score Included Score Not Included Total All Students
Percent who had GPA of 4.0 60.10% 70.00% 65.06%
Percent who had GPA between 3.75 and 3.99 25.00% 20.00% 22.50%
Percent who had GPA between 3.50 and 3.74 10.00% 6.00% 8.00%
Percent who had GPA between 3.25 and 3.49 3.00% 2.00% 2.50%
Percent who had GPA between 3.00 and 3.24 1.00% 1.00% 1.00%
Percent who had GPA between 2.50 and 2.99 0.90% 1.00% 0.94%
Percent who had GPA between 2.0 and 2.49 0% 0% 0%
Percent who had GPA between 1.0 and 1.99 0% 0% 0%
Percent who had GPA below 1.0 0% 0% 0%
C12 Average high school GPA of all degree-seeking, first-time, first-year (freshman) students who submitted
GPA: 4.18
Percent of total first-time, first-year (freshman) students who submitted high school GPA: 82%"""
g = cds.parse(CDS_TEXT)
eq("CDS C12 average and submit %", (g.get("gpa_avg"), g.get("gpa_submit_pct")), (4.18, 82.0))
eq("CDS C11 total column", (g.get("gpa_4_0"), g.get("gpa_250_299"), g.get("gpa_below_100")), (65.06, 0.94, 0.0))
eq("CDS bands add up", cds.check(g), [])
g = cds.parse("C11\nPercent who had GPA of 4.0 0.4\nPercent who had GPA between 3.75 and 3.99 0.6\nC12")
eq("CDS Excel fractions become percents", (cds.check(g), g["gpa_4_0"]), ([], 40.0))
g = {"gpa_avg": 39.2}
eq("CDS impossible average flagged", cds.check(g), ["average GPA 39.2 outside 1-5"])

# IPEDS provisional release: the newest Tablesdoc on the Access page, its titles and labels per table, and
# sources marked as provisional.
page = ('<a href="/ipeds/tablefiles/tableDocs/IPEDS202324Tablesdoc.xlsx">2023-24</a>'
        '<a href="https://nces.ed.gov/ipeds/tablefiles/zipfiles/IPEDS_2023-24_Final.zip">2023-24</a>'
        '<a href="https://nces.ed.gov/ipeds/tablefiles/zipfiles/IPEDS_2024-25_Provisional.zip">2024-25</a>'
        '<a href="https://nces.ed.gov/ipeds/tablefiles/tableDocs/IPEDS202425Tablesdoc.xlsx">2024-25</a>')
eq("newest release on the Access page", fetch.newest_release(page),
   (2024, "https://nces.ed.gov/ipeds/tablefiles/tableDocs/IPEDS202425Tablesdoc.xlsx",
    "https://nces.ed.gov/ipeds/tablefiles/zipfiles/IPEDS_2024-25_Provisional.zip"))
eq("no release on the page", fetch.newest_release("<html></html>"), (None, None, None))
import io  # noqa: E402
import openpyxl  # noqa: E402
wb = openpyxl.Workbook()
ws = wb.active
ws.title = "Tables24"
ws.append(["TableName", "TableTitle"])
ws.append(["ADM2024", "Admissions"])
ws = wb.create_sheet("vartable24")
ws.append(["TableName", "varName", "varTitle", "DataType"])
ws.append(["ADM2024", "applcn", "Applicants total", "N"])
ws.append(["ADM2024", "ADMCON7", "Admission test scores", "N"])
ws = wb.create_sheet("valuesets24")
ws.append(["TableName", "varName", "Codevalue", "valueLabel"])
ws.append(["ADM2024", "ADMCON7", 1.0, "Required"])
buf = io.BytesIO()
wb.save(buf)
doc = fetch.parse_tablesdoc(buf.getvalue())
eq("Tablesdoc titles per table", doc["ADM2024"]["vars"]["APPLCN"]["title"], "Applicants total")
eq("Tablesdoc code labels", doc["ADM2024"]["codes"]["ADMCON7"], {"1": "Required"})
with tempfile.TemporaryDirectory() as tmp:
    tmp = Path(tmp)
    shutil.copytree(FIX, tmp / "fix")
    mf = tmp / "fix" / "manifest.json"
    m = json.loads(mf.read_text())
    m.setdefault("files", {})["ipeds_adm"] = {"release": "provisional"}
    m["files"]["ipeds_drvgr"] = {"release": "provisional"}
    mf.write_text(json.dumps(m))
    build.main(["--raw", str(tmp / "fix" / "raw"), "--out", str(tmp)])
    src = json.loads((tmp / "field_sources.json").read_text())
    eq("provisional ADM source marked", src["applicants"]["source"], "IPEDS ADM2024 provisional release")
    eq("provisional measure source marked", src["grad_rate"]["source"],
       "IPEDS DRVGR 2024 provisional release (else Scorecard)")
    eq("complete-file source unmarked", src["name"]["source"], "IPEDS HD2024")

print("\nALL PASSED" if not fails else f"\nFAILED: {len(fails)}")
sys.exit(1 if fails else 0)
