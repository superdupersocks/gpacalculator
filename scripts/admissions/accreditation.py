"""Institutional accreditor of each college, from the Department of Education's DAPIP database.

    python3 scripts/admissions/accreditation.py [--institutions CSV] [--out DIR]

DAPIP (Database of Accredited Postsecondary Institutions and Programs, https://ope.ed.gov/dapip/) is the
Department's own list of which recognized agency accredits each institution. Its download (one zip of
workbooks) is read here; set DAPIP_ZIP_FILE to use a copy downloaded by hand.

Writes data/admissions/accreditation.csv: unitid, accreditor, status, since (date of the current action),
source. Only institutional accreditation counts (not program accreditors such as nursing boards), and only
"Accredited" or "Pre-Accredited" records. Colleges DAPIP doesn't link to an IPEDS ID stay out; nothing is
guessed. Optional: when the download fails, the step warns and leaves the previous file in place.
"""
import argparse
import csv
import io
import os
import re
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, write_csv  # noqa: E402

URLS = ["https://ope.ed.gov/dapip/api/downloadFiles/accreditationDataFiles",
        "https://ope.ed.gov/dapip/api/downloadFiles/accreditationDataFiles?fileType=csv"]
COLUMNS = ["unitid", "accreditor", "status", "since", "source"]


def tables(data):
    """{file name: list of row dicts} for every CSV or workbook sheet in the zip."""
    out = {}
    with zipfile.ZipFile(io.BytesIO(data)) as zf:
        for name in zf.namelist():
            low = name.lower()
            if low.endswith(".csv"):
                text = zf.read(name).decode("utf-8-sig", "ignore")
                out[name] = list(csv.DictReader(io.StringIO(text)))
            elif low.endswith((".xlsx", ".xlsm")):
                import openpyxl
                wb = openpyxl.load_workbook(io.BytesIO(zf.read(name)), read_only=True, data_only=True)
                for ws in wb.worksheets:
                    it = ws.iter_rows(values_only=True)
                    head = next(it, None)
                    if head:
                        keys = [str(h or "").strip() for h in head]
                        out[f"{name}:{ws.title}"] = [dict(zip(keys, ["" if v is None else str(v) for v in r]))
                                                      for r in it]
    return out


def col(row, *patterns):
    """The value of the first column whose name matches one of the patterns (DAPIP's names vary by file)."""
    for p in patterns:
        for k, v in row.items():
            if re.fullmatch(p, k.replace(" ", "").replace("_", ""), re.I):
                return (v or "").strip()
    return ""


def accreditors(tabs):
    """{unitid: (agency, status, date)} from institutional accreditation records."""
    found = {}
    for name, rows in tabs.items():
        if not rows or not any(re.search(r"agency", k, re.I) for k in rows[0]):
            continue
        for r in rows:
            ids = col(r, r"ipedsunitids?", r"unitids?", r"ipedsid")
            agency = col(r, r"agencyname", r"agency")
            kind = col(r, r"programname", r"accreditationtype", r"program")
            status = col(r, r"accreditationstatus", r"status")
            if not ids or not agency:
                continue
            if kind and "institution" not in kind.lower():
                continue  # program accreditors (nursing, law, ...) are not the college's accreditor
            if status and not re.match(r"(pre-?)?accredited", status, re.I):
                continue
            date = col(r, r"accreditationdate", r"periodicreviewdate", r"actiondate", r"date")
            for uid in re.findall(r"\d{6}", ids):
                found.setdefault(uid, (agency, status or "Accredited", date[:10]))
    return found


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--out", default=str(OUT))
    a = ap.parse_args(argv)
    ours = {r["unitid"] for r in csv.DictReader(open(a.institutions, encoding="utf-8"))}
    data, url = None, None
    if os.environ.get("DAPIP_ZIP_FILE"):
        url = os.environ["DAPIP_ZIP_FILE"]
        data = open(url, "rb").read()
    else:
        import urllib.request
        from fetch import HEADERS, get

        def post(u):  # the download endpoint answers GET with 405
            req = urllib.request.Request(u, data=b"{}", method="POST",
                                         headers={**HEADERS, "Content-Type": "application/json",
                                                  "Accept": "application/zip, application/octet-stream, */*",
                                                  "Referer": "https://ope.ed.gov/dapip/"})
            with urllib.request.urlopen(req, timeout=300) as r:
                return r.read()

        for u, how in [(u, m) for u in URLS for m in (post, lambda x: get(x, tries=2))]:
            try:
                data = how(u)
                if data[:2] == b"PK":
                    url = u
                    break
                print(f"DAPIP {u}: not a zip ({data[:80]!r})")
                data = None
            except Exception as e:
                print(f"DAPIP {u}: {e}")
    if not data:
        print("WARNING: DAPIP not downloaded; accreditation.csv left as it was")
        return
    tabs = tables(data)
    for name, rows in tabs.items():
        print(f"DAPIP {name}: {len(rows):,} rows; columns {list(rows[0])[:14] if rows else []}")
    found = accreditors(tabs)
    rows = [{"unitid": u, "accreditor": ag, "status": st, "since": dt, "source": f"DAPIP ({url})"}
            for u, (ag, st, dt) in sorted(found.items()) if u in ours]
    write_csv(Path(a.out) / "accreditation.csv", rows, COLUMNS)
    print(f"{len(rows):,} of {len(ours):,} colleges with an institutional accreditor")


if __name__ == "__main__":
    main()
