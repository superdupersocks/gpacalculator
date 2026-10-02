"""Find the college's own web page that links to its Common Data Set (CDS) file when the file sits elsewhere.

    python3 scripts/admissions/cds_pages.py [--sources CSV] [--institutions CSV] [--out CSV] [--max-pages 40]

Digant's rule (2026-10-02): a CDS file on Google Drive, Sheets, SharePoint or another host counts only when the
college's own website links to it, and the value then cites that page. For each college in cds_sources.csv whose
file isn't on its own website, this reads a few pages of that website (its sitemaps, home page and the usual
institutional-research addresses, then links that mention the Common Data Set or institutional research, at most
--max-pages) and looks for the file:

- a Google file ID (Drive or Sheets) or the file's own path on a CDN, written in the page; or
- a link to that year's CDS whose download has the same SHA-256 as the copy cds.py read (the archive copy is named
  by its hash), for hosts whose download links don't name the file (Box).

Writes data/admissions/cds_pages.csv, one row per college found: the page, the link on it and how it matched.
The run log lists the colleges not found with the pages read. Honors robots.txt.
"""
import argparse
import csv
import gzip
import hashlib
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser
from concurrent.futures import ThreadPoolExecutor
from datetime import date
from html.parser import HTMLParser
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from cds import elsewhere, sites as college_sites  # noqa: E402
from common import OUT, write_csv  # noqa: E402
from fetch import HEADERS  # noqa: E402

COLUMNS = ["unitid", "name", "cds_year", "file_url", "page_url", "link_url", "match", "checked_on"]
# Where colleges keep the CDS: tried on the college's own site after its sitemaps and home page.
PATHS = ["institutional-research/common-data-set", "institutional-research", "common-data-set", "cds", "ir", "oir",
         "irp", "oira", "about/facts", "about/institutional-research", "offices/institutional-research",
         "institutional-effectiveness", "provost/institutional-research", "facts", "about/facts-and-figures"]
SUBDOMAINS = ["ir", "oir", "irp", "oira", "ira", "irap", "ire", "oirp", "opir", "iea", "ie", "institutionalresearch",
              "data", "factbook"]
CDS_WORDS = re.compile(r"common[\s_-]*data[\s_-]*set|\bcds\b|/cds[/_-]", re.I)
IR_WORDS = re.compile(r"institutional[\s_-]*(research|effectiveness|analytics|data|planning)|\b(oir|irp|oira|ir)\b|"
                      r"fact[\s_-]*book|facts|/data\b", re.I)
FILE_LINK = re.compile(r"\.(pdf|xlsx?|docx?)(\?|$)|drive\.google|docs\.google|box\.com|sharepoint|1drv", re.I)
GOOGLE_ID = re.compile(r"(?:[?&]id=|/d/|/\*/)([\w-]{25,})")


class Links(HTMLParser):
    """The <a href> links of a page with their text."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.links, self._href, self._text = [], None, []

    def handle_starttag(self, tag, attrs):
        if tag == "a":
            self._href, self._text = dict(attrs).get("href"), []

    def handle_data(self, data):
        if self._href is not None:
            self._text.append(data)

    def handle_endtag(self, tag):
        if tag == "a" and self._href is not None:
            self.links.append((self._href, " ".join("".join(self._text).split())))
            self._href = None


def links(html, base):
    p = Links()
    try:
        p.feed(html)
    except Exception:
        pass
    out = []
    for href, text in p.links:
        href = (href or "").strip()
        if href and not href.startswith(("mailto:", "tel:", "javascript:", "#")):
            out.append((urllib.parse.urljoin(base, href).split("#")[0], text))
    return out


def host(url):
    return (urllib.parse.urlparse(url if "://" in url else "https://" + url).hostname or "").lower()


def domain(h):
    """The registrable part of a host: "oir.harvard.edu" -> "harvard.edu", "baruch.cuny.edu" -> "cuny.edu"."""
    return ".".join(h.removeprefix("www.").split(".")[-2:])


def on_site(url, dom):
    h = host(url)
    return h == dom or h.endswith("." + dom)


def tokens(src):
    """What identifies the file in a page that links it: its Google ID, else its path on the CDN (the last two
    segments, with and without URL encoding); plus the SHA-256 that names the archive copy."""
    url = src["source_url"]
    out = {"google": "", "paths": [], "sha256": ""}
    if "google" in host(url):
        m = GOOGLE_ID.search(url)
        out["google"] = m.group(1) if m else ""
    elif not re.search(r"box(cloud)?\.com", host(url)):
        segs = [s for s in urllib.parse.urlparse(url).path.split("/") if s]
        if segs and re.search(r"\.(pdf|xlsx?|docx?)$", segs[-1], re.I):
            tail = "/".join(segs[-2:])
            out["paths"] = sorted({tail, urllib.parse.unquote(tail), urllib.parse.quote(urllib.parse.unquote(tail))})
    name = (src.get("archive_url") or "").rsplit("/", 1)[-1].split(".")[0]
    out["sha256"] = name if re.fullmatch(r"[0-9a-f]{64}", name) else ""
    return out


def found_in(html, page_links, tok):
    """(link, how) when the page names the file, else None."""
    if tok["google"] and tok["google"] in html:
        link = next((u for u, _ in page_links if tok["google"] in u), "")
        return link, "Google file ID"
    for path in tok["paths"]:
        if path in html:
            link = next((u for u, _ in page_links if path in u or path in urllib.parse.unquote(u)), "")
            return link, "file path"
    return None


def year_forms(cds_year):
    """"2024-25" -> the ways a page writes that year: 2024-25, 2024-2025, 2024–25, 2024_2025, 2024 - 2025..."""
    a, b = cds_year.split("-")
    full = str(int(a) + 1)
    return [f"{a}{sep}{end}" for sep in ("-", "–", "_", " - ", " – ", "/", " to ") for end in (b, full)]


def cds_links(page_links, cds_year):
    """Links to this year's CDS file on a page: text or address names the CDS and the year, and it looks like a
    file (or a Drive, Box or SharePoint link)."""
    forms = [f.lower() for f in year_forms(cds_year)]
    out = []
    for u, text in page_links:
        both = f"{text} {urllib.parse.unquote(u)}".lower()
        if FILE_LINK.search(u) and any(f in both for f in forms) and (CDS_WORDS.search(both) or "cds" in both):
            out.append(u)
    return out


def download_url(u):
    """A direct download for a sharing link: Box "/s/<id>" -> "/shared/static/<id>"; Drive "/file/d/<id>" -> uc."""
    m = re.match(r"(https://[\w.-]*box\.com)/s/(\w+)", u)
    if m:
        return f"{m.group(1)}/shared/static/{m.group(2)}"
    m = re.search(r"drive\.google\.com/file/d/([\w-]{25,})", u)
    if m:
        return f"https://drive.google.com/uc?export=download&id={m.group(1)}"
    return u


class Crawler:
    def __init__(self, max_pages):
        self.max_pages, self.robots, self.last = max_pages, {}, {}

    def allowed(self, url):
        h = host(url)
        if h not in self.robots:
            rp = urllib.robotparser.RobotFileParser()
            try:
                body = self.get(f"https://{h}/robots.txt", check=False, limit=500_000)
                rp.parse(body.decode("utf-8", "ignore").splitlines() if body else [])
            except Exception:
                rp.parse([])
            self.robots[h] = rp
        return self.robots[h].can_fetch(HEADERS["User-Agent"], url)

    def get(self, url, check=True, limit=8_000_000):
        if check and not self.allowed(url):
            return None
        h = host(url)
        wait = 0.5 - (time.time() - self.last.get(h, 0))
        if wait > 0:
            time.sleep(wait)
        self.last[h] = time.time()
        req = urllib.request.Request(url, headers={k: v for k, v in HEADERS.items() if k != "Referer"})
        try:
            with urllib.request.urlopen(req, timeout=20) as r:
                data = r.read(limit + 1)
        except (urllib.error.URLError, TimeoutError, ConnectionError, ValueError, OSError):
            return None
        if len(data) > limit:
            return None
        return gzip.decompress(data) if data[:2] == b"\x1f\x8b" else data

    def sitemap_pages(self, dom):
        """Pages from the site's sitemaps whose address mentions the CDS or institutional research."""
        found, todo, seen = [], [f"https://www.{dom}/sitemap.xml", f"https://{dom}/sitemap.xml",
                                  f"https://www.{dom}/sitemap_index.xml", f"https://www.{dom}/wp-sitemap.xml"], set()
        while todo and len(seen) < 12:
            url = todo.pop(0)
            if url in seen:
                continue
            seen.add(url)
            body = self.get(url, limit=20_000_000)
            if not body:
                continue
            for loc in re.findall(r"<loc>\s*([^<\s]+)\s*</loc>", body.decode("utf-8", "ignore")):
                loc = loc.replace("&amp;", "&")
                if re.search(r"sitemap[^/]*\.xml", loc) and on_site(loc, dom):
                    if re.search(r"page|post|default|sitemap\.xml$|-\d+\.xml", loc):
                        todo.append(loc)
                elif on_site(loc, dom) and (CDS_WORDS.search(loc) or IR_WORDS.search(loc)):
                    found.append(loc)
        return sorted(found, key=lambda u: (not CDS_WORDS.search(u), len(u)))[:15]

    def find(self, src, website):
        """(row or None, pages read) for one college."""
        dom = domain(host(website))
        tok = tokens(src)
        # Lower first: sitemap pages naming the CDS or institutional research, the home page and links naming the
        # CDS, then the usual addresses and links naming institutional research, then likely subdomains.
        queue = [(0, u) for u in self.sitemap_pages(dom)]
        queue += [(1, f"https://www.{dom}/"), (1, website if "://" in website else f"https://{website}")]
        queue += [(2, f"https://www.{dom}/{p}/") for p in PATHS] + [(3, f"https://{s}.{dom}/") for s in SUBDOMAINS]
        seen, read, tried = set(), 0, set()
        while queue and read < self.max_pages:
            queue.sort(key=lambda t: t[0])
            score, url = queue.pop(0)
            if url in seen or not on_site(url, dom):
                continue
            seen.add(url)
            body = self.get(url)
            if not body or b"<" not in body[:2000]:
                continue
            read += 1
            html = body.decode("utf-8", "ignore")
            page_links = links(html, url)
            hit = found_in(html, page_links, tok)
            if hit:
                return self.row(src, url, hit[0], hit[1]), read
            if tok["sha256"]:
                for u in cds_links(page_links, src["cds_year"])[:4]:
                    if u in tried:
                        continue
                    tried.add(u)
                    data = self.get(download_url(u), check=False, limit=30_000_000)
                    if data and hashlib.sha256(data).hexdigest() == tok["sha256"]:
                        return self.row(src, url, u, "same file (SHA-256)"), read
            for u, text in page_links:
                if u in seen or not on_site(u, dom) or FILE_LINK.search(u):
                    continue
                both = f"{text} {u}"
                if CDS_WORDS.search(both):
                    queue.append((1, u))
                elif IR_WORDS.search(both) and score <= 2:
                    queue.append((2, u))
        return None, read

    @staticmethod
    def row(src, page, link, how):
        return {"unitid": src["unitid"], "name": src["name"], "cds_year": src["cds_year"],
                "file_url": src["source_url"], "page_url": page, "link_url": link, "match": how,
                "checked_on": date.today().isoformat()}


def needs_page(src, website):
    """True when the file isn't on the college's own website."""
    return bool(website) and not on_site(src["source_url"], domain(host(website))) \
        and not host(src["source_url"]).endswith("commondataset.org")


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", default=str(OUT / "cds_sources.csv"))
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--out", default=str(OUT / "cds_pages.csv"))
    ap.add_argument("--max-pages", type=int, default=40)
    ap.add_argument("--workers", type=int, default=8)
    a = ap.parse_args(argv)
    inst = {r["unitid"]: r for r in csv.DictReader(open(a.institutions, encoding="utf-8"))}
    sites = {u: r["website"] for u, r in inst.items()}
    by_site = college_sites(inst)
    # Files on another college's website are that college's (cds.py doesn't use them), so no page can make them count
    todo = [s for s in csv.DictReader(open(a.sources, encoding="utf-8"))
            if needs_page(s, sites.get(s["unitid"], "")) and not elsewhere(s, inst, by_site)]
    crawler = Crawler(a.max_pages)
    rows, missing = [], []
    with ThreadPoolExecutor(a.workers) as pool:
        for src, (row, read) in zip(todo, pool.map(lambda s: crawler.find(s, sites[s["unitid"]]), todo)):
            if row:
                rows.append(row)
            else:
                missing.append(f"{src['name']} ({src['cds_year']}, {host(src['source_url'])}): {read} pages read")
    rows.sort(key=lambda r: r["name"])
    write_csv(Path(a.out), rows, COLUMNS)
    print(f"{len(todo)} colleges whose CDS file is off their website: page found for {len(rows)}, "
          f"not found for {len(missing)}")
    if missing:
        print("Not found:\n  " + "\n  ".join(missing))


if __name__ == "__main__":
    main()
