#!/usr/bin/env python3
"""Sources for the identity review that only GitHub's runners can download (the cloud sessions can't reach ed.gov).

    python3 scripts/admissions/review_sources.py

Writes data/admissions/review/:
- fsa_closed_schools.csv: Federal Student Aid's weekly closed-school file, every school that left the federal aid
  programs by closing, with its OPEID and closing date (opeid, name, address, city, state, zip, country, close_date)
- ipeds_history.csv: the older IPEDS directory records (HD2002-HD2023) of the colleges the review can't place, one
  row per stretch of years with the same name, place and OPEID (unitid, first_year, last_year, name, city, state,
  opeid, website, closed, newid, deathyr)
- sources.json: where each came from, when, and its SHA-256

The review (scripts/admissions/phase2_r_review.py) reads them; this changes nothing on the site.
"""
import csv
import hashlib
import io
import json
import os
import re
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
import audit  # noqa: E402
from fetch import get  # noqa: E402

DATA = Path(__file__).resolve().parents[2] / "data" / "admissions"
OUT = DATA / "review"
FSA_PAGE = "https://fsapartners.ed.gov/additional-resources/reports/weekly-closed-school-search-file"
FSA_OLD = "https://www2.ed.gov/offices/OSFAP/PEPS/docs/closedschoolsearch.xlsx"
FSA_COLUMNS = ["opeid", "name", "address", "city", "state", "zip", "country", "close_date"]
# FSA's header for each column (lowercased, spaces and punctuation dropped), in the order tried
FSA_HEADERS = {
    "opeid": ("opeid", "opeidnumber", "opeidno", "ope"),
    "name": ("schoolname", "institutionname", "name", "school"),
    "address": ("address", "address1", "streetaddress", "locationaddress"),
    "city": ("city", "locationcity"),
    "state": ("state", "st", "locationstate"),
    "zip": ("zip", "zipcode", "postalcode"),
    "country": ("country",),
    "close_date": ("closedate", "closeddate", "dateclosed", "closingdate", "closurdate", "closuredate"),
}
HIST_COLUMNS = ["unitid", "first_year", "last_year", "name", "city", "state", "opeid", "website", "closed", "newid",
                "deathyr"]


def links(html, base):
    """Links to a spreadsheet, CSV or zip anywhere in a page (attributes or scripts), absolute, in page order."""
    out = []
    text = html.replace("\\/", "/")  # JSON in scripts escapes slashes
    for href in re.findall(r"""[^"'\s<>()]+\.(?:xlsx|xls|csv|zip)(?:\?[^"'\s<>()#]*)?(?=["'\s<>()#]|$)""", text,
                           flags=re.I):
        url = href if href.startswith("http") else re.sub(r"(https?://[^/]+).*", r"\1", base) + "/" + href.lstrip("/")
        if url not in out:
            out.append(url)
    return out


def describe(html):
    """What a page holds, for the log when it links no file: its title and the links and words about closed schools."""
    title = re.search(r"<title[^>]*>(.*?)</title>", html, flags=re.I | re.S)
    hrefs = re.findall(r"""href=["']([^"']+)["']""", html, flags=re.I)
    near = [h for h in hrefs if re.search(r"closed|download|file|report|xls|csv|api", h, flags=re.I)]
    words = re.findall(r"[^<>\"']{0,80}[Cc]losed [Ss]chool[^<>\"']{0,80}", html)
    lines = [f"{len(html):,} characters; title {title.group(1).strip()[:120] if title else None!r}; {len(hrefs)} links"]
    lines += [f"  link: {h}" for h in near[:40]]
    lines += [f"  text: {' '.join(w.split())}" for w in words[:15]]
    if not title:
        lines.append("  start: " + " ".join(html[:600].split()))
    return "\n".join(lines)


def key(h):
    return re.sub(r"[^a-z0-9]", "", (h or "").lower())


def table(data, name):
    """Rows (lists of text) from an xlsx, xls, csv or a zip holding one of them."""
    low = name.lower().split("?")[0]
    if data[:2] == b"PK" and not low.endswith(".xlsx"):
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            inner = [n for n in zf.namelist() if re.search(r"\.(xlsx|xls|csv)$", n, flags=re.I)]
            if inner:
                return table(zf.read(inner[0]), inner[0])
    if low.endswith(".csv"):
        text = data.decode("utf-8-sig", errors="replace")
        return [r for r in csv.reader(io.StringIO(text))]
    if low.endswith(".xls"):
        import xlrd
        book = xlrd.open_workbook(file_contents=data)
        sheet = book.sheet_by_index(0)
        return [[str(sheet.cell_value(i, j)) for j in range(sheet.ncols)] for i in range(sheet.nrows)]
    import openpyxl
    wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
    ws = wb.worksheets[0]
    return [["" if v is None else (v.strftime("%m/%d/%Y") if hasattr(v, "strftime") else str(v)) for v in row]
            for row in ws.iter_rows(values_only=True)]


def fsa_rows(rows):
    """FSA's rows as FSA_COLUMNS dicts: the header is the first row naming an OPEID and a closing date."""
    for n, row in enumerate(rows[:50]):
        keys = [key(c) for c in row]
        if any(k.startswith("ope") for k in keys) and any("close" in k for k in keys):
            col = {}
            for field, names in FSA_HEADERS.items():
                for name in names:
                    if name in keys:
                        col[field] = keys.index(name)
                        break
            missing = [f for f in ("opeid", "name", "close_date") if f not in col]
            if missing:
                raise SystemExit(f"FSA's header lacks {missing}: {row}")
            out = []
            for r in rows[n + 1:]:
                rec = {f: (str(r[i]).strip() if i < len(r) and r[i] is not None else "") for f, i in col.items()}
                if rec["opeid"]:
                    rec["opeid"] = re.sub(r"\D", "", rec["opeid"]).zfill(8)
                    out.append({f: rec.get(f, "") for f in FSA_COLUMNS})
            return out
    raise SystemExit("no header row with an OPEID and a closing date in FSA's file")


def fsa():
    tried = []
    try:
        page = get(FSA_PAGE).decode("utf-8", errors="replace")
        candidates = links(page, FSA_PAGE)
        print(f"FSA page: {len(candidates)} file links" + "".join(f"\n  {u}" for u in candidates))
        if not candidates:
            print(describe(page))
    except Exception as e:  # noqa: BLE001 - fall back to PEPS's old address
        print(f"FSA page: {e}")
        candidates = []
    for url in candidates + [FSA_OLD]:
        try:
            data = get(url)
            rows = fsa_rows(table(data, url))
        except (Exception, SystemExit) as e:  # noqa: BLE001 - try the next link
            tried.append(f"{url}: {e}")
            continue
        print(f"FSA closed schools: {len(rows):,} rows from {url}")
        return url, data, rows
    raise SystemExit("no closed-school file could be read:\n" + "\n".join(tried))


def review_unitids():
    """UNITIDs of the older IPEDS records the review names for the pages it can't place."""
    ids = set()
    for name in ("unmatched.csv", "closed.csv", "merged.csv"):
        path = DATA / "audit" / name
        if path.exists():
            with open(path, newline="", encoding="utf-8") as f:
                for r in csv.DictReader(f):
                    ids |= set(re.findall(r"UNITID (\d+)", r.get("older_ipeds", "") + " " + r.get("evidence", "")))
    with open(DATA / "audit" / "phase2_e_held.csv", newline="", encoding="utf-8") as f:
        ids |= {r["unitid"] for r in csv.DictReader(f)}
    return ids


def history(ids):
    """ipeds_history.csv rows for ids: one per stretch of years with the same name, place and OPEID."""
    audit.KEEP = tuple(audit.KEEP) + ("OPEID", "WEBADDR")
    hist = audit.load_history(2002, 2023)
    out = []
    for uid in sorted(ids & set(hist)):
        span = None
        for y in sorted(hist[uid]):
            r = hist[uid][y]
            rec = {"name": r["INSTNM"], "city": r["CITY"], "state": r["STABBR"],
                   "opeid": re.sub(r"\D", "", r.get("OPEID", "")).zfill(8) if r.get("OPEID") else "",
                   "website": r.get("WEBADDR", ""), "closed": r["CLOSEDAT"] if audit.is_set(r["CLOSEDAT"]) else "",
                   "newid": r["NEWID"] if audit.is_set(r["NEWID"]) else "",
                   "deathyr": r["DEATHYR"] if audit.is_set(r["DEATHYR"]) else ""}
            if span and all(span[k] == rec[k] for k in ("name", "city", "state", "opeid")):
                span.update({k: rec[k] or span[k] for k in ("website", "closed", "newid", "deathyr")}, last_year=y)
            else:
                span = {"unitid": uid, "first_year": y, "last_year": y, **rec}
                out.append(span)
    return out


def write(path, cols, rows):
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=cols, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    url, data, rows = fsa()
    write(OUT / "fsa_closed_schools.csv", FSA_COLUMNS, rows)
    hist = history(review_unitids())
    write(OUT / "ipeds_history.csv", HIST_COLUMNS, hist)
    (OUT / "sources.json").write_text(json.dumps({
        "fetched_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "fsa_closed_schools": {"url": url, "bytes": len(data), "sha256": hashlib.sha256(data).hexdigest(),
                               "rows": len(rows)},
        "ipeds_history": {"url": audit.IPEDS + "HD{2002..2023}.zip", "rows": len(hist)},
    }, indent=1) + "\n")
    print(f"{len(rows):,} closed schools; {len(hist):,} history rows for the review")


if __name__ == "__main__":
    main()
