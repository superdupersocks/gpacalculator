#!/usr/bin/env python3
"""Each college's own website, for the college node in its page's structured data.

    python3 scripts/admissions/phase4_websites.py

Admissions Phase 4. Reads the website IPEDS lists for each college (HD2024 WEBADDR, the `website` column of
data/admissions/institutions.csv) and writes data/admissions/audit/phase4_websites.csv (ipeds_unitid,
college_website), which scripts/admissions/phase4_websites_live.sh puts on every published college page with that
IPEDS ID. The page's structured data then gives the address as the college's url (Harvard: http://www.harvard.edu/),
and our page stays the WebPage about it.

Addresses as IPEDS gives them, with the scheme added where IPEDS leaves it out ("www.harvard.edu/" is
http://www.harvard.edu/, which every college site answers, whether or not it then moves to https), and with stray dots
after the host and anything after a "#" dropped. An entry that isn't one web address (an email address, two
addresses, no host) is left out and listed. Changes nothing on the site.
"""
import csv
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit, urlunsplit

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / "data/admissions/institutions.csv"
OUT = ROOT / "data/admissions/audit/phase4_websites.csv"
HOST = re.compile(r"^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$")


def website(raw):
    """The absolute address for an IPEDS WEBADDR, or None with why it isn't one."""
    url = (raw or "").split("#", 1)[0].strip()
    if not url:
        return None, "empty"
    if "@" in url or " " in url:
        return None, "not a web address"
    if not re.match(r"^https?://", url, re.I):
        url = "http://" + url
    parts = urlsplit(url)
    host = (parts.hostname or "").rstrip(".").lower()
    if not HOST.match(host) or parts.username or parts.port:
        return None, "no host"
    return urlunsplit((parts.scheme.lower(), host, parts.path or "/", parts.query, "")), ""


def main():
    rows, dropped = [], []
    with SRC.open(newline="", encoding="utf-8") as f:
        for r in csv.DictReader(f):
            url, why = website(r.get("website"))
            if url:
                rows.append({"ipeds_unitid": r["unitid"], "college_website": url})
            elif why != "empty":
                dropped.append((r["unitid"], r["name"], r.get("website"), why))
    rows.sort(key=lambda r: int(r["ipeds_unitid"]))
    with OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["ipeds_unitid", "college_website"], lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    https = sum(r["college_website"].startswith("https://") for r in rows)
    print(f"{len(rows)} websites ({https} https as IPEDS gives them, {len(rows) - https} http) in {OUT.relative_to(ROOT)}")
    for unitid, name, raw, why in dropped:
        print(f"  left out {unitid} {name}: {raw!r} ({why})")
    return 0


if __name__ == "__main__":
    sys.exit(main())
