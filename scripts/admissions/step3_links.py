#!/usr/bin/env python3
"""The official admissions link for each college page (step 3 of Digant's plan of 2026-10-03, the new template).

    python3 scripts/admissions/step3_links.py build    write data/admissions/step3/links.tsv, the addresses to check
    python3 scripts/admissions/step3_links.py pick     after the check: write data/admissions/audit/step3_admissions_links.csv

build: for every published college page with an IPEDS ID (data/admissions/tiering/tiers.csv, the page's ID from the
Phase 2 imports), the admissions office address IPEDS lists for the college (HD2024 ADMINURL, the `admissions_url`
column of data/admissions/institutions.csv) and its website (WEBADDR, `website`), with the scheme added where IPEDS
leaves it out. .github/workflows/admissions-links.yml then requests each one from GitHub
(scripts/admissions/step3_links_check.py) and commits data/admissions/step3/links_status.tsv.

pick: a page links to the college's admissions page when that address answered 200 (after redirects) on the
college's own site, the site of its IPEDS website (harvard.edu for college.harvard.edu/admissions); else to its website
on the same terms; else to nothing. A page never links to an address the check couldn't load or that ends on another
site. Writes ipeds_unitid, college_admissions_url, college_admissions_url_kind (admissions or website), plus the
college's street address and ZIP code from IPEDS (HD2024 ADDR and ZIP: college_street, college_zip) for template v2's
CollegeOrUniversity schema (Digant, 2026-10-03 05:49), one row per page's college that has either. Then
scripts/admissions/step3_links_live.sh puts them on the pages. Changes nothing on the site.
"""
import csv
import os
import re
import sys
from collections import Counter
from urllib.parse import urlsplit, urlunsplit

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import tiering  # noqa: E402

ROOT = tiering.ROOT
OUT = os.path.join(ROOT, "data/admissions/step3")
LINKS = os.path.join(OUT, "links.tsv")
STATUS = os.path.join(OUT, "links_status.tsv")
PICKED = os.path.join(ROOT, "data/admissions/audit/step3_admissions_links.csv")
HOST = re.compile(r"^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$")
GENERIC = {"edu", "org", "com", "net", "info", "biz", "college", "university", "school", "academy"}


def absolute(raw):
    """An IPEDS address as an absolute URL ("www.isu.edu/future/" -> "http://www.isu.edu/future/"), or ''."""
    url = (raw or "").split("#", 1)[0].strip()
    if not url or "@" in url or " " in url:
        return ""
    if not re.match(r"^https?://", url, re.I):
        url = "http://" + url
    parts = urlsplit(url)
    host = (parts.hostname or "").rstrip(".").lower()
    if not HOST.match(host) or parts.username or parts.port:
        return ""
    return urlunsplit((parts.scheme.lower(), host, parts.path or "/", parts.query, ""))


def site_of(host):
    """The site a host belongs to: its last two labels under a generic top-level domain (college.harvard.edu ->
    harvard.edu); under .us its last four, since state and district hosts share three (x.cc.ia.us); else its last three."""
    labels = (host or "").lower().rstrip(".").split(".")
    if labels and labels[0] == "www":
        labels = labels[1:]
    if len(labels) >= 2 and labels[-1] in GENERIC:
        return ".".join(labels[-2:])
    return ".".join(labels[-4:]) if len(labels) >= 4 and labels[-1] == "us" else ".".join(labels[-3:])


def on_site(url, site):
    host = (urlsplit(url).hostname or "").lower().rstrip(".")
    return bool(site) and (host == site or host.endswith("." + site))


def pages():
    """[(slug, tier, unitid)] for every published college page with an IPEDS ID."""
    data = tiering.fields()
    out = []
    for r in tiering.read_csv(os.path.join(ROOT, "data/admissions/tiering/tiers.csv")):
        row = data.get(r["old_slug"] or r["slug"]) or data.get(r["slug"]) or {}
        unitid = (row.get("ipeds_unitid") or "").strip()
        if unitid.isdigit():
            out.append((r["slug"], r["tier"], unitid))
    return out


def institutions():
    with open(os.path.join(ROOT, "data/admissions/institutions.csv"), newline="", encoding="utf-8") as f:
        return {r["unitid"]: r for r in csv.DictReader(f)}


def build():
    inst = institutions()
    rows, seen, missing = [], set(), 0
    for slug, tier, unitid in pages():
        r = inst.get(unitid)
        if not r:
            missing += 1
            continue
        website = absolute(r.get("website"))
        site = site_of(urlsplit(website).hostname) if website else ""
        for kind, raw in (("admissions", r.get("admissions_url")), ("website", r.get("website"))):
            url = absolute(raw)
            if url and (unitid, kind) not in seen:
                seen.add((unitid, kind))
                rows.append({"unitid": unitid, "kind": kind, "url": url, "site": site, "tier": tier, "slug": slug})
    os.makedirs(OUT, exist_ok=True)
    with open(LINKS, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, ["unitid", "kind", "url", "site", "tier", "slug"], delimiter="\t", lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    kinds = Counter(r["kind"] for r in rows)
    print(f"{len(rows)} addresses to check ({kinds['admissions']} admissions pages, {kinds['website']} websites) "
          f"for {len({r['unitid'] for r in rows})} colleges; {missing} page IDs not in institutions.csv")


def pick():
    with open(LINKS, newline="", encoding="utf-8") as f:
        links = list(csv.DictReader(f, delimiter="\t"))
    with open(STATUS, newline="", encoding="utf-8") as f:
        status = {(r["unitid"], r["kind"]): r for r in csv.DictReader(f, delimiter="\t")}
    by_unit = {}
    for r in links:
        by_unit.setdefault(r["unitid"], {})[r["kind"]] = r
    picked, why = {}, Counter()
    for unitid, kinds in sorted(by_unit.items(), key=lambda x: int(x[0])):
        choice = None
        for kind in ("admissions", "website"):
            r = kinds.get(kind)
            s = status.get((unitid, kind)) if r else None
            if not s:
                why[f"{kind}: none"] += 1
                continue
            if s.get("error"):
                outcome = "error"
            elif s.get("final_status") != "200":
                outcome = "answered " + (s.get("final_status") or "nothing")
            elif not on_site(s.get("final_url", ""), r["site"]):
                outcome = "ends on another site"
            else:
                outcome = "ok"
            why[f"{kind}: {outcome}"] += 1
            if outcome == "ok" and not choice:
                # The address as the college lists it, unless it moved: then where it lands now
                choice = (kind, s["final_url"] if s.get("hops", "0") != "0" else r["url"])
        if choice:
            picked[unitid] = {"college_admissions_url": choice[1], "college_admissions_url_kind": choice[0]}
    inst, addressed = institutions(), 0
    rows = []
    for unitid in sorted({u for _, _, u in pages()} | set(picked), key=int):
        r = inst.get(unitid, {})
        street = " ".join((r.get("address") or "").split())
        zip_ = (r.get("zip") or "").strip()
        if not re.match(r"^\d{5}(-\d{4})?$", zip_) or not street or len(street) > 200:
            street, zip_ = "", ""
        addressed += bool(street)
        row = {"ipeds_unitid": unitid, "college_admissions_url": "", "college_admissions_url_kind": "",
               "college_street": street, "college_zip": zip_, **picked.get(unitid, {})}
        if row["college_admissions_url"] or street:
            rows.append(row)
    with open(PICKED, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, ["ipeds_unitid", "college_admissions_url", "college_admissions_url_kind", "college_street",
                               "college_zip"], lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    kinds = Counter(p["college_admissions_url_kind"] for p in picked.values())
    print(f"{len(picked)} of {len(by_unit)} colleges get a link: {kinds['admissions']} admissions pages, "
          f"{kinds['website']} websites; {len(by_unit) - len(picked)} none. {addressed} get a street address.")
    for k, n in sorted(why.items()):
        print(f"  {k}: {n}")


if __name__ == "__main__":
    {"build": build, "pick": pick}.get(sys.argv[1] if len(sys.argv) > 1 else "", lambda: print(__doc__))()
