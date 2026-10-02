"""Download the federal source files for the admissions data into data/admissions/raw/.

    python3 scripts/admissions/fetch.py            # newest published year of each IPEDS file
    IPEDS_YEAR=2024 python3 scripts/admissions/fetch.py

Sources (Department of Education, public, no key needed):
- College Scorecard, Most Recent Institution-Level Data (https://collegescorecard.ed.gov/data/): the release
  linked from that page when it can be read, else the pinned SCORECARD_ZIP; SCORECARD_ZIP_URL overrides both.
- IPEDS complete data files HD, ADM and IC, each from its newest year that has a dictionary
  (https://nces.ed.gov/ipeds/datacenter/data/<FILE><year>.zip and <FILE><year>_Dict.zip).

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

# The Scorecard hosts refuse requests that don't look like a browser (403), so send a browser's headers.
HEADERS = {
    "User-Agent": "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    "Accept-Language": "en-US,en;q=0.9",
    "Referer": "https://collegescorecard.ed.gov/data/",
}
IPEDS = "https://nces.ed.gov/ipeds/datacenter/data/"
SCORECARD_PAGE = "https://collegescorecard.ed.gov/data/"
# "Most Recent Institution-Level Data", release of June 10, 2026. Update when Scorecard publishes a new release.
SCORECARD_ZIP = "https://ed-public-download.scorecard.network/downloads/Most-Recent-Cohorts-Institution_06102026.zip"
IPEDS_FILES = ["HD", "ADM", "IC"]


def get(url, tries=4):
    for i in range(tries):
        try:
            req = urllib.request.Request(url, headers=HEADERS)
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
        req = urllib.request.Request(url, headers={**HEADERS, "Range": "bytes=0-1"})
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
    """Each file from its own newest published year (ADM, HD and IC are released on different schedules)."""
    forced = os.environ.get("IPEDS_YEAR")
    this_year = datetime.date.today().year
    years = [int(forced)] if forced else list(range(this_year, this_year - 5, -1))
    (RAW / "ipeds").mkdir(parents=True, exist_ok=True)
    manifest["ipeds_years"] = {}
    for f in IPEDS_FILES:
        year = next((y for y in years if exists(f"{IPEDS}{f}{y}.zip") and exists(f"{IPEDS}{f}{y}_Dict.zip")), None)
        if year is None:
            raise SystemExit(f"no published IPEDS {f} file with a dictionary (tried {years})")
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
        manifest["ipeds_years"][f.lower()] = year
        print(f"IPEDS {name}: {member}, {len(parsed['vars'])} variables in dictionary")


def fetch_scorecard(manifest):
    url = os.environ.get("SCORECARD_ZIP_URL")
    if not url:
        try:  # a newer release than the pinned one, if the data page links it
            page = get(SCORECARD_PAGE, tries=1).decode("utf-8", "ignore")
            links = re.findall(r"""(https://[^"'()\s]*Most-Recent-Cohorts-Institution[^"'()\s]*\.zip)""", page)
            url = links[0] if links else None
        except Exception as e:  # the page refuses scripted requests (403); fall back to the pinned release
            print(f"Scorecard data page not readable ({e}); using {SCORECARD_ZIP}")
        url = url or SCORECARD_ZIP
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
