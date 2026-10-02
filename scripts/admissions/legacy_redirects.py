#!/usr/bin/env python3
"""Map the old /admission/<slug>/ addresses that no longer reach their college (Admissions Phase 2).

    python3 scripts/admissions/legacy_redirects.py [--history] [--gsc path/to/gsc_pages.json]

Reads where each old address in data/admissions/redirects/old_admission_urls_check.tsv lands today (from
scripts/check_urls.py on GitHub) and names the college behind its old slug: the current post whose title gives that
slug, or else the IPEDS colleges whose name or alias gives it, in the current directory and, with --history, in the
directories back to 2002 (downloaded as scripts/admissions/audit.py does; GitHub runs it this way). When several
colleges carry the name, they must all lead to the same answer; a slug that starts with "the-" prefers the one whose
name starts with "The". A slug that finds nothing as written is read again with "uni" as "university" and "dc" as
"district of columbia", and with a trailing state ("-maine", "-wi") and city ("-bangor-maine") taken as the college's
location. Then, for every address that ends on a 404, on WordPress's slug guess, or on a Rank Math redirect to a
missing page:

- 301 to the college's page, when one published post that stays (after checkpoints C and D) is matched to it;
- the same answer C and D give that page, when they retire or redirect it;
- 301 to the successor's page, when IPEDS says the college merged into one with a page here;
- 410 Gone, when IPEDS says the college closed (a closing date), or that its successor did.

Writes, in data/admissions/redirects/:
- legacy_redirect_map.csv: the rules scripts/admissions/legacy_redirects_live.sh adds, one per old slug, each
  covering admission/<slug> and admissions/<slug>;
- legacy_unresolved.csv: old addresses left as they are (no college found, several candidates, or a college that
  operates but has no page here), with what they get today;
- legacy_existing_wrong.csv: Rank Math redirects that already exist but end on another college's page.
Search Console clicks and impressions (June 2025 to September 2026) are added when the JSON export is given.
"""
import argparse
import csv
import json
import os
import re
import sys
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import STATES, norm_name  # noqa: E402
from match import strip_campus  # noqa: E402

DATA = Path(__file__).resolve().parents[2] / "data"
ADM = DATA / "admissions"
OUT = ADM / "redirects"
SITE = "https://gpacalculator.net/admissions/"
CONFIDENT = {"exact", "renamed", "alias", "fuzzy"}
SLUG_WORDS = {"uni": "university", "dc": "district of columbia"}  # 'uni-of-the-dc-david-a-clarke-school-of-law'
STATE_SLUGS = {k.lower().replace(" ", "-"): v for k, v in STATES.items()}  # 'new-york' -> 'NY'
STATE_CODES = set(STATES.values())


def read(path, delimiter=","):
    with open(path, newline="") as f:
        return list(csv.DictReader(f, delimiter=delimiter))


def keys(name):
    """Comparable forms of a name or slug ('University of X-Main Campus' also gives 'university x')."""
    n = norm_name(name.replace("-", " "))
    return {n, strip_campus(n)} - {""}


def squashed(name):
    """The same without spaces, so 'texas a m' and 'texas am' agree; used only when the words find nothing."""
    return {k.replace(" ", "") for k in keys(name)}


def readings(old):
    """Other ways to read an old slug, tried when it finds no answer as written: (name, state, city, how)."""
    words = old.split("-")
    spelled = [SLUG_WORDS.get(w, w) for w in words]
    if spelled != words:
        yield " ".join(spelled), "", "", "abbreviation spelled out"
    for n in (3, 2, 1):
        tail = "-".join(words[-n:])
        if len(words) > n and tail in STATE_SLUGS:
            rest = words[:-n]
            for c in range(min(3, len(rest) - 1) + 1):
                city = " ".join(rest[len(rest) - c:])
                yield " ".join(rest[:len(rest) - c]), STATE_SLUGS[tail], city, f"located in {city + ', ' if city else ''}{STATE_SLUGS[tail]}"
            break
    if len(words) > 1 and len(words[-1]) == 2 and words[-1].upper() in STATE_CODES:
        yield " ".join(words[:-1]), words[-1].upper(), "", f"located in {words[-1].upper()}"


def slug_of(url):
    m = re.search(r"/admissions?/([^/]+)/?$", url or "")
    return m.group(1) if m else ""


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--history", action="store_true", help="also match names in the IPEDS directories 2002-2023")
    ap.add_argument("--gsc", help="Windsor.ai Search Console page export (JSON with a 'result' list)")
    a = ap.parse_args(argv)
    gsc = {}
    if a.gsc:
        for r in json.load(open(a.gsc))["result"]:
            gsc[r["page"]] = (r["clicks"], r["impressions"])

    posts = {}
    for f in (DATA / "colleges").glob("*.json"):
        if not f.name.startswith("_"):
            p = json.loads(f.read_text())
            posts[p["slug"]] = p
    match = {r["slug"]: r for r in read(ADM / "match.csv")}
    inst = {r["unitid"]: r for r in read(ADM / "institutions.csv")}
    actions = {r["slug"]: r for r in read(ADM / "audit" / "phase2_cd_actions.csv")}

    by_title = defaultdict(set)  # name key -> post slugs whose title gives it
    for slug, p in posts.items():
        for k in keys(p["title"]):
            by_title[k].add(slug)
    by_ipeds, by_squash = defaultdict(set), defaultdict(set)  # name key -> unitids whose name or alias gives it
    names_of, places_of = defaultdict(set), defaultdict(set)  # unitid -> every name, every (city, state) it had

    def index(u, name, aliases, city, state):
        names_of[u].add(name.lower())
        places_of[u].add((norm_name(city), state))
        for n in [name] + [x for x in (aliases or "").replace("|", ",").split(",") if x.strip()]:
            for k in keys(n):
                by_ipeds[k].add(u)
            for k in squashed(n):
                by_squash[k].add(u)

    for u, r in inst.items():
        index(u, r["name"], r.get("alias"), r["city"], r["state"])
    hist = {}
    if a.history:
        import audit  # noqa: E402 - downloads the HD files the first time
        hist = audit.load_history(2002, 2023)
        for u, years in hist.items():
            for rec in years.values():
                index(u, rec["INSTNM"], rec.get("IALIAS"), rec["CITY"], rec["STABBR"])
    posts_of = defaultdict(list)  # unitid -> confidently matched posts
    for slug, m in match.items():
        if m["unitid"] and m["method"] in CONFIDENT:
            posts_of[m["unitid"]].append(slug)

    def post_place(slug):
        city, _, state = match.get(slug, {}).get("location", "").rpartition(",")
        return {(norm_name(city), STATES.get(state.strip(), ""))}

    def placed(places, state, city):
        return any(s == state and (not city or c == norm_name(city)) for c, s in places)

    def answer_for_post(slug):
        """What the post's own address answers after C and D: ('301', url) or ('410', '')."""
        a = actions.get(slug)
        if not a:
            return "301", SITE + slug + "/"
        return ("410", "") if a["action"] == "retire" else ("301", a["target"])

    def name_of(u):
        if u in inst:
            return inst[u]["name"]
        return audit_last(u)["INSTNM"] if u in hist else u

    def audit_last(u):
        return hist[u][max(hist[u])]

    def page_answer(u):
        """(action, target, note) for one college, or (None, '', why)."""
        mine = [s for s in posts_of.get(u, []) if s in posts]
        if len(mine) == 1:
            return (*answer_for_post(mine[0]), "")
        if len(mine) > 1:
            return None, "", "several pages for this college: " + " ".join(sorted(mine))
        if u in inst:
            succ = inst[u].get("merged_into", "")
            closed = inst[u].get("closed_date", "")
        else:
            import audit  # noqa: E402
            got = audit.successor(u, hist, inst)
            succ = got[0] if got else ""
            shut = audit.closing(hist[u])
            closed = shut[1] if shut else ""
        if succ and succ != u:
            action, target, note = page_answer(succ)
            if action:
                return action, target, f"merged into {name_of(succ)}" + (f"; {note}" if note else "")
            if closed:
                return "410", "", f"closed {closed} (IPEDS)"
            return None, "", f"merged into {name_of(succ)}, which has no page here"
        if closed:
            return "410", "", f"closed {closed} (IPEDS)"
        if u in inst:
            if inst[u].get("operating") == "No":
                return None, "", "no page here; College Scorecard (June 2026) says it no longer operates, IPEDS gives no closing date"
            if inst[u].get("operating") != "Yes":
                return None, "", "no page here; open in IPEDS 2024 but missing from College Scorecard's June 2026 release (may have closed since)"
            return None, "", "operating, but no page here"
        return None, "", f"left IPEDS after {max(hist[u])} without a closing date or a successor"

    def decide_one(old, name, state="", city=""):
        """(action, target, college, unitid, how, note) for one reading of the slug; action None finds no answer."""
        ks = keys(name)
        titled = set().union(*(by_title.get(k, set()) for k in ks))
        if state:
            titled = {s for s in titled if placed(post_place(s), state, city)}
        if len(titled) > 1 and old.startswith("the-"):
            titled = {s for s in titled if posts[s]["title"].lower().startswith("the ")} or titled
        if len(titled) == 1:
            slug = titled.pop()
            return (*answer_for_post(slug), posts[slug]["title"], match.get(slug, {}).get("unitid", ""), "title", "")
        if len(titled) > 1:
            return None, "", "", "", "", "several pages: " + " ".join(sorted(titled))
        units, how = set().union(*(by_ipeds.get(k, set()) for k in ks)), "ipeds"
        if state:
            units = {u for u in units if placed(places_of[u], state, city)}
        if not units:
            units, how = set().union(*(by_squash.get(k, set()) for k in squashed(name))), "ipeds, spacing aside"
            if state:
                units = {u for u in units if placed(places_of[u], state, city)}
        if not units:
            return None, "", "", "", "", "no college by that name in IPEDS" + (" (2002-2024)" if hist else " (2024)")
        if len(units) > 1 and old.startswith("the-"):
            units = {u for u in units if any(n.startswith("the ") for n in names_of[u])} or units
        answers = {u: page_answer(u) for u in sorted(units)}
        found = {(x[0], x[1]) for x in answers.values()}
        names = " | ".join(f"{u} {name_of(u)}" for u in answers)
        if len(found) == 1 and None not in {x[0] for x in answers.values()}:
            action, target = found.pop()
            notes = sorted({x[2] for x in answers.values() if x[2]})
            if old.startswith("the-") and not any(name_of(u).lower().startswith("the ") for u in answers):
                notes.append("matched without the leading 'The'")
            return action, target, names, " ".join(answers), how, "; ".join(notes)
        if len(answers) == 1:
            return None, "", names, " ".join(answers), how, next(iter(answers.values()))[2]
        lead = ("several colleges by that name, none with an answer: " if found == {(None, "")}
                else "several colleges with different answers: ")
        return None, "", names, " ".join(answers), how, lead + "; ".join(
            f"{name_of(u)}: {x[0] or 'none'} {x[1] or x[2]}" for u, x in answers.items())

    def decide(old):
        """The slug as written, else the first other reading that finds an answer (else the slug's own result)."""
        first = decide_one(old, old)
        if first[0]:
            return first
        for name, state, city, why in readings(old):
            got = decide_one(old, name, state, city)
            if got[0]:
                return (*got[:4], f"{got[4]}, {why}", got[5])
        return first

    rows, unresolved, wrong = [], [], []
    for c in read(OUT / "old_admission_urls_check.tsv", "\t"):
        old = slug_of(c["old_url"])
        today = f"{c['status']} {c['redirect_by'] or ''} -> {c['final_status']} {c['final_url'] if c['final_url'] != c['old_url'] else ''}".replace("  ", " ").strip()
        clicks, imps = gsc.get(c["old_url"], ("", ""))
        if old in posts:
            continue  # a page has this slug again; not an old address any more
        action, target, college, unitid, how, note = decide(old)
        if c["status"] == "410" or (c["redirect_by"] == "Rank Math" and c["final_status"] in ("200", "410")):
            # Answers already. Report a Rank Math redirect that lands on another college's page.
            end = slug_of(c["final_url"])
            if (c["final_status"] == "200" and action == "301" and target and slug_of(target) != end
                    and end in posts and match.get(end, {}).get("unitid") != unitid):
                wrong.append([old, c["final_url"], target, college, unitid, how, clicks, imps])
            continue
        if action:
            rows.append([old, action, target, college, unitid, how, note, today, clicks, imps])
        else:
            unresolved.append([old, today, note, college, unitid, clicks, imps])

    def write(name, cols, data):
        with open(OUT / name, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(cols)
            w.writerows(sorted(data, key=lambda r: (-(r[-1] or 0), r[0])))

    write("legacy_redirect_map.csv",
          ["old_slug", "action", "target", "college", "unitid", "found_by", "note", "today", "clicks", "impressions"], rows)
    write("legacy_unresolved.csv", ["old_slug", "today", "why", "college", "unitid", "clicks", "impressions"], unresolved)
    write("legacy_existing_wrong.csv",
          ["old_slug", "lands_on", "should_go_to", "college", "unitid", "found_by", "clicks", "impressions"], wrong)
    n301 = sum(1 for r in rows if r[1] == "301")
    print(f"map: {len(rows)} ({n301} x 301, {len(rows) - n301} x 410); unresolved: {len(unresolved)}; "
          f"existing redirects to another college: {len(wrong)}")


if __name__ == "__main__":
    main()
