#!/usr/bin/env python3
"""Check that a page's "On this page" list, its H2s and Rank Math's SiteNavigationElement schema agree.

    python3 scripts/qa/check_toc_schema.py [URL ...]

With no URL it checks every published page whose saved content contains the collapsed list (class gpa-toc),
found live over SSH with WP-CLI, so the list can't drift between branches; plus any extra URLs in
scripts/qa/toc-pages.txt (pages whose list comes from a template, not saved content). If the server can't be
reached it falls back to toc-pages.txt alone and says so.

For each page, from the live HTML (cache-busted):
  - every TOC link (.wp-block-rank-math-toc-block a[href^="#"]) points to an element with that id, and that
    element is an H2 whose text equals the link text, word for word;
  - the SiteNavigationElement names in the JSON-LD equal the TOC link texts, same count and order;
  - the TOC links are in the HTML (3 or more: the list only shows on pages with 3+ sections) and the page has exactly one FAQPage when it has any.
Exit code 1 if any page fails. deploy_theme.sh runs it after every deploy.
"""
import html, json, os, re, subprocess, sys, time, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
SSH = ["ssh", "-i", os.path.expanduser("~/.ssh/gpacalculator_cloudways"), "-o", "IdentitiesOnly=yes",
       "-o", "ConnectTimeout=15", "master_rfzfmbbwze@67.205.161.226"]
LIVE_PAGES = ("cd applications/xwnzegvpyy/public_html && wp eval 'global $wpdb; foreach ($wpdb->get_col("
              "\"SELECT ID FROM {$wpdb->posts} WHERE post_status = \\\"publish\\\" "
              "AND post_content LIKE \\\"%gpa-toc%\\\"\") as $id) echo get_permalink($id), PHP_EOL;'")


def live_pages():
    """Published pages whose saved content carries the collapsed "On this page" list."""
    try:
        out = subprocess.run(SSH + [LIVE_PAGES], capture_output=True, text=True, timeout=60)
    except (OSError, subprocess.TimeoutExpired):
        return None
    if out.returncode != 0:
        return None
    return [l.strip() for l in out.stdout.splitlines() if l.strip().startswith("http")]
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36"


def text(fragment):
    return re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", "", fragment))).strip()


def walk(node, out):
    if isinstance(node, dict):
        types = node.get("@type")
        types = types if isinstance(types, list) else [types]
        if "SiteNavigationElement" in types:
            names = node.get("name")
            out["nav"].extend(names if isinstance(names, list) else [names])
        if "FAQPage" in types:
            out["faq"] += 1
        for v in node.values():
            walk(v, out)
    elif isinstance(node, list):
        for v in node:
            walk(v, out)


def check(url):
    sep = "&" if "?" in url else "?"
    req = urllib.request.Request(f"{url}{sep}qa={int(time.time())}", headers={"User-Agent": UA})
    page = urllib.request.urlopen(req, timeout=30).read().decode("utf-8", "replace")
    problems = []

    toc = re.search(r'<div[^>]*class="[^"]*wp-block-rank-math-toc-block[^"]*"[^>]*>(.*?)</nav>', page, re.S)
    links = re.findall(r'<a[^>]*href="#([^"]+)"[^>]*>(.*?)</a>', toc.group(1), re.S) if toc else []
    if not links:
        problems.append("no TOC links in the HTML")
    elif len(links) < 3:
        problems.append(f"only {len(links)} TOC links: the list is shown on pages with 3+ sections only")
    for target, label in links:
        label = text(label)
        el = re.search(r'<(h[1-6])\b[^>]*\bid="%s"[^>]*>(.*?)</\1>' % re.escape(target), page, re.S)
        if not el:
            problems.append(f'#{target}: no heading with this id ("{label}")')
        elif el.group(1) != "h2":
            problems.append(f"#{target}: is an {el.group(1)}, not an h2")
        elif text(el.group(2)) != label:
            problems.append(f'#{target}: link "{label}" != H2 "{text(el.group(2))}"')

    found = {"nav": [], "faq": 0}
    for block in re.findall(r'<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>', page, re.S):
        try:
            walk(json.loads(block), found)
        except ValueError:
            problems.append("a JSON-LD block does not parse")
    nav = [text(str(n)) for n in found["nav"]]
    labels = [text(l) for _, l in links]
    if nav != labels:
        problems.append(f"schema SiteNavigationElement names ({len(nav)}) != TOC links ({len(labels)}): {nav}")
    if found["faq"] > 1:
        problems.append(f"{found['faq']} FAQPage blocks (expected one)")

    status = "PASS" if not problems else "FAIL"
    print(f"{status} {url}  ({len(labels)} TOC links, {len(nav)} schema names, {found['faq']} FAQPage)")
    for p in problems:
        print("   -", p)
    return not problems


def main():
    urls = sys.argv[1:]
    if not urls:
        with open(os.path.join(HERE, "toc-pages.txt")) as f:
            extra = [l.split("#")[0].strip() for l in f if l.split("#")[0].strip()]
        found = live_pages()
        if found is None:
            print("(server not reachable: checking scripts/qa/toc-pages.txt only)")
            found = []
        urls = list(dict.fromkeys(found + extra))
    ok = all([check(u) for u in urls])
    sys.exit(0 if ok else 1)


if __name__ == "__main__":
    main()
