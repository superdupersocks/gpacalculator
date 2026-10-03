#!/usr/bin/env python3
"""Cleanup QA for /admissions/ (Digant's plan of 2026-10-03, step 1): every college page the cleanup removed, what it
answers today, the redirect chains and 404s around it, its Search Console clicks, and the links that still point at it.

    python scripts/admissions/cleanup_qa.py urls      write data/admissions/cleanup_qa/urls.tsv and pages.txt
    python scripts/admissions/cleanup_qa.py report    read the live check's results and write the lists and report.md

`urls` lists each address to check on the live site: both forms (/admissions/<slug>/ and the old /admission/<slug>/)
of the 516 college pages published in the pre-cleanup export (data/colleges/, 2026-10-01) that the colleges sitemap
no longer lists, the old addresses Phase 2 gave rules (legacy map, the 358 /admissions/ twins, the first pass), the 112
it left unresolved, and every other non-live address Search Console showed in the last 12 months. pages.txt lists the
pages whose links are checked: everything in the other sitemaps, the hub and two college pages (their header and
footer).

.github/workflows/admissions-cleanup-qa.yml runs scripts/admissions/cleanup_qa_check.py on GitHub with these and
commits live_status.tsv and internal_links.tsv, which `report` reads.
"""
import csv
import glob
import json
import os
import re
import sys
from collections import Counter, defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SITE = "https://gpacalculator.net"
AUDIT = os.path.join(ROOT, "data/admissions/audit")
OUT = os.path.join(ROOT, "data/admissions/cleanup_qa")
LIVE_CSV = os.path.join(ROOT, "data/admissions/live_checks/sitemap_colleges.csv")
OTHER_CSV = os.path.join(ROOT, "data/admissions/live_checks/sitemap_other.csv")
GSC_CSV = os.path.join(ROOT, "data/admissions/search_console/pages_12m.csv")
ACTION_FILES = ["phase2_cd_actions.csv", "phase2_s_actions.csv", "phase2_r_actions.csv"]
URL_COLS = ["url", "slug", "form", "source", "expected", "clicks_12m", "impressions_12m"]


def read_csv(path, **kw):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f, **kw))


def slug_of(url):
    """The college slug of an /admissions/ or /admission/ address, or ''."""
    m = re.match(r"https?://(?:www\.)?gpacalculator\.net/admissions?/([^/?#]*)", url or "")
    return m.group(1).lower() if m else ""


def address(form, slug):
    return f"{SITE}/{form}/{slug}/"


def pre_cleanup():
    """slug -> record of every college published in the 2026-10-01 export."""
    out = {}
    for path in glob.glob(os.path.join(ROOT, "data/colleges/*.json")):
        if os.path.basename(path).startswith("_"):
            continue
        with open(path, encoding="utf-8") as f:
            rec = json.load(f)
        out[rec["slug"]] = rec
    return out


def live_slugs():
    return {slug_of(r["url"]) for r in read_csv(LIVE_CSV) if slug_of(r["url"])}


def phase2_actions():
    out = {}
    for name in ACTION_FILES:
        for r in read_csv(os.path.join(AUDIT, name)):
            out[r["slug"]] = r
    return out


def search_console():
    """(form, slug) -> [clicks, impressions] over the last 12 months, all URL variants of the address together."""
    agg = defaultdict(lambda: [0, 0])
    for r in read_csv(GSC_CSV):
        m = re.match(r"https?://(?:www\.)?gpacalculator\.net/(admissions?)/([^/?#]+)", r["page"])
        if m:
            key = (m.group(1), m.group(2).lower())
            agg[key][0] += int(r["clicks"])
            agg[key][1] += int(r["impressions"])
    return agg


def expected_rules():
    """slug -> (source, expected answer) for each old address a Phase 2 rule or list covers, first source first."""
    out = {}
    redirects = os.path.join(ROOT, "data/admissions/redirects")
    first = os.path.join(ROOT, "scripts/admissions/legacy_first_pass")
    for r in read_csv(os.path.join(redirects, "legacy_redirect_map.csv")):
        out.setdefault(r["old_slug"], ("legacy map", "410" if r["action"] == "410" else f"301 {r['target']}"))
    for r in read_csv(os.path.join(first, "actions.csv")):
        out.setdefault(r["old_slug"], ("old-slug twin", "410" if r["code"] == "410" else f"301 {r['target']}"))
    for r in read_csv(os.path.join(first, "actions-20261002-0631.csv")):
        out.setdefault(r["old_slug"], ("first pass", f"301 {r['target']}"))
    for r in read_csv(os.path.join(redirects, "legacy_unresolved.csv")):
        out.setdefault(r["old_slug"], ("unresolved", "unresolved"))
    return out


def cmd_urls():
    pre, live, acts, gsc = pre_cleanup(), live_slugs(), phase2_actions(), search_console()
    rows = {}

    def add(form, slug, source, expected):
        url = address(form, slug)
        if url in rows:
            if source not in rows[url]["source"]:
                rows[url]["source"] += "; " + source
            return
        clicks, impressions = gsc.get((form, slug), [0, 0])
        rows[url] = {"url": url, "slug": slug, "form": form, "source": source, "expected": expected,
                     "clicks_12m": clicks, "impressions_12m": impressions}

    removed = sorted(set(pre) - live)
    for slug in removed:
        a = acts[slug]
        expected = "410" if a["action"] == "retire" else f"301 {a['target']}"
        for form in ("admissions", "admission"):
            add(form, slug, f"removed ({a['checkpoint']})", expected)
    for slug, (source, expected) in sorted(expected_rules().items()):
        if slug in live:
            continue
        for form in ("admission", "admissions"):
            add(form, slug, source, expected)
    for (form, slug), (clicks, impressions) in sorted(gsc.items()):
        if slug not in live:
            add(form, slug, "search console", "")
    # A few live colleges' old /admission/ addresses, which WordPress's exact-slug guess should send on in one hop
    for slug in ("harvard", "maharishi-university-of-management", "el-centro-college", "uc-berkeley"):
        add("admission", slug, "live college, old form", f"301 {address('admissions', slug)}")

    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "urls.tsv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, URL_COLS, delimiter="\t")
        w.writeheader()
        w.writerows(rows[u] for u in sorted(rows))
    pages = [r["url"] for r in read_csv(OTHER_CSV)]
    pages += [f"{SITE}/admissions/", f"{SITE}/admissions/harvard/", f"{SITE}/admissions/el-centro-college/"]
    with open(os.path.join(OUT, "pages.txt"), "w", encoding="utf-8") as f:
        f.write("# Pages whose links into /admission(s)/ the live check reads (scripts/admissions/cleanup_qa.py urls)\n")
        f.write("\n".join(dict.fromkeys(pages)) + "\n")
    print(f"{len(removed)} removed college pages; {len(rows)} addresses to check "
          f"({Counter(r['source'].split(';')[0].split(' (')[0] for r in rows.values()).most_common()}); "
          f"{len(set(pages))} pages to read for links")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "urls":
        cmd_urls()
    else:
        sys.exit(__doc__)
