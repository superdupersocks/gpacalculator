#!/usr/bin/env python3
"""Cleanup QA on the live site: where each removed or old /admission(s)/ address lands, and which links point at them.

    python scripts/admissions/cleanup_qa_check.py status <urls.tsv> <shard> <shards> <out.tsv>
    python scripts/admissions/cleanup_qa_check.py links <pages.txt> <sitemap_colleges.csv> <out.tsv>

status: every <shards>-th URL of urls.tsv (first column; a header row), starting at row <shard>: the first answer
without following redirects, then each hop until an answer that isn't a redirect (at most 6 hops), written as a chain
such as "301 Rank Math > /admissions/x/ | 410".
links: each page in pages.txt (one URL per line) read once; every link on it into /admission/ or /admissions/ with its
text and the part of the page it sits in (content, header, footer, sidebar, nav); then each linked address that isn't
a page in the colleges sitemap gets the same check as in status.

Read-only: plain GET requests, one at a time with a pause, since the site answers 429 to bursts. Run by
.github/workflows/admissions-cleanup-qa.yml (the cloud sessions can't reach the site); scripts/admissions/cleanup_qa.py
writes the inputs and reads the results.
"""
import csv
import sys
import time
from urllib.parse import urljoin, urlsplit

import requests

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/128.0 Safari/537.36 gpacalculator-cleanup-qa")
SITE = "https://gpacalculator.net"
PAUSE = 1.0  # seconds between requests
MAX_HOPS = 6
STATUS_COLS = ["url", "status", "location", "redirect_by", "chain", "final_url", "final_status", "hops", "error"]
LINK_COLS = ["page", "url", "text", "region", "link_class", "target"] + STATUS_COLS[1:]


def get(session, url):
    """One GET without following redirects; waits and retries when the site answers 429."""
    r = None
    for attempt in range(6):
        time.sleep(PAUSE)
        r = session.get(url, allow_redirects=False, timeout=30)
        if r.status_code != 429:
            return r
        time.sleep(float(r.headers.get("Retry-After") or 0) or 20 * (attempt + 1))
    return r


def short(url):
    return url[len(SITE):] if url.startswith(SITE) else url


def check(session, url):
    out = {"url": url}
    try:
        r = get(session, url)
        out.update(status=r.status_code, location=r.headers.get("Location", ""),
                   redirect_by=r.headers.get("X-Redirect-By", ""))
        chain, here, hops = [], url, 0
        while r.status_code in (301, 302, 307, 308) and hops < MAX_HOPS:
            by = r.headers.get("X-Redirect-By", "")
            here = urljoin(here, r.headers.get("Location", ""))
            chain.append(f"{r.status_code}{' ' + by if by else ''} > {short(here)}")
            r = get(session, here)
            hops += 1
        chain.append(str(r.status_code))
        out.update(chain=" | ".join(chain), final_url=here, final_status=r.status_code, hops=hops)
    except requests.RequestException as e:
        out.update(error=str(e)[:200])
    return out


def session():
    s = requests.Session()
    s.headers["User-Agent"] = UA
    return s


def status(src, shard, shards, dst):
    with open(src, newline="", encoding="utf-8") as f:
        urls = [row[0] for row in list(csv.reader(f, delimiter="\t"))[1:] if row]
    mine = urls[int(shard)::int(shards)]
    s = session()
    with open(dst, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, STATUS_COLS, delimiter="\t", extrasaction="ignore")
        w.writeheader()
        for i, url in enumerate(mine, 1):
            w.writerow(check(s, url))
            if i % 100 == 0:
                f.flush()
                print(f"{i} of {len(mine)}", flush=True)
    print(f"shard {shard}: {len(mine)} addresses checked")


def region(a):
    """The part of the page a link sits in, from its nearest landmark ancestor."""
    for p in a.parents:
        if p.name is None:
            continue
        cls = " ".join(p.get("class") or []) + " " + (p.get("id") or "")
        if p.name == "header" or "site-header" in cls or "masthead" in cls:
            return "header"
        if p.name == "footer" or "site-footer" in cls or "footer-widgets" in cls:
            return "footer"
        if p.name == "nav" or "navigation" in cls:
            return "nav"
        if p.name == "aside" or "sidebar" in cls or "widget-area" in cls:
            return "sidebar"
        if "entry-content" in cls or p.name == "article" or p.name == "main":
            return "content"
    return "other"


def links(pages_file, sitemap_csv, dst):
    from bs4 import BeautifulSoup

    with open(pages_file, encoding="utf-8") as f:
        pages = [line.strip() for line in f if line.strip() and not line.startswith("#")]
    with open(sitemap_csv, newline="", encoding="utf-8") as f:
        live = {row["url"].rstrip("/").lower() for row in csv.DictReader(f)}
    s = session()
    found = []
    for i, page in enumerate(pages, 1):
        try:
            r = s.get(page, timeout=30)
            time.sleep(PAUSE)
        except requests.RequestException as e:
            found.append({"page": page, "error": str(e)[:200]})
            continue
        if r.status_code != 200:
            found.append({"page": page, "error": f"page answered {r.status_code}"})
            continue
        soup = BeautifulSoup(r.text, "html.parser")
        for a in soup.find_all("a", href=True):
            url = urljoin(r.url, a["href"].strip()).split("#")[0]
            parts = urlsplit(url)
            if parts.netloc.lower().replace("www.", "") != "gpacalculator.net":
                continue
            if not (parts.path.startswith("/admission/") or parts.path.startswith("/admissions/")):
                continue
            found.append({"page": page, "url": url, "text": " ".join(a.get_text(" ").split())[:120],
                          "region": region(a), "link_class": " ".join(a.get("class") or [])})
        if i % 50 == 0:
            print(f"{i} of {len(pages)} pages read", flush=True)
    targets = {}
    for row in found:
        url = row.get("url")
        if not url:
            continue
        key = url.split("?")[0].rstrip("/").lower()
        if key in live and urlsplit(url).path.startswith("/admissions/"):
            row["target"] = "live"
            continue
        if url not in targets:
            targets[url] = check(s, url)
        row.update({k: v for k, v in targets[url].items() if k != "url"})
        row["target"] = "checked"
    with open(dst, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, LINK_COLS, delimiter="\t", extrasaction="ignore")
        w.writeheader()
        w.writerows(found)
    print(f"{len(pages)} pages read, {sum(1 for r in found if r.get('url'))} links into /admission(s)/, "
          f"{len(targets)} addresses outside the sitemap checked")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "status" and len(sys.argv) == 6:
        status(*sys.argv[2:6])
    elif cmd == "links" and len(sys.argv) == 5:
        links(*sys.argv[2:5])
    else:
        sys.exit(__doc__)
