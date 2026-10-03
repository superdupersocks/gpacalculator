"""Link each existing /admissions/ college post (data/colleges/) to its IPEDS UNITID.

    python3 scripts/admissions/match.py [--colleges DIR] [--institutions CSV] [--out DIR]

The posts carry no federal ID, only a title and "City, State". Matching runs in steps, stopping at the first hit:
1. exact: same cleaned name and state (one candidate)
2. alias: the post's name equals one of the college's IPEDS aliases, same state
3. renamed: same city, and the names agree once generic words (college, university, campus...) are dropped,
   or one name's distinctive words contain the other's (King College -> King University; one candidate only)
4. fuzzy: closest name in the same state (city match breaks ties); accepted at score >= 0.955 with a clear
   lead over the runner-up, otherwise sent to review with the top three candidates

Writes data/admissions/match.csv (every post, with method and score) and match_review.csv (posts that need a
person: low-confidence or no candidate, or two posts claiming the same college).
"""
import argparse
import csv
import html
import json
import os
import sys
from collections import defaultdict
from difflib import SequenceMatcher
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import COLLEGES, OUT, STATES, norm_name, write_csv  # noqa: E402

ACCEPT = 0.955
REVIEW = 0.75
LEAD = 0.03


def strip_campus(n):
    """'university of x main campus' -> 'university of x' (IPEDS appends campus names after a hyphen)."""
    for tail in (" main campus", " campus"):
        if n.endswith(tail):
            return n[: -len(tail)]
    return n


QUALIFIERS = {"branch", "north", "south", "east", "west", "area", "cuny", "suny", "campus"}
GENERIC = {"college", "university", "community", "district", "institute", "campus", "main", "school", "center", "inc"}


def core(n):
    return frozenset(w for w in n.split() if w not in GENERIC)


def score(a, b):
    return SequenceMatcher(None, " ".join(sorted(a.split())), " ".join(sorted(b.split()))).ratio()


def load_posts(folder):
    posts = []
    for f in sorted(Path(folder).glob("*.json")):
        if f.name.startswith("_"):
            continue
        p = json.loads(f.read_text())
        loc = (p.get("fields") or {}).get("location") or ""
        city, _, state = loc.rpartition(",")
        posts.append({"slug": p["slug"], "title": p["title"], "location": loc, "city": city.strip(),
                      "state": STATES.get(state.strip(), "")})
    return posts


def load_institutions(path):
    with open(path, encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    for r in rows:
        r["_names"] = {strip_campus(norm_name(r["name"])), norm_name(r["name"])}
        r["_aliases"] = {norm_name(a) for a in (r.get("alias") or "").replace("|", ",").split(",") if a.strip()}
    return rows


def match(posts, insts):
    by_state = defaultdict(list)
    for r in insts:
        by_state[r["state"]].append(r)
    out = []
    for p in posts:
        name = norm_name(html.unescape(p["title"]))
        pool = by_state.get(p["state"]) or insts
        res = {"slug": p["slug"], "title": p["title"], "location": p["location"]}
        exact = [r for r in pool if name in r["_names"] or strip_campus(name) in r["_names"]]
        if len(exact) > 1:  # same name in one state: the city decides
            exact = [r for r in exact if r["city"].lower() == p["city"].lower()] or exact
        alias = [r for r in pool if name in r["_aliases"]]
        mine = core(name)
        same_city = [r for r in pool if r["city"].lower() == p["city"].lower()]
        place = set(norm_name(f"{p['city']} {p['location'].rpartition(',')[2]} {p['state']}").split()) | QUALIFIERS

        def same_school(c):  # equal, or one adds only place words ("Penn State Hazleton" in Pennsylvania)
            if not mine or not c:
                return False
            small, big = sorted((c, mine), key=len)
            return small == big or (len(small) >= 2 and small <= big and not small <= place and big - small <= place)

        renamed = [r for r in same_city if any(same_school(c) for c in map(core, r["_names"]))]
        if len(exact) == 1:
            hit, method, sc = exact[0], "exact", 1.0
        elif not exact and len(alias) == 1:
            hit, method, sc = alias[0], "alias", 1.0
        elif not exact and not alias and len(renamed) == 1:
            hit, method, sc = renamed[0], "renamed", 0.95
        else:
            ranked = sorted(((max(score(name, n) for n in r["_names"] | r["_aliases"])
                              + (0.03 if r["city"].lower() == p["city"].lower() else 0), r) for r in pool),
                            key=lambda t: -t[0])
            top = ranked[:3]
            best = top[0][0] if top else 0
            lead = best - top[1][0] if len(top) > 1 else 1
            if top and best >= ACCEPT and lead >= LEAD:
                hit, method, sc = top[0][1], "fuzzy", best
            else:
                hit, sc = None, best
                method = "review" if best >= REVIEW else "none"
                res["candidates"] = " | ".join(f"{r['unitid']} {r['name']} ({r['city']}, {r['state']}) {s:.2f}"
                                               for s, r in top if s >= REVIEW - 0.15)
        res["method"], res["score"] = method, round(min(sc, 1.0), 3)
        if hit:
            res.update(unitid=hit["unitid"], ipeds_name=hit["name"], ipeds_city=hit["city"],
                       ipeds_state=hit["state"], operating=hit.get("operating", ""),
                       merged_into=hit.get("merged_into", ""))
        out.append(res)
    claimed = defaultdict(list)
    for r in out:
        if r.get("unitid"):
            claimed[r["unitid"]].append(r["slug"])
    for r in out:
        others = [s for s in claimed.get(r.get("unitid"), []) if s != r["slug"]]
        if others:
            r["duplicate_of"] = " ".join(others)
    return out


COLUMNS = ["slug", "title", "location", "method", "score", "unitid", "ipeds_name", "ipeds_city", "ipeds_state",
           "operating", "merged_into", "duplicate_of", "candidates"]


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--colleges", default=str(COLLEGES))
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--out", default=str(OUT))
    a = ap.parse_args(argv)
    res = match(load_posts(a.colleges), load_institutions(a.institutions))
    out = Path(a.out)
    write_csv(out / "match.csv", res, COLUMNS)
    review = [r for r in res if r["method"] in ("review", "none") or r.get("duplicate_of")]
    write_csv(out / "match_review.csv", review, COLUMNS)
    counts = defaultdict(int)
    for r in res:
        counts[r["method"]] += 1
    print(f"{len(res):,} posts: " + ", ".join(f"{k} {v:,}" for k, v in sorted(counts.items()))
          + f"; {len(review):,} to review")
    return res


if __name__ == "__main__":
    main()
