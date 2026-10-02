"""Admissions details from each college's own Common Data Set (CDS), which IPEDS and College Scorecard don't carry.

    python3 scripts/admissions/cds.py [--sources CSV] [--out DIR] [--workers 8]

Reads data/admissions/cds_sources.csv (from cds_sources.py: the newest CDS file on each college's own site)
and, for each college, downloads the file (the archived copy if the college's link is gone) and reads:
- C1  first-year applicants, admits and enrollees (fresher than IPEDS: a 2025-26 CDS describes fall 2025)
- C2  wait list: policy, offered, accepted a place, admitted from it
- C7  how much each admission factor counts (Very Important / Important / Considered / Not Considered)
- C9  % submitting SAT / ACT; SAT composite, EBRW and Math and ACT composite 25th/50th/75th percentiles
- C11 % of first-year students in each high school GPA band (the "Total" column)
- C12 average high school GPA of those who submitted one, and the % who submitted one
- C21/C22 early decision offered (with applicants and admits), early action offered

Every value has two independent readings before it is published:
- our own: the PDF's fillable form fields (exact, when the college didn't flatten the PDF) or the text of the
  file, matched against the CDS wording
- collegedata.fyi's extraction of the same file (raw/cds/collegedata_values.json), or IPEDS for the same fall
A value is verified when it comes from the form fields, or when two readings agree; text-only C11/C12 values
are verified by their own checks (bands add up to 100, GPA within 1-5). Everything else goes to review with
both readings. Values are never estimated, and a blank stays blank.

Writes data/admissions/cds_values.csv (verified values, one row per college, with the CDS year and the source
URL on the college's site for citations), cds_provenance.csv (every reading of every value, verified or not)
and cds_review.csv (files that could not be read, and values whose readings disagree).
Needs pypdf and openpyxl.
"""
import argparse
import csv
import html
import io
import json
import os
import re
import sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, RAW, write_csv  # noqa: E402

TAGS = json.loads((Path(__file__).parent / "cds_tags.json").read_text())
LEVELS = {"VI": "Very Important", "I": "Important", "C": "Considered", "NC": "Not Considered"}
FACTORS = ["rigor", "class_rank", "gpa", "test_scores", "essay", "recommendations", "interview",
           "extracurriculars", "talent", "character", "first_generation", "legacy", "geography",
           "state_residency", "religion", "volunteer_work", "work_experience", "demonstrated_interest"]
BANDS = [("gpa_4_0", r"4\.00?(?: and higher)?"),
         ("gpa_375_399", r"3\.75 and 3\.99"), ("gpa_350_374", r"3\.50 and 3\.74"),
         ("gpa_325_349", r"3\.25 and 3\.49"), ("gpa_300_324", r"3\.00 and 3\.24"),
         ("gpa_250_299", r"2\.50 and 2\.99"), ("gpa_200_249", r"2\.0 and 2\.49"),
         ("gpa_100_199", r"1\.0 and 1\.99"), ("gpa_below_100", r"below 1\.0")]
TESTS = [("sat_comp", r"SAT Composite", "905"), ("sat_erw", r"SAT Evidence-Based Reading and Writing", "908"),
         ("sat_math", r"SAT Math", "911"), ("act_comp", r"ACT Composite", "914")]

# column -> (kind, {template year or "*": CDS question number}). Kinds: count, pct, gpa, score, yn, level.
FIELDS = {
    "applicants": ("count", {"2025-26": "C.116", "*": "C.117"}),
    "admits": ("count", {"2025-26": "C.117", "*": "C.118"}),
    "enrolled": ("count", {"2025-26": "C.118", "*": "C.119"}),
    "waitlist_policy": ("yn", {"*": "C.201"}),
    "waitlist_offered": ("count", {"*": "C.202"}),
    "waitlist_accepted": ("count", {"*": "C.203"}),
    "waitlist_admitted": ("count", {"*": "C.204"}),
    **{f"factor_{f}": ("level", {"*": f"C.7{i + 1:02d}"}) for i, f in enumerate(FACTORS)},
    "sat_submit_pct": ("pct", {"*": "C.901"}), "act_submit_pct": ("pct", {"*": "C.902"}),
    **{f"{name}_p{q}": ("score", {"*": f"C.{int(first) + i}"})
       for name, _, first in TESTS for i, q in enumerate((25, 50, 75))},
    **{col: ("pct", {"*": f"C.11{21 + i}"}) for i, (col, _) in enumerate(BANDS)},
    "gpa_avg": ("gpa", {"*": "C.1201"}), "gpa_submit_pct": ("pct", {"*": "C.1202"}),
    "ed_offered": ("yn", {"*": "C.2101"}),
    "ed_applicants": ("count", {"2025-26": "C.2110", "*": "C.2106"}),
    "ed_admits": ("count", {"2025-26": "C.2111", "*": "C.2107"}),
    "ea_offered": ("yn", {"*": "C.2201"}),
}
# Our columns that IPEDS ADM also reports for the same fall (CDS 2024-25 = fall 2024 = ADM2024).
IPEDS_SAME = {"applicants": "applicants", "admits": "admits", "enrolled": "enrolled",
              "sat_submit_pct": "sat_submit_pct", "act_submit_pct": "act_submit_pct",
              **{f"{t}_p{q}": f"{t}_p{q}" for t in ("sat_math", "act_comp") for q in (25, 50, 75)},
              **{f"sat_erw_p{q}": f"sat_erw_p{q}" for q in (25, 50, 75)}}
META = ["unitid", "name", "cds_year", "source_url"]
COLUMNS = META + list(FIELDS)


def question(col, year):
    m = FIELDS[col][1]
    return m.get(year, m["*"])


def num(v):
    if v is None:
        return None
    s = str(v).strip().replace(",", "").replace("$", "").rstrip("%").strip()
    try:
        return float(s)
    except ValueError:
        return None


def clean(kind, v):
    """A reading in our units, or None: percents 0-100, GPA, counts and scores as numbers, labels as words."""
    if v is None or str(v).strip() in ("", "N/A", "n/a", "NA", "-", "--"):
        return None
    s = str(v).strip()
    if kind == "level":
        code = s.lstrip("/").upper()
        if code in LEVELS:
            return LEVELS[code]
        low = s.lower()
        for label in ("Very Important", "Not Considered", "Important", "Considered"):
            if label.lower() in low:
                return label
        return None
    if kind == "yn":
        low = s.lstrip("/").lower()
        return "Yes" if low in ("y", "yes", "x", "on") else "No" if low in ("n", "no") else None
    n = num(s)
    if n is None:
        return None
    if kind == "pct" and 0 < n <= 1 and "." in s and "%" not in s:
        n *= 100  # Excel stores 25% as 0.25
    return round(n, 2) if kind in ("pct", "gpa") else int(round(n))


def agree(kind, a, b):
    if a is None or b is None:
        return False
    if kind in ("level", "yn"):
        return a == b
    tol = {"pct": 0.51, "gpa": 0.005, "count": 0, "score": 0}[kind]
    return abs(a - b) <= tol


def text_of(data, fmt=""):
    if data[:4] == b"%PDF":
        from pypdf import PdfReader
        return "\n".join(page.extract_text() or "" for page in PdfReader(io.BytesIO(data)).pages)
    if data[:2] == b"PK" and b"word/document.xml" in data:
        import zipfile
        x = zipfile.ZipFile(io.BytesIO(data)).read("word/document.xml").decode("utf-8", "ignore")
        x = re.sub(r"</w:p>\s*</w:tc>", " ", x).replace("</w:tr>", "\n").replace("</w:p>", "\n")
        return html.unescape(re.sub(r"<[^>]+>", "", x))
    if fmt == "xlsx" or data[:2] == b"PK":
        import openpyxl
        wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
        lines = []
        for ws in wb.worksheets:
            for row in ws.iter_rows(values_only=True):
                cells = [str(c) for c in row if c is not None and str(c).strip()]
                if cells:
                    lines.append(" ".join(cells))
        return "\n".join(lines)
    t = data.decode("utf-8", "ignore")
    t = re.sub(r"(?is)<(script|style).*?</\1>", " ", t)
    t = re.sub(r"(?i)<br\s*/?>|</(p|div|tr|li|h\d)>", "\n", t)
    t = re.sub(r"(?i)</t[dh]>", " ", t)
    return html.unescape(re.sub(r"<[^>]+>", " ", t))


def form_fields(data):
    """{AcroForm field name: value} from a fillable CDS PDF; empty when the college flattened it."""
    if data[:4] != b"%PDF":
        return {}
    from pypdf import PdfReader
    try:
        fields = PdfReader(io.BytesIO(data)).get_fields() or {}
    except Exception:
        return {}
    return {k: str(f.get("/V")).strip() for k, f in fields.items() if f.get("/V") not in (None, "", "/Off")}


def from_form(fields, year):
    tags = TAGS.get(year) or TAGS["2025-26"]
    out = {}
    for col, (kind, _) in FIELDS.items():
        tag = tags.get(question(col, year))
        if tag and tag in fields:
            v = clean(kind, fields[tag])
            if v is not None:
                out[col] = v
    return out


NUM = r"(\d{1,3}(?:,\d{3})*(?:\.\d+)?)"


def parse(text):
    """Readings from the file's text, matched against the CDS wording. Returns {} for fields not found."""
    t = re.sub(r"[ \t ]+", " ", text)
    flat = re.sub(r"\s+", " ", t)
    out = {}
    m = re.search(r"Average high school GPA of all degree-seeking.{0,200}?submitted GPA[:\s]*(\d\.\d{1,3})", flat, re.I)
    if m:
        out["gpa_avg"] = float(m.group(1))
    m = re.search(r"Percent of total first-time.{0,160}?submitted high school GPA[:\s]*" + NUM + r"\s*(%?)", flat, re.I)
    if m:
        out["gpa_submit_pct"] = clean("pct", m.group(1) + m.group(2))
    c11 = re.search(r"C11(.{0,4000}?)C12", t, re.S)
    block = c11.group(1) if c11 else t
    for col, label in BANDS:
        m = re.search(r"Percent who had GPA (?:of |between )?" + label + r"[^\n%\d]*((?:\s*" + NUM + r"\s*%?)+)",
                      block, re.I)
        if m:
            nums = re.findall(r"\d{1,3}(?:\.\d+)?\s*%?", m.group(1))
            if nums:
                out[col] = clean("pct", nums[-1].replace(" ", ""))  # the "Total" column when there are three
    c9 = re.search(r"C9(.{0,6000}?)C10", t, re.S)
    block = c9.group(1) if c9 else ""
    for name, label, _ in TESTS:
        m = re.search(label + r"\s*:?\s*(\d{2,4})\s+(\d{2,4})\s+(\d{2,4})(?!\s*-)", block)
        if m:
            vals = [int(x) for x in m.groups()]
            if vals == sorted(vals):
                for q, v in zip((25, 50, 75), vals):
                    out[f"{name}_p{q}"] = v
    for col, label in (("sat_submit_pct", "SAT"), ("act_submit_pct", "ACT")):
        m = re.search(r"Submitting " + label + r" Scores\s*:?\s*(\d{1,3}(?:\.\d+)?)\s*%", block)
        if m:
            out[col] = float(m.group(1))
    return out


def check(vals):
    problems = []
    if vals.get("gpa_avg") is not None and not 1 <= vals["gpa_avg"] <= 5:
        problems.append(("gpa_avg", f"average GPA {vals['gpa_avg']} outside 1-5"))
    for col, (kind, _) in FIELDS.items():
        v = vals.get(col)
        if v is None:
            continue
        if kind == "pct" and not 0 <= v <= 100:
            problems.append((col, f"{v} outside 0-100"))
        if col.startswith("sat_") and kind == "score" and not 200 <= v <= 1600:
            problems.append((col, f"SAT {v} outside 200-1600"))
        if col.startswith("act_") and kind == "score" and not 1 <= v <= 36:
            problems.append((col, f"ACT {v} outside 1-36"))
    bands = [vals[c] for c, _ in BANDS if vals.get(c) is not None]
    if bands and not 97 <= sum(bands) <= 103:
        problems.append(("gpa_bands", f"GPA bands add up to {round(sum(bands), 1)}"))
    a, b = vals.get("applicants"), vals.get("admits")
    if a is not None and b is not None and b > a:
        problems.append(("admits", "more admits than applicants"))
    return problems


EXACT_PRODUCERS = {"tier1_xlsx", "tier2_acroform"}  # collegedata.fyi reads of the college's own form fields


def decide(src, ipeds, mine_form, mine_text, theirs, producer=""):
    """(verified values, provenance rows, review rows) for one college."""
    year = src["cds_year"]
    same_fall = ipeds if ipeds and ipeds.get("admissions_year") == year[:4] else {}
    values, prov, review = {}, [], []
    for col, (kind, _) in FIELDS.items():
        f, t = mine_form.get(col), mine_text.get(col)
        c = clean(kind, theirs.get(question(col, year)))
        i = clean(kind, same_fall.get(IPEDS_SAME.get(col, ""))) if col in IPEDS_SAME else None
        ours = f if f is not None else t
        if ours is None and c is None:
            continue
        if f is not None:
            v, how = f, "form fields"
        elif agree(kind, t, c):
            v, how = t, "file text + collegedata.fyi"
        elif t is None and c is not None and producer in EXACT_PRODUCERS:
            v, how = c, f"collegedata.fyi exact read ({producer})"
        elif t is not None and c is None and col.startswith("gpa_"):
            v, how = t, "file text, checked"
        elif t is None and agree(kind, c, i):
            v, how = c, "collegedata.fyi + IPEDS same fall"
        elif t is not None and c is None and agree(kind, t, i):
            v, how = t, "file text + IPEDS same fall"
        else:
            v, how = None, ""
        prov.append({"unitid": src["unitid"], "field": col, "question": question(col, year), "value": v,
                     "verified": "yes" if v is not None else "no", "method": how,
                     "form": f, "text": t, "collegedata": c, "ipeds": i})
        if v is not None:
            values[col] = v
        elif ours is not None or c is not None:
            review.append({"unitid": src["unitid"], "name": src["name"], "cds_year": year,
                           "file_url": src["source_url"], "field": col,
                           "problem": f"readings disagree or unconfirmed: ours {ours}, collegedata.fyi {c}, IPEDS {i}"})
    for col, why in check(values):
        cols = [c for c, _ in BANDS] if col == "gpa_bands" else [col]
        for c in cols:
            values.pop(c, None)
        for p in prov:
            if p["field"] in cols:
                p["verified"], p["method"] = "no", f"failed check: {why}"
        review.append({"unitid": src["unitid"], "name": src["name"], "cds_year": year,
                       "file_url": src["source_url"], "field": col, "problem": why})
    return values, prov, review


def fetch(url):
    from fetch import get  # same browser headers and retries as the federal downloads
    return get(url, tries=2)


def read_one(src):
    """Downloads the college's file (else the archived copy) and reads it; a file that can't be read (an HTML
    page instead of the workbook, say) falls through to the next copy."""
    err = "no file"
    for url in (src.get("source_url"), src.get("archive_url")):
        if not url:
            continue
        try:
            data = fetch(url)
        except Exception as e:
            err = f"{url}: {e}"[:200]
            continue
        if not data or len(data) <= 500:
            err = f"{url}: empty file"
            continue
        try:
            return src, form_fields(data), parse(text_of(data, src.get("format", ""))), ""
        except Exception as e:
            err = f"could not read file: {e}"[:200]
    return src, None, None, err


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", default=str(OUT / "cds_sources.csv"))
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--collegedata", default=str(RAW / "cds" / "collegedata_values.json"))
    ap.add_argument("--out", default=str(OUT))
    ap.add_argument("--workers", type=int, default=8)
    a = ap.parse_args(argv)
    sources = list(csv.DictReader(open(a.sources, encoding="utf-8")))
    ipeds = {r["unitid"]: r for r in csv.DictReader(open(a.institutions, encoding="utf-8"))} \
        if os.path.exists(a.institutions) else {}
    theirs = json.loads(Path(a.collegedata).read_text()) if os.path.exists(a.collegedata) else {}
    rows, prov, review = [], [], []
    with ThreadPoolExecutor(a.workers) as pool:
        for src, form, text, err in pool.map(read_one, sources):
            if err:
                review.append({"unitid": src["unitid"], "name": src["name"], "cds_year": src["cds_year"],
                               "file_url": src["source_url"], "field": "", "problem": err})
                form, text = {}, {}
            values, p, r = decide(src, ipeds.get(src["unitid"]), from_form(form, src["cds_year"]), text,
                                  (theirs.get(src["unitid"]) or {}).get("values", {}),
                                  (theirs.get(src["unitid"]) or {}).get("producer") or "")
            prov += p
            review += r
            if values:
                rows.append({**{k: src[k] for k in ("unitid", "name", "cds_year", "source_url")}, **values})
    out = Path(a.out)
    rows.sort(key=lambda r: r["name"])
    write_csv(out / "cds_values.csv", rows, COLUMNS)
    write_csv(out / "cds_provenance.csv", prov,
              ["unitid", "field", "question", "value", "verified", "method", "form", "text", "collegedata", "ipeds"])
    write_csv(out / "cds_review.csv", review, ["unitid", "name", "cds_year", "file_url", "field", "problem"])
    n = len(sources)
    print(f"{n:,} CDS files: {sum(1 for r in review if not r['field']):,} unreadable; "
          f"{len(rows):,} colleges with verified values; "
          f"{sum(r.get('gpa_avg') is not None for r in rows):,} with an average GPA, "
          f"{sum(r.get('gpa_4_0') is not None for r in rows):,} with GPA bands; "
          f"{sum(1 for r in review if r['field']):,} values to review")


if __name__ == "__main__":
    main()
