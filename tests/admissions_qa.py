"""QA for the admissions data scripts (scripts/admissions/build.py and match.py) on a small fixture.

Run: python3 tests/admissions_qa.py
The fixture (tests/fixtures/admissions/) mimics the IPEDS HD/ADM/IC files, their parsed dictionaries and the
College Scorecard institution file, with deliberate problems: suppressed values, an impossible score,
more admits than applicants, an alias, a typo and two posts for one college.
"""
import csv
import io
import json
import shutil
import sys
import tempfile
import zipfile
from pathlib import Path

import openpyxl

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
form = cds.from_form({"FRSH_GPA": "3.91", "Q111_3": "/VI", "AP_RECD_1ST_N": "1000", "AP_ADMT_1ST_N": "250",
                      "AD_EDEC": "/Y", "EN_FRSH_GPA_1_P": "0.6", "EN_FRSH_GPA_2_P": "0.4"})
eq("CDS form fields (same names every template year)", {k: v for k, v in form.items() if k != "_summed"},
   {"applicants": 1000, "admits": 250, "factor_gpa": "Very Important", "gpa_avg": 3.91, "ed_offered": "Yes",
    "gpa_4_0": 60.0, "gpa_375_399": 40.0})
form = cds.from_form({"AP_RECD_1ST_N": "0", "AP_RECD_1ST_MEN_N": "5,831", "AP_RECD_1ST_WMN_N": "6,310",
                      "AP_ADMT_1ST_N": "0", "EN_TOT_1ST_N": "0", "EN_TOT_1ST_FT_MEN_N": "2890",
                      "EN_TOT_1ST_PT_WMN_N": "10"})
eq("CDS form: an uncalculated 0 total is its lines by sex added up",
   ({k: form.get(k) for k in ("applicants", "admits", "enrolled")}, sorted(form["_summed"])),
   ({"applicants": 12141, "admits": None, "enrolled": 2900}, ["applicants", "enrolled"]))
src = {"unitid": "1", "name": "X", "cds_year": "2023-24", "source_url": "https://x.edu/cds.pdf"}
vals, prov, rev = cds.decide(src, {"admissions_year": "2023", "sat_math_p25": "760", "applicants": "500"},
                             {"factor_gpa": "Important"}, {"gpa_avg": 3.9, "sat_math_p50": 780},
                             {"C.1201": "3.90", "C.911": "760", "C.912": "790", "C.117": "500", "C.1202": "88"})
eq("CDS decide: form, text+collegedata, collegedata+IPEDS",
   {k: vals.get(k) for k in ("factor_gpa", "gpa_avg", "sat_math_p25", "applicants")},
   {"factor_gpa": "Important", "gpa_avg": 3.9, "sat_math_p25": 760, "applicants": 500})
eq("CDS decide: disagreement and single readings go to review",
   sorted(r["field"] for r in rev), ["gpa_submit_pct", "sat_math_p50"])
for producer in ("tier1_xlsx", "tier2_acroform", "tier4_docling"):
    vals2, _, _ = cds.decide(src, None, {}, {}, {"C.701": "Very Important", "C.117": "2885"}, producer)
    eq(f"CDS decide: collegedata.fyi alone is not published ({producer})", vals2, {})
vals2, _, rev2 = cds.decide(src, None, {}, {"gpa_avg": 3.62, "gpa_submit_pct": 97.0, "_tight": {"gpa_avg"}}, {})
eq("CDS decide: a GPA on its label's line stands alone; a loose one doesn't",
   (vals2, [r["field"] for r in rev2]), ({"gpa_avg": 3.62}, ["gpa_submit_pct"]))
vals2, _, rev2 = cds.decide(src, {"admissions_year": "2023", "applicants": "28111"}, {"applicants": 0},
                            {"applicants": 0}, {"C.117": "0"})
eq("CDS decide: a 0 applicant count is blank, not published", (vals2, rev2), ({}, []))
vals2, _, rev2 = cds.decide(src, {"admissions_year": "2023", "applicants": "28111"}, {"applicants": 12000}, {}, {})
eq("CDS decide: C1 far from IPEDS for the same fall goes to review",
   (vals2, [r["field"] for r in rev2]), ({}, ["applicants"]))

# Which college a file belongs to: a file listed for three campuses is used for the one whose first-year class
# it matches; a single file far from IPEDS's class describes another campus; collegedata.fyi's row-shifted counts
# (fewer applicants than enrollees, or a lone count) don't disqualify a college's own file; applications can
# swing from year to year while the class size holds.
def _src(u, name, archive):
    return {"unitid": u, "name": name, "cds_year": "2025-26", "source_url": "https://x.edu/" + archive,
            "archive_url": "https://archive/" + archive + ".pdf"}


readings = {"1": (_src("1", "Main", "aaa"), {}, {"applicants": 46000}, {}, ""),
            "2": (_src("2", "Branch", "aaa"), {}, {"applicants": 46000}, {}, ""),
            "3": (_src("3", "Other", "bbb"), {}, {}, {"C.116": "52703", "C.117": "31701", "C.118": "7272"}, ""),
            "4": (_src("4", "Misread", "ccc"), {}, {"applicants": 45000}, {"C.116": "2885"}, ""),
            "5": (_src("5", "No IPEDS", "ddd"), {}, {"applicants": 900}, {}, ""),
            "6": (_src("6", "Shifted", "eee"), {}, {}, {"C.116": "1284"}, ""),
            "7": (_src("7", "Upside down", "fff"), {}, {}, {"C.116": "1829", "C.117": "900", "C.118": "2657"}, ""),
            "8": (_src("8", "Surge", "ggg"), {"applicants": 4133, "enrolled": 249}, {}, {}, ""),
            "9": (_src("9", "Two totals", "hhh"), {},
                  {"applicants": 2512, "enrolled": 162, "_alt": {"applicants": 2694, "enrolled": 364}}, {}, "")}
ipeds = {"1": {"applicants": "44000"}, "2": {"applicants": "5100"}, "3": {"applicants": "3005", "enrolled": "666"},
         "4": {"applicants": "45409"}, "6": {"applicants": "28232"}, "7": {"applicants": "9568", "enrolled": "800"},
         "8": {"applicants": "1747", "enrolled": "260"}, "9": {"applicants": "2694", "enrolled": "364"}}
skip = cds.assign(readings, ipeds)
eq("CDS files matched to their college", sorted(skip), ["2", "3"])
eq("CDS skipped file says why, and whose reading it is", skip["3"].split("(")[1].split(")")[0],
   "collegedata.fyi reading of the file: 7,272 enrolled, 52,703 applicants; IPEDS: 666 enrolled, 3,005 applicants")

LAYOUT = """C1. Applications
Total first-time, first-year students who applied in Fall 2023 1,578.0 2,055.0 9.0
                                              IN-STATE   OUT-OF-STATE   INTERNATIONAL   UNKNOWN        TOTAL
Total first-time, first-year (degree seeking) who applied     3,046      465      131                3,642
Total first-time, first-year (degree-seeking) who were admitted                                       2,933
C7. Relative importance
                                                                                          Not
                                    Very Important          Important        Considered
                                                                                       Considered
      Rigor of secondary school record      X
      Academic GPA                                 ☐                    ☐
                                                                        ✔                 ☐                 ☐
      Interview                                                                                         X
C8: SAT and ACT Policies
C21. Early decision: Does your institution offer an early decision plan for fall enrollment?
     ☐ Yes  ✔ No
Number of early decision applications received by your institution:            1,053
CDS-C Page 6
C12. Average high school GPA of all degree-seeking, first-time, first-year students who
     submitted GPA:                                                  3.71
Percent of total first-time, first-year students who submitted high school GPA:     96.4%"""
g = cds.parse(LAYOUT)
eq("CDS layout text: C1 total column, C7 marks under their headings, C21, C12",
   {k: g.get(k) for k in ("applicants", "admits", "factor_rigor", "factor_gpa", "factor_interview", "ed_offered",
                          "ed_applicants", "gpa_avg", "gpa_submit_pct")},
   {"applicants": 3642, "admits": 2933, "factor_rigor": "Very Important", "factor_gpa": "Important",
    "factor_interview": "Not Considered", "ed_offered": "No", "ed_applicants": 1053, "gpa_avg": 3.71,
    "gpa_submit_pct": 96.4})
eq("CDS layout text: a by-sex row isn't a total", cds.parse(LAYOUT.split("IN-STATE")[0]).get("applicants"), None)
C1_BLANK = """C1 Applications
Total first-time, first-year men who applied                         5,831
Total first-time, first-year women who applied                       6,310
Total first-time, first-year another gender who applied                 12
Total first-time, first-year unknown gender who applied
Total full-time, first-time, first-year men who enrolled             1,402
Total part-time, first-time, first-year men who enrolled                 3
Total full-time, first-time, first-year women who enrolled           1,488
Total first-time, first-year students who applied
Total first-time, first-year students who enrolled"""
g = cds.parse(C1_BLANK)
eq("CDS text: a blank C1 total is its lines by sex added up",
   ({k: g.get(k) for k in ("applicants", "admits", "enrolled")}, sorted(g["_summed"])),
   ({"applicants": 12153, "admits": None, "enrolled": 2893}, ["applicants", "enrolled"]))
C1_2025 = """Total first-time, first-year males who applied          14,020
Total first-time, first-year females who applied        18,734
Total first-time, first-year students of unknown sex who applied     0
First-Time, First-Year Student Applicants   In-State   Out-of-State   International   Unknown   Total
Total first-time, first-year (degree-seeking) who applied    3,512    24,960    4,282"""
eq("CDS text: a 2025-26 residency row with its Total blank isn't the total",
   cds.parse(C1_2025).get("applicants"), 32754)
eq("CDS text: a residency row whose Total adds up stays",
   cds.parse(C1_2025 + "    32,754").get("applicants"), 32754)
# Vanderbilt's workbook adds up each block of lines by sex on the row under it; a blank line by sex doesn't take
# that check sum.
_vu = cds.parse("""Total first-time, first-year males who applied                    21595
Total first-time, first-year females who applied                  26601
Total first-time, first-year students of unknown sex who applied
                                                                  48196
Total first-time, first-year (degree-seeking) who applied    2885   36130   9181   0   48196""")
eq("CDS text: a check sum under a blank line by sex isn't counted",
   (_vu.get("applicants"), _vu["_summed"], _vu["_alt"]), (48196, set(), {}))
eq("CDS text: the C1 lines as read, for the run log", _vu["_c1_lines"][-1],
   "Total first-time, first-year (degree-seeking) who applied 2885 36130 9181 0 48196")
eq("CDS text: lines \"of another gender\" count toward the total", cds.parse(
    "Total first-time, first-year men who enrolled 220\nTotal first-time, first-year women who enrolled 227\n"
    "Total first-time, first-year of another gender who enrolled 29\n"
    "Total first-time, first-year (degree-seeking) enrolled   95   347   34   476")["_alt"], {})
# A lone number in the residency row can be its in-state column (RIT); a file that states two totals keeps both,
# and a second reading settles which one is published.
_rit = cds.parse("""Total first-time, first-year men who applied 18303
Total first-time, first-year women who applied 13224
Total first-time, first-year (degree-seeking) who applied        10339""")
eq("CDS text: a file's two totals both kept", (_rit.get("applicants"), _rit["_alt"]), (10339, {"applicants": 31527}))
_src25 = {"unitid": "1", "name": "X", "cds_year": "2025-26", "source_url": "https://x.edu/cds.pdf"}
vals2, prov2, _ = cds.decide(_src25, {"admissions_year": "2024", "applicants": "27911"}, {}, _rit, {"C.116": "31527"})
eq("CDS decide: the total a second reading confirms is published",
   (vals2.get("applicants"), [p["method"] for p in prov2 if p["field"] == "applicants"]),
   (31527, ["file text + collegedata.fyi (lines by sex added up)"]))
eq("CDS text: enrollees by sex aren't counted again by full- and part-time", cds.parse(
    "Total first-time, first-year males who enrolled 100\nTotal first-time, first-year females who enrolled 120\n"
    "Total full-time, first-time, first-year males who enrolled 90\nTotal part-time, first-time, first-year males "
    "who enrolled 10\nTotal full-time, first-time, first-year females who enrolled 120").get("enrolled"), 220)
# The 2025-26 spreadsheet template indexes every question in columns right of the form, on unrelated rows: the
# C11 band rows end with the application closing month and day.
_wb = openpyxl.Workbook()
_ws = _wb.active
_ws.append(["C11", "Percentage of all enrolled ..."])
for _i, (_band, _p) in enumerate([("of 4.0", 40.0), ("between 3.75 and 3.99", 30.0), ("between 3.50 and 3.74", 20.0),
                                  ("between 3.25 and 3.49", 6.0), ("between 3.00 and 3.24", 4.0)]):
    _ws.append([None, "Percent who had GPA " + _band, None, _p, _p, _p] + [None] * 20
               + [f"C.14{_i:02d}", "Application closing date (fall): Month", _i + 1])
for _i in range(6):
    _ws.append([None] * 26 + [f"C.15{_i:02d}", "Another question", 15])
_ws.append(["C12", "Average high school GPA of all degree-seeking, first-time, first-year students who submitted GPA:",
            None, 3.71])
_buf3 = io.BytesIO()
_wb.save(_buf3)
g = cds.parse(cds.text_of(_buf3.getvalue(), "xlsx"))
eq("CDS spreadsheet: the question index right of the form is cut off",
   ([g.get(c) for c in cds.BAND_COLS[:5]], "gpa_350_374" in g["_tight"]), ([40.0, 30.0, 20.0, 6.0, 4.0], True))
_m = {c: "file text, checked" for c in cds.BAND_COLS}
_bands = dict(zip(cds.BAND_COLS, (87.6, 7.1, 3.4, 0.8, 0.7, 0.3, 0.1, 0.0, 0.0)))
eq("CDS GPA bands that can't give their average: the lone reading goes",
   cds.gpa_conflict({"gpa_avg": 3.71, **_bands}, {**_m, "gpa_avg": "form fields"}),
   [("gpa_bands", "the GPA bands put the class near 3.96, well above its 3.71 average")])
_both = {c: "file text + collegedata.fyi" for c in cds.BAND_COLS}
eq("CDS GPA bands and average both confirmed stay",
   cds.gpa_conflict({"gpa_avg": 3.71, **_bands}, {**_both, "gpa_avg": "form fields"}), [])
eq("CDS weighted average above its bands is fine",
   cds.gpa_conflict({"gpa_avg": 4.54, **dict(zip(cds.BAND_COLS, (56.8, 26.9, 9.9, 2.8, 2.9, 0.7, 0.1, 0, 0)))},
                    {**_m, "gpa_avg": "file text, checked"}), [])
eq("CDS C1 for another fall: an admit rate far from IPEDS's goes to review",
   [c for c, _ in cds.c1_jump({"applicants": 6785, "admits": 2612, "enrolled": 1742},
                              {"applicants": "8824", "admits": "7266", "enrolled": "2044"})],
   ["applicants", "admits", "enrolled"])
eq("CDS C1 for another fall: growth with the same admit rate stays",
   cds.c1_jump({"applicants": 9678, "admits": 8778, "enrolled": 519},
               {"applicants": "5037", "admits": "4492", "enrolled": "603"}), [])
_wb = openpyxl.Workbook()
_ws = _wb.active
_ws.append(["C11", "Percent who had GPA of 4.0", 0.55])
_ws.append([None, "Percent who had GPA between 3.75 and 3.99", 0.45])
_ws.append(["C12", "Percent of total first-time, first-year students who submitted high school GPA:", 1])
for _c in ("C1", "C2", "C3"):
    _ws[_c].number_format = "0.0%"
_buf2 = io.BytesIO()
_wb.save(_buf2)
g = cds.parse(cds.text_of(_buf2.getvalue(), "xlsx"))
eq("CDS spreadsheet percent cells read as percents", (g.get("gpa_4_0"), g.get("gpa_submit_pct")), (55.0, 100.0))

_buf = io.BytesIO()
with zipfile.ZipFile(_buf, "w") as _z:
    _z.writestr("word/document.xml", "<w:document><w:body><w:tbl><w:tr><w:tc><w:p><w:t>Average high school GPA of "
                "all degree-seeking, first-time, first-year students who submitted GPA:</w:t></w:p></w:tc><w:tc><w:p>"
                "<w:t>3.87</w:t></w:p></w:tc></w:tr></w:tbl></w:body></w:document>")
eq("CDS Word file read", cds.parse(cds.text_of(_buf.getvalue())).get("gpa_avg"), 3.87)

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
