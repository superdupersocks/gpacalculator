"""Download the federal source files for the admissions data into data/admissions/raw/.

    python3 scripts/admissions/fetch.py            # newest IPEDS year with HD, ADM and IC all published
    IPEDS_YEAR=2024 python3 scripts/admissions/fetch.py

Sources (Department of Education, public, no key needed):
- College Scorecard, Most Recent Institution-Level Data (https://collegescorecard.ed.gov/data/). The zip's URL is
  read from that page; set SCORECARD_ZIP_URL to override it.
- IPEDS complete data files HD<year>, ADM<year>, IC<year> and their dictionaries
  (https://nces.ed.gov/ipeds/datacenter/data/<FILE>.zip and <FILE>_Dict.zip).

Writes raw/scorecard/institutions.csv, raw/ipeds/<file>.csv, raw/ipeds/<file>_dict.json (variable titles and code
labels from the dictionary workbook) and data/admissions/manifest.json (URL, size, SHA-256, year of every file).
Needs openpyxl for the dictionary workbooks.
"""
import datetime
import hashlib
import io
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
import zipfile

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, RAW, write_json  # noqa: E402

UA = "Mozilla/5.0 (compatible; gpacalculator-data/1.0; +https://github.com/superdupersocks/gpacalculator)"
IPEDS = "https://nces.ed.gov/ipeds/datacenter/data/"
SCORECARD_PAGE = "https://collegescorecard.ed.gov/data/"
IPEDS_FILES = ["HD", "ADM", "IC"]


def get(url, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers={"User-Agent": UA})
            with urllib.request.urlopen(req, timeout=300) as r:
                return r.read()
        except urllib.error.HTTPError as e:
            if e.code in (403, 404, 410):
                raise
            err = e
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            err = e
        time.sleep(2 ** (i + 1))
    raise err


def exists(url):
    try:
        req = urllib.request.Request(url, headers={"User-Agent": UA, "Range": "bytes=0-1"})
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.read(2) == b"PK"  # a zip, not an HTML "file not found" page
    except urllib.error.HTTPError:
        return False


def record(manifest, key, url, data, **extra):
    manifest["files"][key] = {"url": url, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest(), **extra}


def pick_member(zf, suffix):
    """The file in a zip ending with suffix; IPEDS ships a revised '_rv' copy beside the provisional one."""
    names = [n for n in zf.namelist() if n.lower().endswith(suffix)]
    if not names:
        raise SystemExit(f"no *{suffix} in zip: {zf.namelist()}")
    revised = [n for n in names if "_rv" in n.lower()]
    return (revised or names)[0]


def parse_dictionary(xlsx_bytes):
    """Variable titles and code labels from an IPEDS dictionary workbook (sheets 'varlist' and 'Frequencies')."""
    import openpyxl

    wb = openpyxl.load_workbook(io.BytesIO(xlsx_bytes), read_only=True, data_only=True)
    sheets = {ws.title.lower(): ws for ws in wb.worksheets}

    def rows(name):
        ws = sheets.get(name)
        if ws is None:
            return []
        it = ws.iter_rows(values_only=True)
        head = [str(h or "").strip().lower() for h in next(it)]
        return [dict(zip(head, r)) for r in it]

    out = {"vars": {}, "codes": {}}
    for r in rows("varlist"):
        if r.get("varname"):
            out["vars"][str(r["varname"]).upper()] = {"title": str(r.get("vartitle") or ""),
                                                      "type": str(r.get("datatype") or "")}
    for r in rows("frequencies"):
        if r.get("varname") is not None and r.get("codevalue") is not None:
            code = str(r["codevalue"]).strip()
            code = code[:-2] if code.endswith(".0") else code
            out["codes"].setdefault(str(r["varname"]).upper(), {})[code] = str(r.get("valuelabel") or "")
    if not out["vars"]:
        raise SystemExit(f"dictionary has no varlist sheet: {list(sheets)}")
    return out


def fetch_ipeds(manifest):
    forced = os.environ.get("IPEDS_YEAR")
    this_year = datetime.date.today().year
    years = [int(forced)] if forced else range(this_year, this_year - 5, -1)
    for year in years:
        if all(exists(f"{IPEDS}{f}{year}.zip") for f in IPEDS_FILES):
            break
    else:
        raise SystemExit(f"no IPEDS year with all of {IPEDS_FILES} published (tried {list(years)})")
    (RAW / "ipeds").mkdir(parents=True, exist_ok=True)
    for f in IPEDS_FILES:
        name = f"{f}{year}"
        url = f"{IPEDS}{name}.zip"
        data = get(url)
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            member = pick_member(zf, ".csv")
            (RAW / "ipeds" / f"{f.lower()}.csv").write_bytes(zf.read(member))
        record(manifest, f"ipeds_{f.lower()}", url, data, year=year, member=member)
        durl = f"{IPEDS}{name}_Dict.zip"
        ddata = get(durl)
        with zipfile.ZipFile(io.BytesIO(ddata)) as zf:
            parsed = parse_dictionary(zf.read(pick_member(zf, ".xlsx")))
        write_json(RAW / "ipeds" / f"{f.lower()}_dict.json", parsed)
        record(manifest, f"ipeds_{f.lower()}_dict", durl, ddata, year=year)
        print(f"IPEDS {name}: {member}, {len(parsed['vars'])} variables in dictionary")
    manifest["ipeds_year"] = year


def fetch_scorecard(manifest):
    url = os.environ.get("SCORECARD_ZIP_URL")
    if not url:
        page = get(SCORECARD_PAGE).decode("utf-8", "ignore")
        links = re.findall(r"""["'(]((?:https?:)?//[^"'()\s]*Most-Recent-Cohorts-Institution[^"'()\s]*\.zip)""", page)
        if not links:
            raise SystemExit(f"no Most-Recent-Cohorts-Institution zip linked from {SCORECARD_PAGE}; "
                             "set SCORECARD_ZIP_URL to the 'Most Recent Institution-Level Data' download link")
        url = links[0] if links[0].startswith("http") else "https:" + links[0]
    data = get(url)
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        members = [n for n in zf.namelist() if n.lower().endswith(".csv") and "institution" in n.lower()]
        member = (members or [pick_member(zf, ".csv")])[0]
        (RAW / "scorecard").mkdir(parents=True, exist_ok=True)
        (RAW / "scorecard" / "institutions.csv").write_bytes(zf.read(member))
    record(manifest, "scorecard", url, data, member=member)
    print(f"Scorecard: {url} -> {member}")


def main():
    manifest = {"fetched_at": datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds"),
                "files": {}}
    fetch_ipeds(manifest)
    fetch_scorecard(manifest)
    write_json(OUT / "manifest.json", manifest)
    print(json.dumps({k: v["url"] for k, v in manifest["files"].items()}, indent=2))


if __name__ == "__main__":
    main()
