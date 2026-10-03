#!/usr/bin/env python3
"""Where each college's admissions page and website (data/admissions/step3/links.tsv) lead, read from GitHub.

    python scripts/admissions/step3_links_check.py <links.tsv> <shard> <shards> <out.tsv>

Every <shards>-th row of links.tsv, starting at row <shard>: one GET that follows redirects (at most 10), recording
the answer, where it ended and how many hops it took. Read-only: plain GETs, one at a time with a pause, no page is
read beyond its first bytes. Run by .github/workflows/admissions-links.yml (the cloud sessions can't reach the
colleges' sites); scripts/admissions/step3_links.py writes the input and picks each page's link from the results.
"""
import csv
import sys
import time

import requests

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/128.0 Safari/537.36 gpacalculator-link-check")
PAUSE = 0.5  # seconds between requests (each to a different college's site, mostly)
COLS = ["unitid", "kind", "url", "status", "final_url", "final_status", "hops", "error"]


def check(session, url):
    out = {"url": url}
    try:
        r = session.get(url, allow_redirects=True, timeout=(10, 25), stream=True)
        out.update(status=r.history[0].status_code if r.history else r.status_code, final_url=r.url,
                   final_status=r.status_code, hops=len(r.history))
        r.close()
    except requests.TooManyRedirects:
        out.update(error="too many redirects")
    except requests.RequestException as e:
        out.update(error=type(e).__name__ + ": " + str(e)[:160])
    return out


def main(src, shard, shards, dst):
    with open(src, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f, delimiter="\t"))[int(shard)::int(shards)]
    s = requests.Session()
    s.headers.update({"User-Agent": UA, "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
                      "Accept-Language": "en-US,en;q=0.9"})
    s.max_redirects = 10
    with open(dst, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, COLS, delimiter="\t", extrasaction="ignore", lineterminator="\n")
        w.writeheader()
        for i, row in enumerate(rows, 1):
            out = check(s, row["url"])
            out.update(unitid=row["unitid"], kind=row["kind"])
            w.writerow(out)
            time.sleep(PAUSE)
            if i % 100 == 0:
                f.flush()
                print(f"{i} of {len(rows)}", flush=True)
    print(f"shard {shard}: {len(rows)} addresses checked")


if __name__ == "__main__":
    if len(sys.argv) != 5:
        sys.exit(__doc__)
    main(*sys.argv[1:])
