#!/usr/bin/env python3
"""Record where URLs on the live site land: the first response (status, Location, who redirected) and the final page.

    python scripts/check_urls.py <in.tsv> <out.tsv>

<in.tsv> has a header row; its first column is the URL, and an optional `same_college_today` column names the page
the URL should end on. Read-only: plain GET requests, one at a time with a pause between them, since the site answers
429 to bursts. Run by .github/workflows/admissions-urls.yml, since the cloud sessions can't reach the site.
"""
import csv
import sys
import time
from collections import Counter
from urllib.parse import urljoin

import requests

UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/128.0 Safari/537.36 gpacalculator-url-check")
PAUSE = 1.0  # seconds between requests
COLS = ["old_url", "status", "location", "redirect_by", "final_url", "final_status", "hops", "same_college_today",
        "lands_there"]


def norm(url):
    return url.split("#")[0].split("?")[0].rstrip("/").lower()


def get(session, url):
    """One request without following redirects; waits and retries when the site answers 429."""
    for attempt in range(5):
        time.sleep(PAUSE)
        r = session.get(url, allow_redirects=False, timeout=30)
        if r.status_code != 429:
            return r
        time.sleep(float(r.headers.get("Retry-After") or 0) or 15 * (attempt + 1))
    return r


def check(session, row):
    url, want = row[0], row[1] if len(row) > 1 else ""
    out = {"old_url": url, "same_college_today": want}
    try:
        r = get(session, url)
        out.update(status=r.status_code, location=r.headers.get("Location", ""),
                   redirect_by=r.headers.get("X-Redirect-By", ""))
        hops, here = 0, url
        while r.status_code in (301, 302, 307, 308) and hops < 5:
            here = urljoin(here, r.headers.get("Location", ""))
            r = get(session, here)
            hops += 1
        out.update(final_url=here, final_status=r.status_code, hops=hops)
    except requests.RequestException as e:
        out.update(status="error", location=str(e)[:200])
    if want and out.get("final_url"):
        out["lands_there"] = "yes" if norm(out["final_url"]) == norm(want) and out["final_status"] == 200 else "no"
    return out


def main(src, dst):
    with open(src, newline="") as f:
        rows = [r for r in csv.reader(f, delimiter="\t")][1:]
    session = requests.Session()
    session.headers["User-Agent"] = UA
    results = [check(session, row) for row in rows]
    with open(dst, "w", newline="") as f:
        w = csv.DictWriter(f, COLS, delimiter="\t", extrasaction="ignore")
        w.writeheader()
        w.writerows(results)

    def kind(o):
        if o.get("status") == "error":
            return "error"
        if o.get("final_status") == 404:
            return "ends in 404"
        if o.get("final_status") == 200 and "/admissions/" in (o.get("final_url") or "").rstrip("/") + "/" \
                and norm(o["final_url"]) != norm("https://gpacalculator.net/admissions"):
            return "ends on a college page"
        return f"ends elsewhere ({o.get('final_status')})"

    print(f"{len(results)} URLs")
    for k, n in Counter(kind(o) for o in results).most_common():
        print(f"  {k}: {n}")
    for k, n in Counter((o.get("status"), o.get("redirect_by")) for o in results).most_common():
        print(f"  first response {k[0]} {k[1] or '(no X-Redirect-By)'}: {n}")
    named = [o for o in results if o["same_college_today"]]
    landed = sum(o.get("lands_there") == "yes" for o in named)
    print(f"  with a known page today: {len(named)}, landing on it: {landed}")


if __name__ == "__main__":
    main(*sys.argv[1:3])
