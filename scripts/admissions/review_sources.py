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
from fetch import HEADERS, get  # noqa: E402

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


def text_rows(data):
    """Rows of a CSV or delimited text file (comma, tab or pipe, whichever the first line uses most)."""
    text = data.decode("utf-8-sig", errors="replace")
    first = text.split("\n", 1)[0]
    delim = max(",\t|", key=first.count)
    return list(csv.reader(io.StringIO(text), delimiter=delim))


def sheets(data, name):
    """(label, rows) for every table in a file: each worksheet of an xlsx or xls, a CSV or text file, or each of
    those inside a zip. Rows are lists of text; dates read MM/DD/YYYY."""
    low = name.lower().split("?")[0]
    if data[:2] == b"PK" and not low.endswith(".xlsx"):
        out = []
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            print("  zip holds: " + "; ".join(f"{i.filename} ({i.file_size:,} bytes)" for i in zf.infolist()))
            for n in zf.namelist():
                if re.search(r"\.(xlsx|xls|csv|txt)$", n, flags=re.I):
                    out += sheets(zf.read(n), n)
        return out
    if re.search(r"\.(csv|txt)$", low):
        return [(name, text_rows(data))]
    if low.endswith(".xls"):
        import xlrd
        book = xlrd.open_workbook(file_contents=data)

        def cell(s, i, j):
            c = s.cell(i, j)
            if c.ctype == xlrd.XL_CELL_DATE:
                return xlrd.xldate_as_datetime(c.value, book.datemode).strftime("%m/%d/%Y")
            return str(c.value)
        return [(f"{name} [{s.name}]", [[cell(s, i, j) for j in range(s.ncols)] for i in range(s.nrows)])
                for s in book.sheets()]
    import openpyxl
    wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
    out = []
    for ws in wb.worksheets:
        ws.reset_dimensions()  # read-only mode stops at the size the file records, and FSA's records 10 rows
        out.append((f"{name} [{ws.title}]",
                    [["" if v is None else (v.strftime("%m/%d/%Y") if hasattr(v, "strftime") else str(v)) for v in row]
                     for row in ws.iter_rows(values_only=True)]))
    return out


def fsa_table(data, name):
    """(label, FSA_COLUMNS rows) of the biggest table in a file or zip that has FSA's header."""
    best, errors = None, []
    for label, rows in sheets(data, name):
        try:
            parsed = fsa_rows(rows)
        except SystemExit as e:
            errors.append(f"{label}: {e}")
            continue
        print(f"  {label}: {len(parsed):,} rows")
        if best is None or len(parsed) > len(best[1]):
            best = (label, parsed)
    if best is None:
        raise SystemExit("; ".join(errors) or f"no table in {name}")
    return best


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
                # a school has an OPEID and a closing date; the sheet's summary by year below the list has neither
                if rec["opeid"] and re.match(r"\d{1,2}/\d{1,2}/\d{4}$|\d{4}-\d{2}-\d{2}", rec["close_date"]):
                    rec["opeid"] = re.sub(r"\D", "", rec["opeid"]).zfill(8)
                    out.append({f: rec.get(f, "") for f in FSA_COLUMNS})
            return out
    raise SystemExit("no header row with an OPEID and a closing date in FSA's file")


def json_records(obj):
    """The longest list of records in a JSON value whose fields name an OPEID and a closing date, else []."""
    best = []
    if isinstance(obj, dict):
        for v in obj.values():
            found = json_records(v)
            best = found if len(found) > len(best) else best
    elif isinstance(obj, list):
        if obj and all(isinstance(r, dict) for r in obj):
            keys = [key(k) for k in obj[0]]
            if any(k.startswith("ope") for k in keys) and any("close" in k for k in keys):
                return obj
        for v in obj:
            found = json_records(v)
            best = found if len(found) > len(best) else best
    return best


def records_table(records):
    """JSON records as rows: a header of their fields, then their values."""
    fields = list(records[0])
    return [fields] + [["" if r.get(f) is None else str(r.get(f)) for f in fields] for r in records]


def fsa_rendered():
    """FSA's page is a JavaScript app ("School Data Webapp"), so open it in Chromium: take a file it links, a download
    one of its controls starts, or JSON it loads with OPEIDs and closing dates. Logs what it loads and offers."""
    from playwright.sync_api import sync_playwright
    tried = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        page = browser.new_page(accept_downloads=True, user_agent=HEADERS["User-Agent"])
        seen = []
        page.on("response", lambda r: seen.append(r))
        page.goto(FSA_PAGE, wait_until="domcontentloaded", timeout=120_000)
        try:
            page.wait_for_load_state("networkidle", timeout=60_000)
        except Exception as e:  # noqa: BLE001 - an app that keeps polling never goes idle; read what it has
            print(f"FSA page in Chromium: not idle after 60 s ({e.__class__.__name__})")
        page.wait_for_timeout(5_000)
        html = page.content()
        print("FSA page in Chromium: " + describe(html))
        for r in seen[:80]:
            print(f"  loaded: {r.status} {r.request.method} {r.url[:160]} {r.headers.get('content-type', '')}")
        for r in seen:
            if "json" not in r.headers.get("content-type", ""):
                continue
            try:
                records = json_records(r.json())
                if records:
                    rows = fsa_rows(records_table(records))
                    return r.url, r.body(), rows
            except (Exception, SystemExit) as e:  # noqa: BLE001 - try the next response
                tried.append(f"{r.url}: {e}")
        for url in links(html, FSA_PAGE):
            try:
                data = get(url)
                label, rows = fsa_table(data, url)
                return f"{url} ({label})", data, rows
            except (Exception, SystemExit) as e:  # noqa: BLE001 - try the next link
                tried.append(f"{url}: {e}")
        controls = page.locator("a, button, [role=button], [role=tab], [role=link]")
        texts = [" ".join((controls.nth(i).inner_text() or "").split()) for i in range(min(controls.count(), 200))]
        print("  controls: " + " | ".join(t for t in texts if t)[:3000])
        for i, text in enumerate(texts):
            if not re.search(r"download|excel|xlsx|csv|export|closed school", text, flags=re.I):
                continue
            try:
                with page.expect_download(timeout=60_000) as info:
                    controls.nth(i).click()
                dl = info.value
                data = Path(dl.path()).read_bytes()
                label, rows = fsa_table(data, dl.suggested_filename)
                return f"{FSA_PAGE} ({text!r}: {label})", data, rows
            except (Exception, SystemExit) as e:  # noqa: BLE001 - try the next control
                tried.append(f"control {text!r}: {e}")
        browser.close()
    raise SystemExit("nothing readable in the rendered page:\n" + "\n".join(tried))


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
            label, rows = fsa_table(data, url)
            url = f"{url} ({label})"
        except (Exception, SystemExit) as e:  # noqa: BLE001 - try the next link
            tried.append(f"{url}: {e}")
            continue
        print(f"FSA closed schools: {len(rows):,} rows from {url}")
        return url, data, rows
    try:
        url, data, rows = fsa_rendered()
    except (Exception, SystemExit) as e:  # noqa: BLE001 - report every address tried
        raise SystemExit("no closed-school file could be read:\n" + "\n".join(tried + [f"{FSA_PAGE} in Chromium: {e}"]))
    print(f"FSA closed schools: {len(rows):,} rows from {url}")
    return url, data, rows


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
