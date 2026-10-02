"""Find each college's newest Common Data Set (CDS) file, using collegedata.fyi's public archive as the index.

    python3 scripts/admissions/cds_sources.py [--institutions CSV] [--out DIR] [--min-year 2023-24]

collegedata.fyi (MIT-licensed, https://github.com/bolewood/collegedata-fyi) tracks about 4,000 CDS files and the
URL of each on the college's own site. We use it only to find the files: values are read from the college's
own file by cds.py and cited to it. Its own extracted values are kept (raw/cds/collegedata_values.json) to
cross-check ours, never published on their own.

Writes data/admissions/cds_sources.csv: one row per college in institutions.csv with a CDS from --min-year on
(source_url on the college's site, archive_url as a byte-for-byte copy when the college's link is gone).
The read-only key collegedata.fyi publishes for its API is read from https://www.collegedata.fyi/api at run
time (or CDF_ANON_KEY) and never stored.
"""
import argparse
import csv
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, RAW, write_csv, write_json  # noqa: E402

API = "https://api.collegedata.fyi/rest/v1/"
KEY_PAGE = "https://www.collegedata.fyi/api"
ARCHIVE = "https://api.collegedata.fyi/storage/v1/object/public/sources/"
UA = "gpacalculator-admissions-data/1.0 (+https://gpacalculator.net)"
COLUMNS = ["unitid", "name", "school_id", "cds_year", "source_url", "archive_url", "format", "document_id",
           "sub_institutional", "extraction_status", "data_quality_flag", "producer"]
# CDS items we read: question numbers in the 2023-24 to 2025-26 templates (see cds.py FIELDS).
QUESTIONS = ([f"C.{n}" for n in range(116, 120)] + [f"C.20{n}" for n in range(1, 5)]
             + [f"C.7{n:02d}" for n in range(1, 19)] + [f"C.9{n:02d}" for n in range(1, 17)]
             + [f"C.11{n:02d}" for n in range(1, 31)] + ["C.1201", "C.1202"]
             + [f"C.21{n:02d}" for n in range(1, 13)] + [f"C.22{n:02d}" for n in range(1, 7)])


def http(url, key=None):
    headers = {"User-Agent": UA, "Accept": "application/json, text/html"}
    if key:
        headers.update(apikey=key, Authorization=f"Bearer {key}")
    with urllib.request.urlopen(urllib.request.Request(url, headers=headers), timeout=120) as r:
        return r.read()


def anon_key():
    key = os.environ.get("CDF_ANON_KEY")
    if key:
        return key
    page = http(KEY_PAGE).decode("utf-8", "ignore")
    keys = re.findall(r"eyJ[\w-]{10,}\.[\w-]{20,}\.[\w-]{10,}", page)
    if not keys:
        raise SystemExit(f"no API key found on {KEY_PAGE}")
    return keys[0]


def rows(key, view, params, page=1000):
    out, offset = [], 0
    while True:
        q = urllib.parse.urlencode({**params, "limit": page, "offset": offset}, safe="(),.*:>-")
        batch = json.loads(http(f"{API}{view}?{q}", key))
        out += batch
        if len(batch) < page:
            return out
        offset += page


def pick(docs, min_year):
    """Newest CDS per college; the whole-institution file wins over a sub-institution's."""
    best = {}
    for d in docs:
        uid, year = str(d.get("ipeds_id") or "").strip(), d.get("canonical_year") or ""
        if not uid or not d.get("source_url") or year < min_year:
            continue
        rank = (year, not d.get("sub_institutional"), d.get("last_verified_at") or "")
        if uid not in best or rank > best[uid][0]:
            best[uid] = (rank, d)
    return {u: d for u, (_, d) in best.items()}


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--out", default=str(OUT))
    ap.add_argument("--min-year", default="2023-24")
    a = ap.parse_args(argv)
    names = {r["unitid"]: r["name"] for r in csv.DictReader(open(a.institutions, encoding="utf-8"))}
    key = anon_key()
    docs = rows(key, "cds_manifest", {
        "removed_at": "is.null",
        "select": "document_id,school_id,school_name,ipeds_id,sub_institutional,canonical_year,source_url,"
                  "source_format,source_storage_path,extraction_status,data_quality_flag,last_verified_at",
        "order": "document_id"})
    chosen = {u: d for u, d in pick(docs, a.min_year).items() if u in names}
    values = {}
    ids = [d["document_id"] for d in chosen.values()]
    narrow = "document_id,producer,schema_version,created_at," + ",".join(
        f'q{q.replace(".", "_")}:notes->values->"{q}"->>value' for q in QUESTIONS)
    wide = "document_id,producer,schema_version,created_at,vals:notes->values"
    sel = narrow
    for i in range(0, len(ids), 50):
        params = {"document_id": f"in.({','.join(ids[i:i + 50])})", "kind": "eq.canonical", "order": "created_at.desc"}
        try:
            arts = rows(key, "cds_artifacts", {**params, "select": sel})
        except urllib.error.HTTPError as e:
            if sel is wide:
                raise
            print(f"narrow select refused ({e}); reading whole value sets")
            sel = wide
            arts = rows(key, "cds_artifacts", {**params, "select": sel})
        for art in arts:
            if art["document_id"] in values:
                continue  # newest canonical artifact first
            if sel is wide:
                got = {q: (v or {}).get("value") for q, v in (art.get("vals") or {}).items() if q in QUESTIONS}
            else:
                got = {q: art.get(f'q{q.replace(".", "_")}') for q in QUESTIONS}
            values[art["document_id"]] = {"producer": art.get("producer"), "schema_version": art.get("schema_version"),
                                          "values": {q: v for q, v in got.items() if v not in (None, "")}}
    out = []
    for uid, d in sorted(chosen.items(), key=lambda t: names[t[0]]):
        v = values.get(d["document_id"], {})
        out.append({"unitid": uid, "name": names[uid], "school_id": d["school_id"], "cds_year": d["canonical_year"],
                    "source_url": d["source_url"],
                    "archive_url": ARCHIVE + d["source_storage_path"] if d.get("source_storage_path") else "",
                    "format": d.get("source_format") or "", "document_id": d["document_id"],
                    "sub_institutional": d.get("sub_institutional") or "",
                    "extraction_status": d.get("extraction_status") or "",
                    "data_quality_flag": d.get("data_quality_flag") or "", "producer": v.get("producer") or ""})
    write_csv(Path(a.out) / "cds_sources.csv", out, COLUMNS)
    write_json(RAW / "cds" / "collegedata_values.json",
               {r["unitid"]: values.get(r["document_id"], {}) for r in out})
    years = {}
    for r in out:
        years[r["cds_year"]] = years.get(r["cds_year"], 0) + 1
    print(f"{len(docs):,} CDS files indexed; {len(out):,} of our colleges have one from {a.min_year} on "
          f"({', '.join(f'{y}: {n:,}' for y, n in sorted(years.items()))}); "
          f"{sum(bool(values.get(r['document_id'], {}).get('values')) for r in out):,} with extracted values")


if __name__ == "__main__":
    main()
