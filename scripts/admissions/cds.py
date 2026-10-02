"""High school GPA from each college's own Common Data Set (CDS), which IPEDS and College Scorecard don't carry.

    python3 scripts/admissions/cds.py [--sources CSV] [--out DIR]

Reads data/admissions/cds_sources.csv (unitid, name, cds_year, page_url, file_url, format, note): one row per
college, pointing at the CDS file on the college's own site. Downloads each file, reads its text (PDF with
pypdf, XLSX with openpyxl) and pulls:
- C12: average high school GPA of first-year students who submitted one, and the % who submitted one
- C11: % of first-year students in each GPA band (4.0, 3.75-3.99, ... below 1.0); when the CDS splits the
  table into "score included / not included / total", the total column is used

Writes data/admissions/cds_gpa.csv (values, CDS year and source URL per college, for citations) and
cds_review.csv (files that could not be read or whose numbers fail the checks: GPA outside 1-5, submit %
outside 0-100, bands not adding up to 100 +/- 3). Values are never estimated: a field the college left blank
stays empty. Downloads need pypdf and openpyxl and a network that reaches the colleges' sites.
"""
import argparse
import csv
import io
import os
import re
import sys
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, write_csv  # noqa: E402

BANDS = [("gpa_4_0", r"4\.00?(?: and higher)?"),
         ("gpa_375_399", r"3\.75 and 3\.99"), ("gpa_350_374", r"3\.50 and 3\.74"),
         ("gpa_325_349", r"3\.25 and 3\.49"), ("gpa_300_324", r"3\.00 and 3\.24"),
         ("gpa_250_299", r"2\.50 and 2\.99"), ("gpa_200_249", r"2\.0 and 2\.49"),
         ("gpa_100_199", r"1\.0 and 1\.99"), ("gpa_below_100", r"below 1\.0")]
COLUMNS = (["unitid", "name", "cds_year", "source_url", "gpa_avg", "gpa_submit_pct"] + [b for b, _ in BANDS]
           + ["bands_total"])
NUM = r"(\d{1,3}(?:\.\d+)?)"


def text_of(data, fmt):
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
    from pypdf import PdfReader
    return "\n".join(page.extract_text() or "" for page in PdfReader(io.BytesIO(data)).pages)


def pct(s):
    return float(s)


def parse(text):
    """C11/C12 values from a CDS's text. Returns {} for fields not found."""
    t = re.sub(r"[ \t\u00a0]+", " ", text)
    flat = re.sub(r"\s+", " ", t)
    out = {}
    m = re.search(r"Average high school GPA of all degree-seeking.{0,200}?submitted GPA[:\s]*" + r"(\d\.\d{1,3})",
                  flat, re.I)
    if m:
        out["gpa_avg"] = float(m.group(1))
    m = re.search(r"Percent of total first-time.{0,160}?submitted high school GPA[:\s]*" + NUM + r"\s*%?",
                  flat, re.I)
    if m:
        v = pct(m.group(1))
        out["gpa_submit_pct"] = round(v * 100, 1) if v <= 1 and "." in m.group(1) else v  # Excel fraction
    c11 = re.search(r"C11(.{0,4000}?)C12", t, re.S)
    block = c11.group(1) if c11 else t
    for col, label in BANDS:
        m = re.search(r"Percent who had GPA (?:of |between )?" + label + r"[^\n%\d]*((?:\s*" + NUM + r"\s*%?)+)",
                      block, re.I)
        if m:
            nums = re.findall(NUM, m.group(1))
            if nums:
                out[col] = pct(nums[-1])  # the "Total" column when there are three
    return out


def check(row):
    problems = []
    if row.get("gpa_avg") is not None and not 1 <= row["gpa_avg"] <= 5:
        problems.append(f"average GPA {row['gpa_avg']} outside 1-5")
    if row.get("gpa_submit_pct") is not None and not 0 <= row["gpa_submit_pct"] <= 100:
        problems.append(f"submit % {row['gpa_submit_pct']} outside 0-100")
    bands = [row[b] for b, _ in BANDS if row.get(b) is not None]
    if bands and 0.97 <= sum(bands) <= 1.03:  # Excel stores 25% as 0.25
        for b, _ in BANDS:
            if row.get(b) is not None:
                row[b] = round(row[b] * 100, 2)
        bands = [row[b] for b, _ in BANDS if row.get(b) is not None]
    if bands:
        row["bands_total"] = round(sum(bands), 1)
        if not 97 <= row["bands_total"] <= 103:
            problems.append(f"GPA bands add up to {row['bands_total']}")
    return problems


def fetch(url):
    from fetch import get  # same browser headers and retries as the federal downloads
    return get(url)


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", default=str(OUT / "cds_sources.csv"))
    ap.add_argument("--out", default=str(OUT))
    a = ap.parse_args(argv)
    sources = list(csv.DictReader(open(a.sources, encoding="utf-8")))
    rows, review = [], []
    for s in sources:
        if not s.get("file_url"):
            review.append({**s, "problem": s.get("note") or "no CDS found"})
            continue
        try:
            text = text_of(fetch(s["file_url"]), s.get("format", ""))
        except Exception as e:
            review.append({**s, "problem": f"could not read file: {e}"[:200]})
            continue
        row = {"unitid": s["unitid"], "name": s["name"], "cds_year": s["cds_year"], "source_url": s["file_url"],
               **parse(text)}
        problems = check(row)
        if not problems and row.get("gpa_avg") is None and not any(row.get(b) is not None for b, _ in BANDS):
            problems = ["no GPA figures found in the file"]
        if problems:
            review.append({**s, "problem": "; ".join(problems)})
        else:
            rows.append(row)
    out = Path(a.out)
    write_csv(out / "cds_gpa.csv", rows, COLUMNS)
    write_csv(out / "cds_review.csv", review, ["unitid", "name", "cds_year", "file_url", "problem"])
    print(f"{len(sources)} colleges: {sum(r.get('gpa_avg') is not None for r in rows)} with an average GPA, "
          f"{len(review)} to review")


if __name__ == "__main__":
    main()
