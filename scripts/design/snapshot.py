#!/usr/bin/env python3
"""Save live pages plus the site files they load, so design previews can be rendered away from the live site.

    python3 scripts/design/snapshot.py OUTDIR URL [URL ...]

Writes OUTDIR/pages/<slug>.html (the HTML a logged-out visitor gets) and OUTDIR/site/<path> for every
gpacalculator.net file the pages reference (stylesheets, scripts, images, fonts, and url()s inside the
stylesheets). Query strings are dropped from saved file names. Images over 1.5 MB are skipped.
Standard library only; read-only against the site.
"""
import json, os, re, sys, time, urllib.parse, urllib.request

HOST = "gpacalculator.net"
UA = ("Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) "
      "Chrome/129.0 Safari/537.36")
MAX_BYTES = 1_500_000


def get(url):
    req = urllib.request.Request(url, headers={"User-Agent": UA, "Accept-Encoding": "identity"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return r.read(), r.headers.get("Content-Type", "")


def local_path(out, url):
    path = urllib.parse.urlsplit(url).path
    if path.endswith("/"):
        path += "index.html"
    return os.path.join(out, "site", path.lstrip("/"))


def refs_in_html(html, base):
    found = set()
    for m in re.finditer(r'''(?:href|src|data-src|content)=["']([^"']+)["']''', html):
        found.add(m.group(1))
    for m in re.finditer(r'''(?:srcset|data-srcset)=["']([^"']+)["']''', html):
        for part in m.group(1).split(","):
            if part.strip():
                found.add(part.strip().split()[0])
    for m in re.finditer(r"url\(\s*['\"]?([^'\")]+)['\"]?\s*\)", html):
        found.add(m.group(1))
    out = set()
    for f in found:
        f = f.replace("&#038;", "&").replace("&amp;", "&")
        u = urllib.parse.urljoin(base, f)
        s = urllib.parse.urlsplit(u)
        if s.netloc.endswith(HOST) and re.search(r"\.(css|js|png|jpe?g|webp|gif|svg|ico|woff2?|ttf|otf|avif)$", s.path, re.I):
            out.add(urllib.parse.urlunsplit((s.scheme, s.netloc, s.path, s.query, "")))
    return out


def main():
    out, urls = sys.argv[1], sys.argv[2:]
    if not urls:
        sys.exit(__doc__)
    os.makedirs(os.path.join(out, "pages"), exist_ok=True)
    queue, seen, manifest = set(), set(), {"taken_utc": time.strftime("%Y-%m-%d %H:%M", time.gmtime()), "pages": {}, "skipped": []}
    for url in urls:
        body, _ = get(url)
        html = body.decode("utf-8", "replace")
        slug = urllib.parse.urlsplit(url).path.strip("/").replace("/", "__") or "home"
        with open(os.path.join(out, "pages", slug + ".html"), "w", encoding="utf-8") as f:
            f.write(html)
        manifest["pages"][slug] = url
        queue |= refs_in_html(html, url)
        print(f"page {url} -> {slug}.html ({len(body)} bytes)")
    while queue:
        u = queue.pop()
        key = urllib.parse.urlsplit(u).path
        if key in seen:
            continue
        seen.add(key)
        try:
            body, ctype = get(u)
        except Exception as e:  # noqa: BLE001 - keep going, record it
            manifest["skipped"].append(f"{u} ({e})")
            continue
        if len(body) > MAX_BYTES and not key.endswith((".css", ".js")):
            manifest["skipped"].append(f"{u} (too big: {len(body)})")
            continue
        p = local_path(out, u)
        os.makedirs(os.path.dirname(p), exist_ok=True)
        with open(p, "wb") as f:
            f.write(body)
        if key.endswith(".css"):
            queue |= refs_in_html(body.decode("utf-8", "replace"), u)
    manifest["files"] = len(seen) - len(manifest["skipped"])
    with open(os.path.join(out, "manifest.json"), "w") as f:
        json.dump(manifest, f, indent=1)
    print(f"{manifest['files']} files saved, {len(manifest['skipped'])} skipped -> {out}")


if __name__ == "__main__":
    main()
