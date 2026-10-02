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
    eq("Harvard net price by income from SFA titles", h["net_price_0_30k"], "1000")
    eq("optional DRVIC absent leaves cost of attendance empty", h["cost_in_state_on_campus"], "")
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
eq("CDS impossible average flagged", cds.check({"gpa_avg": 39.2}), [("gpa_avg", "average GPA 39.2 outside 1-5")])
g = cds.parse("C9 Percent and number of first-time, first-year students enrolled ...\n"
              "Submitting SAT Scores 54% 1046\nSubmitting ACT Scores 21% 405\n"
              "SAT Composite 1500 1540 1570\nSAT Evidence-Based Reading and Writing 740 760 780\n"
              "SAT Math 760 780 800\nACT Composite 34 35 36\nC10")
eq("CDS C9 percentiles and submit %", (g.get("sat_comp_p50"), g.get("sat_erw_p25"), g.get("act_comp_p75"),
                                       g.get("sat_submit_pct")), (1540, 740, 36, 54.0))
eq("CDS form values decoded", (cds.clean("level", "/VI"), cds.clean("yn", "/Y"), cds.clean("pct", "0.25"),
                               cds.clean("count", "1,234"), cds.clean("gpa", "N/A")),
   ("Very Important", "Yes", 25.0, 1234, None))
eq("CDS form fields mapped by template year",
   cds.from_form({"FRSH_GPA": "3.91", "Q111_3": "/VI", "AP_RECD_1ST_N": "1000", "AP_ADMT_1ST_N": "250"}, "2025-26"),
   {"applicants": 1000, "admits": 250, "factor_gpa": "Very Important", "gpa_avg": 3.91})
src = {"unitid": "1", "name": "X", "cds_year": "2023-24", "source_url": "https://x.edu/cds.pdf"}
vals, prov, rev = cds.decide(src, {"admissions_year": "2023", "sat_math_p25": "760", "applicants": "500"},
                             {"factor_gpa": "Important"}, {"gpa_avg": 3.9, "sat_math_p50": 780},
                             {"C.1201": "3.90", "C.911": "760", "C.912": "790", "C.117": "500", "C.1202": "88"})
eq("CDS decide: form, text+collegedata, collegedata+IPEDS",
   {k: vals.get(k) for k in ("factor_gpa", "gpa_avg", "sat_math_p25", "applicants")},
   {"factor_gpa": "Important", "gpa_avg": 3.9, "sat_math_p25": 760, "applicants": 500})
vals2, _, _ = cds.decide(src, None, {}, {}, {"C.701": "Very Important"}, "tier1_xlsx")
eq("CDS decide: collegedata.fyi's exact XLSX read is trusted", vals2.get("factor_rigor"), "Very Important")
vals2, _, _ = cds.decide(src, None, {}, {}, {"C.701": "Very Important"}, "tier4_docling")
eq("CDS decide: a layout-model read alone is not", vals2.get("factor_rigor"), None)
eq("CDS decide: disagreement and single readings go to review",
   sorted(r["field"] for r in rev), ["gpa_submit_pct", "sat_math_p50"])

import io  # noqa: E402
import zipfile  # noqa: E402
_buf = io.BytesIO()
with zipfile.ZipFile(_buf, "w") as _z:
    _z.writestr("word/document.xml", "<w:document><w:body><w:tbl><w:tr><w:tc><w:p><w:t>Average high school GPA of "
                "all degree-seeking, first-time, first-year students who submitted GPA:</w:t></w:p></w:tc><w:tc><w:p>"
                "<w:t>3.87</w:t></w:p></w:tc></w:tr></w:tbl></w:body></w:document>")
eq("CDS Word file read", cds.parse(cds.text_of(_buf.getvalue())).get("gpa_avg"), 3.87)

import accreditation  # noqa: E402
_buf = io.BytesIO()
with zipfile.ZipFile(_buf, "w") as _z:
    _z.writestr("AccreditationRecords.csv",
                "DapipId,Institution Name,Ipeds UnitIds,Agency Name,Program Name,Accreditation Status,Accreditation Date\n"
                "1,Harvard University,166027,New England Commission of Higher Education,Institutional Accreditation,"
                "Accredited,2018-04-01\n"
                "1,Harvard University,166027,Liaison Committee on Medical Education,Medical Education,Accredited,2020-01-01\n"
                "2,Gone College,999001,Some Agency,Institutional Accreditation,Terminated,2019-01-01\n")
_acc = accreditation.accreditors(accreditation.tables(_buf.getvalue()))
eq("DAPIP institutional accreditor only, active only", _acc,
   {"166027": ("New England Commission of Higher Education", "Accredited", "2018-04-01")})

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
eq("provisional table without net price is skipped", fetch.usable("sfa", {"vars": {}}),
   ["average net price-students awarded grant or scholarship aid"])
eq("provisional ADM with the counts is used", fetch.usable("adm", {"vars": {v: {"title": ""} for v in
                                                                         ("APPLCN", "ADMSSN", "ENRLT")}}), [])
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
