#!/usr/bin/env python3
"""Phase 4 audit: what the live site gives search engines for /admissions/. Read-only GETs.

    python3 scripts/admissions/indexing_check.py data/admissions/live_checks/indexing_urls.txt data/admissions/live_checks

Writes three files to the output folder:
- indexing.md: robots.txt; the sitemap index and every sitemap it lists (status, how many addresses, the lastmod
  range, images, and the response headers that decide caching and indexing: X-Robots-Tag, Cache-Control,
  cf-cache-status); how many addresses each first path segment has; then each address in the URL list, requested once
  without following redirects (status, Location, X-Robots-Tag, meta robots, canonical, rel next/prev, title, H1 and
  the JSON-LD types).
- sitemap_colleges.csv: every address the colleges sitemaps list, with its lastmod and how many images it lists.
- sitemap_other.csv: every address the other sitemaps list, with its sitemap and lastmod.

Runs on GitHub (admissions-indexing.yml) because the cloud sessions can't reach the site. Changes nothing on the site.
"""
import csv
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request
from collections import Counter

SITE = "https://gpacalculator.net"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36"
HEADERS = ("content-type", "x-robots-tag", "cache-control", "cf-cache-status", "x-redirect-by")
LOC = re.compile(r"<loc>\s*([^<\s]+)\s*</loc>")
URL_BLOCK = re.compile(r"<(url|sitemap)>(.*?)</\1>", re.S)
LASTMOD = re.compile(r"<lastmod>\s*([^<\s]+)\s*</lastmod>")
IMAGE = re.compile(r"<image:loc>")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


OPEN = urllib.request.build_opener(NoRedirect)


def fetch(url):
    """One GET, redirects not followed: (status, headers, body). Status 0 means the request failed."""
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "text/html,application/xml,*/*;q=0.8"})
    err = ""
    for attempt in range(3):
        try:
            with OPEN.open(req, timeout=30) as r:
                return r.status, r.headers, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.headers, e.read().decode("utf-8", "replace")
        except Exception as e:  # network trouble: try again, then report it
            err = f"{type(e).__name__}: {e}"
            time.sleep(2 * (attempt + 1))
    return 0, {}, err


def head_line(headers):
    return ", ".join(f"{h}: {headers.get(h)}" for h in HEADERS if headers and headers.get(h))


def entries(body):
    """(address, lastmod, images) for each <url> or <sitemap> entry."""
    out = []
    for _, block in URL_BLOCK.findall(body):
        loc = LOC.search(block)
        if loc:
            mod = LASTMOD.search(block)
            out.append((loc.group(1), mod.group(1) if mod else "", len(IMAGE.findall(block))))
    return out


def segment(url):
    path = re.sub(r"^https?://[^/]+", "", url)
    first = path.strip("/").split("/")[0]
    return "/" if not first else f"/{first}/"


def sitemaps(out_dir):
    lines = ["## robots.txt", ""]
    status, headers, body = fetch(SITE + "/robots.txt")
    lines.append(f"- status {status}; {head_line(headers)}")
    lines += ["", "```", body.strip()[:3000], "```", ""]

    status, headers, body = fetch(SITE + "/sitemap_index.xml")
    index = entries(body)
    lines += ["## Sitemap index", "", f"- /sitemap_index.xml: status {status}, {len(index)} sitemaps; {head_line(headers)}"]
    colleges, other, segments = [], [], Counter()
    for url, mod, _ in index:
        time.sleep(1)
        st, hd, b = fetch(url)
        rows = entries(b)
        name = url.rsplit("/", 1)[-1]
        mods = sorted(m for _, m, _ in rows if m)
        images = sum(n for _, _, n in rows)
        repeats = sum(1 for _, n in Counter(u for u, _, _ in rows).items() if n > 1)
        foreign = sum(1 for u, _, _ in rows if not u.startswith(SITE + "/"))
        lines.append(f"  - {name} (index lastmod {mod or 'none'}): status {st}, {len(rows)} addresses, {images} images"
                     + (f", lastmod {mods[0][:10]} to {mods[-1][:10]}" if mods else ", no lastmod")
                     + (f", {repeats} repeated" if repeats else "") + (f", {foreign} off-site" if foreign else "")
                     + (f"; {head_line(hd)}" if head_line(hd) else ""))
        for u, m, n in rows:
            segments[segment(u)] += 1
            if re.search(r"/colleges-sitemap\d*\.xml$", url):
                colleges.append((u, m, n))
            else:
                other.append((name, u, m))
    total = len(colleges) + len(other)
    lines += ["", f"- {total} addresses in all, {len(colleges)} in the colleges sitemaps", "",
              "Addresses by first path segment:", ""]
    lines += [f"- {seg}: {n}" for seg, n in segments.most_common()]
    lines.append("")
    with open(os.path.join(out_dir, "sitemap_colleges.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["url", "lastmod", "images"])
        w.writerows(sorted(colleges))
    with open(os.path.join(out_dir, "sitemap_other.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["sitemap", "url", "lastmod"])
        w.writerows(other)
    return lines


def tag_attr(body, pattern):
    m = re.search(pattern, body, re.I | re.S)
    return " ".join(m.group(1).split()) if m else ""


def ld_types(body):
    types = []

    def walk(node):
        if isinstance(node, dict):
            t = node.get("@type")
            if t:
                types.extend(t if isinstance(t, list) else [t])
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    bad = 0
    for block in re.findall(r'<script[^>]*application/ld\+json[^>]*>(.*?)</script>', body, re.S | re.I):
        try:
            walk(json.loads(block))
        except Exception:
            bad += 1
    shown = Counter(t for t in types if t not in ("ListItem", "Question", "Answer"))
    return ", ".join(f"{t} x{n}" if n > 1 else t for t, n in shown.items()) + (f"; {bad} don't parse" if bad else "")


def check(path):
    url = path if path.startswith("http") else SITE + path
    status, headers, body = fetch(url)
    out = [f"- {path}: status {status}"]
    loc = headers.get("location") if headers else None
    if loc:
        out[0] += f" -> {loc}"
    if head_line(headers):
        out.append(f"  - headers: {head_line(headers)}")
    if status == 200 and "html" in (headers.get("content-type") or ""):
        robots = tag_attr(body, r'<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)')
        canonical = tag_attr(body, r'<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)')
        nxt = tag_attr(body, r'<link\s+rel=["\']next["\']\s+href=["\']([^"\']*)')
        prev = tag_attr(body, r'<link\s+rel=["\']prev["\']\s+href=["\']([^"\']*)')
        title = tag_attr(body, r"<title[^>]*>(.*?)</title>")
        h1 = re.sub(r"<[^>]+>", "", tag_attr(body, r"<h1[^>]*>(.*?)</h1>"))
        cards = len(re.findall(r'class="db-college-card gpa-hub-row"', body))
        first = tag_attr(body, r'class="db-college-card gpa-hub-row".*?<a[^>]*>(.*?)</a>')
        out += [f"  - robots: {robots or 'none'}; canonical: {canonical or 'none'}"
                + (f"; next: {nxt}" if nxt else "") + (f"; prev: {prev}" if prev else ""),
                f"  - title: {title}", f"  - H1: {h1}", f"  - JSON-LD: {ld_types(body) or 'none'}"]
        if cards:
            out.append(f"  - college cards: {cards}, first: {re.sub(r'<[^>]+>', '', first)}")
    elif status not in (301, 302, 307, 308) and body and "xml" in (headers.get("content-type") or ""):
        out.append(f"  - {len(LOC.findall(body))} <loc> entries")
    return out


def main(src, out_dir):
    paths = [ln.strip() for ln in open(src, encoding="utf-8") if ln.strip() and not ln.startswith("#")]
    stamp = time.strftime("%Y-%m-%d %H:%M UTC", time.gmtime())
    lines = [f"# Indexing check, {stamp}", "",
             f"Read-only GETs by scripts/admissions/indexing_check.py; addresses from `{src}`.", ""]
    lines += sitemaps(out_dir)
    lines += ["## Addresses, redirects not followed", ""]
    for path in paths:
        lines += check(path)
        time.sleep(1)
    with open(os.path.join(out_dir, "indexing.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")
    print("\n".join(lines))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
