#!/usr/bin/env python3
"""Reads pages of the live site and writes what a reviewer checks after a change, one section per page.

    python3 scripts/page_check.py data/admissions/live_checks/pages.txt data/admissions/live_checks/report.md

Each line of the list is a path or URL on gpacalculator.net (blank lines and # comments are skipped). For each page
the report gives the HTTP status and where redirects ended, the <title>, meta description, og:title and H1, the
structured data (whether each JSON-LD block parses, its types, its FAQPage questions and whether each question is on
the page), the average-GPA and acceptance-rate cards as shown, and flags for what the admissions cleanup removes
(collegesimply images, "GPA Requirements", "Admission Standards", "Applicant Competition", "What GPA do I need") and
for what must stay (Freestar ad tags). A line "sitemap <index path> <post type>" (for example
"sitemap /sitemap_index.xml colleges") instead reads every page of that post type's sitemap and reports how many
addresses each page lists, the total, and any address listed more than once. Read-only GETs; runs on GitHub
(admissions-pages.yml) because the cloud sessions can't reach the site. Changes nothing on the site.
"""
import html
import json
import re
import sys
import time
import urllib.error
import urllib.request
from collections import Counter
from html.parser import HTMLParser

SITE = "https://gpacalculator.net"
UA = "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36"


class Page(HTMLParser):
    """The parts of a page the report shows."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title, self.h1, self.meta, self.ld, self.text = "", "", {}, [], []
        self._in, self._buf = None, []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "meta" and a.get("content") is not None:
            key = a.get("name") or a.get("property")
            if key:
                self.meta.setdefault(key.lower(), a["content"])
        elif tag == "title" and not self.title:
            self._in, self._buf = "title", []
        elif tag == "h1" and not self.h1:
            self._in, self._buf = "h1", []
        elif tag == "script" and (a.get("type") or "").lower() == "application/ld+json":
            self._in, self._buf = "ld", []
        elif tag in ("script", "style"):
            self._in, self._buf = "skip", []

    def handle_endtag(self, tag):
        if self._in == "title" and tag == "title":
            self.title, self._in = " ".join("".join(self._buf).split()), None
        elif self._in == "h1" and tag == "h1":
            self.h1, self._in = " ".join("".join(self._buf).split()), None
        elif self._in == "ld" and tag == "script":
            self.ld.append("".join(self._buf))
            self._in = None
        elif self._in == "skip" and tag in ("script", "style"):
            self._in = None

    def handle_data(self, data):
        if self._in:
            self._buf.append(data)
            if self._in == "h1":
                self.text.append(data)
        else:
            self.text.append(data)


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept": "text/html,*/*;q=0.8"})
    for attempt in range(3):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.status, r.geturl(), r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.geturl() or url, e.read().decode("utf-8", "replace")
        except Exception as e:  # network trouble: try again, then report it
            err = f"{type(e).__name__}: {e}"
            time.sleep(2 * (attempt + 1))
    return 0, url, err


def types_of(node, out):
    if isinstance(node, dict):
        t = node.get("@type")
        if t:
            out.extend(t if isinstance(t, list) else [t])
        for v in node.values():
            types_of(v, out)
    elif isinstance(node, list):
        for v in node:
            types_of(v, out)
    return out


def faq_questions(node, out):
    if isinstance(node, dict):
        if node.get("@type") == "FAQPage":
            for q in node.get("mainEntity") or []:
                if isinstance(q, dict) and q.get("name"):
                    out.append(q["name"])
        for v in node.values():
            faq_questions(v, out)
    elif isinstance(node, list):
        for v in node:
            faq_questions(v, out)
    return out


def near(text, label, chars=110):
    """The text around the first mention of a label on the page, any case (for a stat card: its value and note)."""
    i = text.lower().find(label.lower())
    return " ".join(text[max(0, i - 40):i + len(label) + chars].split()) if i >= 0 else ""


def check(path):
    url = path if path.startswith("http") else SITE + path
    status, final, body = fetch(url)
    p = Page()
    try:
        p.feed(body)
    except Exception:
        pass
    text = " ".join(" ".join(p.text).split())
    ld_ok, ld_bad, types, faqs, ld_gpa = 0, 0, [], [], False
    for block in p.ld:
        try:
            data = json.loads(block)
        except Exception:
            ld_bad += 1
            continue
        ld_ok += 1
        types_of(data, types)
        faq_questions(data, faqs)
        ld_gpa = ld_gpa or "Average GPA" in block
    heads = " | ".join([p.title, p.meta.get("description", ""), p.meta.get("og:title", ""), p.h1])
    out = [f"## {path}", "", f"- status {status}" + (f", ended at {final}" if final.rstrip('/') != url.rstrip('/') else ""),
           f"- title: {p.title}", f"- description: {p.meta.get('description', '')}",
           f"- og:title: {p.meta.get('og:title', '')}", f"- H1: {p.h1}",
           f"- JSON-LD: {ld_ok} parse, {ld_bad} don't; types: {', '.join(sorted(set(types)))}"
           + ("; mentions \"Average GPA\"" if ld_gpa else "")]
    for q in faqs:
        out.append(f"  - FAQ: {q}" + ("" if html.unescape(q) in text else "  [NOT ON PAGE]"))
    for label in ("Average high school GPA", "Average GPA", "Acceptance rate", "Average SAT", "Open Admission",
                  "Sources"):
        snippet = near(text, label)
        if snippet:
            out.append(f"- \"{label}\": {snippet}")
    flags = {
        "collegesimply": "collegesimply" in body,
        "GPA Requirements in title/description/H1": "GPA Requirements" in heads,
        "Admission Standards": "Admission Standards" in text,
        "Applicant Competition": "Applicant Competition" in text,
        "What GPA do I need": "What GPA do I need" in text,
        "Freestar tags": "freestar" in body.lower(),
        "College Navigator link": "nces.ed.gov/collegenavigator" in body,
    }
    out.append("- flags: " + ", ".join(f"{k} {'yes' if v else 'no'}" for k, v in flags.items()))
    return "\n".join(out) + "\n"


LOC = re.compile(r"<loc>\s*([^<\s]+)\s*</loc>")


def sitemap(spec):
    """Every address one post type's sitemap pages list, from the sitemap index: per page, in all, and repeats."""
    _, index, kind = spec.split()
    status, _, body = fetch(SITE + index)
    pages = [u for u in LOC.findall(body) if re.search(rf"/{re.escape(kind)}-sitemap\d*\.xml$", u)]
    out = [f"## {spec}", "", f"- index status {status}: {len(pages)} {kind} sitemap pages"]
    urls = []
    for u in pages:
        st, _, b = fetch(u)
        found = LOC.findall(b)
        urls += found
        out.append(f"  - {u.rsplit('/', 1)[-1]}: status {st}, {len(found)} addresses")
        time.sleep(1)
    repeats = sorted(u for u, n in Counter(urls).items() if n > 1)
    out.append(f"- {len(urls)} addresses, {len(set(urls))} different; listed more than once: {len(repeats)}")
    out += [f"  - {u}" for u in repeats[:20]]
    return "\n".join(out) + "\n"


def main(src, dest):
    paths = [ln.strip() for ln in open(src, encoding="utf-8") if ln.strip() and not ln.startswith("#")]
    stamp = time.strftime("%Y-%m-%d %H:%M UTC", time.gmtime())
    parts = [f"# Live page check, {stamp}\n\nFrom `{src}`, read-only GETs by scripts/page_check.py.\n"]
    for path in paths:
        parts.append(sitemap(path) if path.startswith("sitemap ") else check(path))
        time.sleep(1)
    with open(dest, "w", encoding="utf-8") as f:
        f.write("\n".join(parts))
    print("\n".join(parts))


if __name__ == "__main__":
    main(sys.argv[1], sys.argv[2])
