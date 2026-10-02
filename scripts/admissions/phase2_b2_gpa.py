#!/usr/bin/env python3
"""The Common Data Set GPAs the college pages can publish now (Admissions Phase 2, step B2).

    python3 scripts/admissions/phase2_b2_gpa.py

Digant's go for B (2026-10-02): "Replace GPA with verified, cited CDS values wherever available". A page gets the
average high school GPA (CDS C12) its college reported when:

- the post is confidently matched to the college (match.csv: exact, renamed or alias; not corrected in
  audit/corrections.csv, and not unpublished by checkpoint C or D);
- the value passed the Phase 1 checks (cds_provenance.csv: verified) and lies between 1 and 5;
- the file is the college's own: it sits on the college's web domain (IPEDS website) or on one of the college's own
  hosts in OWN_HOSTS. Files on Google Drive or Sheets, Box or a third-party CDN count once cds_pages.py has found the
  college's own page that links to them, and the GPA then cites that page (Digant's rule); until then they wait. A
  file on another college's domain is that college's, not this one's.

Writes data/admissions/audit/phase2_b2_gpa.csv (the rows scripts/admissions/phase2_b2_live.sh imports) and
phase2_b2_gpa_pending.csv (the rest, with the reason). A GPA above 4.0 is marked weighted: an unweighted 4.0 scale
can't average above 4.0. Otherwise the basis stays blank, since the files don't state it in a form we read.
"""
import csv
from pathlib import Path
from urllib.parse import urlparse

DATA = Path(__file__).resolve().parents[2] / "data" / "admissions"
AUDIT = DATA / "audit"
CONFIDENT = {"exact", "renamed", "alias"}
# Hosts that belong to the college although they aren't on the domain IPEDS lists for it.
OWN_HOSTS = {
    "236948": ("cdn.uw.edu", "the University of Washington's own CDN (uw.edu); the file is the Seattle campus's"),
    "230807": ("westminsteru.edu", "Westminster University's domain since its 2023 renaming"),
    "197036": ("s3.amazonaws.com/usma-media/", "the Military Academy's own media bucket (usma-media)"),
    "110422": ("content-calpoly-edu.s3.amazonaws.com", "Cal Poly's own bucket (content-calpoly-edu)"),
    "239318": ("msoe.s3.amazonaws.com", "the Milwaukee School of Engineering's own bucket"),
}
COLS = ["slug", "unitid", "college", "cds_gpa", "cds_gpa_year", "cds_gpa_submit_pct", "cds_gpa_basis",
        "cds_gpa_source_url"]
PENDING_COLS = ["slug", "unitid", "college", "gpa", "cds_year", "source_url", "why"]


def read(path):
    with open(path, newline="") as f:
        return list(csv.DictReader(f))


def host(url):
    return (urlparse(url if "://" in url else "http://" + url).hostname or "").lower().removeprefix("www.")


def site(h):
    return ".".join(h.split(".")[-2:])


def own_file(unitid, url, website):
    if unitid in OWN_HOSTS:
        prefix = OWN_HOSTS[unitid][0]
        return (host(url) + urlparse(url).path).startswith(prefix) or host(url) == prefix
    return site(host(url)) == site(host(website))


def why_not_own(url, website, colleges_by_site):
    h = host(url)
    if "google" in h:
        return f"file on {h} (Google Drive or Sheets): needs the college's own page that links to it"
    if "boxcloud" in h or "box.com" in h:
        return f"file on {h} (Box): needs the college's own page that links to it"
    other = colleges_by_site.get(site(h))
    if other and site(h) != site(host(website)):
        return f"file on {site(h)}, the domain of {other}: another college's Common Data Set"
    return f"file on {h}, not the college's domain ({site(host(website))}): needs the college's page that links to it"


def main():
    inst = {r["unitid"]: r for r in read(DATA / "institutions.csv")}
    colleges_by_site = {}
    for r in inst.values():
        if r["website"]:
            colleges_by_site.setdefault(site(host(r["website"])), f"{r['name']} ({r['city']}, {r['state']})")
    cds = {r["unitid"]: r for r in read(DATA / "cds_values.csv") if r["gpa_avg"]}
    verified = {r["unitid"] for r in read(DATA / "cds_provenance.csv") if r["field"] == "gpa_avg" and r["verified"] == "yes"}
    pages = {r["unitid"]: r for r in read(DATA / "cds_pages.csv")} if (DATA / "cds_pages.csv").exists() else {}
    corrected = {r["slug"] for r in read(AUDIT / "corrections.csv")}
    unpublished = {r["slug"] for r in read(AUDIT / "phase2_cd_actions.csv")}

    rows, pending = [], []
    for m in read(DATA / "match.csv"):
        u = m["unitid"]
        if u not in cds or m["method"] not in CONFIDENT or m["slug"] in corrected or m["slug"] in unpublished:
            continue
        c, i = cds[u], inst[u]
        gpa = c["gpa_avg"].strip()
        if u not in verified:
            pending.append([m["slug"], u, i["name"], gpa, c["cds_year"], c["source_url"], "the value didn't pass the checks"])
        elif not 1.0 <= float(gpa) <= 5.0:
            pending.append([m["slug"], u, i["name"], gpa, c["cds_year"], c["source_url"], "outside 1.0-5.0"])
        elif not own_file(u, c["source_url"], i["website"]):
            page = pages.get(u)
            if page and page["file_url"] == c["source_url"] and site(host(page["page_url"])) == site(host(i["website"])):
                rows.append([m["slug"], u, i["name"], gpa, c["cds_year"], c["gpa_submit_pct"].strip(),
                             "weighted" if float(gpa) > 4.0 else "", page["page_url"]])  # cite the college's page
            else:
                pending.append([m["slug"], u, i["name"], gpa, c["cds_year"], c["source_url"],
                                why_not_own(c["source_url"], i["website"], colleges_by_site)])
        else:
            rows.append([m["slug"], u, i["name"], gpa, c["cds_year"], c["gpa_submit_pct"].strip(),
                         "weighted" if float(gpa) > 4.0 else "", c["source_url"]])

    for name, cols, data in (("phase2_b2_gpa.csv", COLS, rows), ("phase2_b2_gpa_pending.csv", PENDING_COLS, pending)):
        with open(AUDIT / name, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(cols)
            w.writerows(sorted(data))
    print(f"publish now: {len(rows)} (weighted: {sum(1 for r in rows if r[6])}); pending: {len(pending)}")


if __name__ == "__main__":
    main()
