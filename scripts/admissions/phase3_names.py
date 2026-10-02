#!/usr/bin/env python3
"""College page titles that lost punctuation or still carry a former name, with the name to use instead.

    python3 scripts/admissions/phase3_names.py

Admissions Phase 3. Compares each published college page's title (the live state, rebuilt by
scripts/admissions/preview/compose_state.py) with the college's 2024 IPEDS name (data/admissions/institutions.csv)
and every name it carried in IPEDS from 2002 to 2023 (data/admissions/review/ipeds_names.csv, made on GitHub by
scripts/admissions/ipeds_names.py). Writes data/admissions/audit/phase3_names.csv, one row per title to change:

- punctuation: the title has the 2024 name's letters but lacks punctuation inside the name: a hyphen between two words
  of the name (Hardin-Simmons, Anoka-Ramsey), an apostrophe (Saint Mary's), a period (St.) or the capitals (SOWELA).
  The title keeps punctuation IPEDS drops (A.T. Still, a comma) and keeps a space where IPEDS joins a campus or city
  to a college's or system's name with a hyphen (University of Houston Clear Lake), as the site's titles do.
- former name: the title is a name the same college (same UNITID) carried in an earlier IPEDS year, and the college
  has since been renamed (Calvin College is Calvin University). The new title is the 2024 name, written the same way.
  The import keeps the old name in the post's former_name field: the page says "(formerly ...)" and the hub's search
  finds it.
- campus name: only the campus or place in the name changed (Herzing University Kenner is Herzing University New
  Orleans), IPEDS dropped it (Pace University New York is Pace University) or added it where several pages share the
  title (two pages called Fortis College). No former_name.
- name form: the same name in the form the college now reports, with no rename to mention: "at" dropped or added
  (University of Illinois Chicago), a spelling, an initial, "(The)" or "Inc" dropped. No former_name.

Titles that match an earlier IPEDS name but stay as they are go to data/admissions/audit/phase3_names_kept.csv with
the reason: only "The", "Saint"/"St." or the word order differ; IPEDS adds a campus to a title only this page uses
(Arizona State University Campus Immersion) or names the district or system (Blinn College District); dropping the
campus would leave a name other colleges share (Lincoln University); an IPEDS typo; the college's own name (KEEP).
Changes nothing on the site.
"""
import csv
import difflib
import html
import re
import sys
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DATA = ROOT / "data" / "admissions"
sys.path.insert(0, str(ROOT / "scripts" / "admissions"))
sys.path.insert(0, str(ROOT / "scripts" / "admissions" / "preview"))
from common import STATES  # noqa: E402
from compose_state import compose  # noqa: E402

OUT = DATA / "audit" / "phase3_names.csv"
KEPT = DATA / "audit" / "phase3_names_kept.csv"
COLS = ["slug", "unitid", "old_title", "new_title", "kind", "former_name", "evidence"]
KEPT_COLS = ["slug", "unitid", "title", "ipeds_2024", "reason", "evidence"]
# A hyphen after one of these words in an IPEDS name joins a campus or place to the college's name
GENERIC_END = {
    "academy", "art", "arts", "beauty", "bible", "career", "careers", "center", "centre", "college", "colleges",
    "conservatory", "cosmetology", "design", "district", "education", "health", "inc", "institute", "law", "llc",
    "medicine", "music", "nursing", "polytechnic", "school", "schools", "science", "sciences", "seminary", "studies",
    "system", "tech", "technology", "theology", "university",
}
CAMPUS_WORDS = {"main", "campus", "campuses", "branch", "online", "global", "immersion", "digital", "worldwide"}
CONNECTORS = {"at", "in", "of", "the", "and"}
LEGAL = {"inc", "llc", "ltd"}
ALIASES = {"st": "saint", "ste": "sainte", "ft": "fort", "mt": "mount"}
STATE_NAMES = {s.lower() for s in STATES}
PUNCT = ".'’,"
# Titles that are the college's own name, which IPEDS writes otherwise
KEEP = {
    "harper-college": "IPEDS uses the legal name, William Rainey Harper College; the college calls itself Harper College",
    "sewanee-the-university-of-the-south": "the college calls itself Sewanee: The University of the South; IPEDS "
                                           "drops Sewanee",
}


def clean(s):
    return " ".join(html.unescape(s or "").split())


def norm(s):
    s = clean(s).lower().replace("&", " and ").replace("’", "'")
    s = re.sub(r"[.,'\-–—:()/]", " ", s)
    return " ".join(s.split())


def words(s):
    """norm()'s words, with St, Ft and Mt spelled out."""
    return [ALIASES.get(w, w) for w in norm(s).split()]


def squash(s):
    return re.sub(r"[^a-z0-9]", "", clean(s).lower().replace("&", "and"))


def bare(word):
    return re.sub(r"[.,'’:()/]", "", word).lower()


def split(name):
    """[('Anoka', '-'), ('Ramsey', ' '), ('Community', ' '), ...]: each word and the separator after it."""
    p = re.findall(r"[^\s\-–]+|[\s\-–]+", clean(name))
    return [(p[i], p[i + 1] if i + 1 < len(p) else "") for i in range(0, len(p), 2)]


def place(ws, cities):
    """Whether the words name a state or a city of the college's state."""
    text = " ".join(ALIASES.get(w, w) for w in ws)
    return text in STATE_NAMES or text in cities


def campus_hyphen(pairs, i, cities):
    """Whether the hyphen after word i of an IPEDS name joins a campus or place to the name before it (Arkansas State
    University-Beebe, University of Houston-Clear Lake), not two words of one name (Hardin-Simmons, Urbana-Champaign)."""
    after = []
    for w, sep in pairs[i + 1:]:
        after.append(bare(w))
        if "-" in sep or "–" in sep:
            break
    before = [bare(pairs[i][0])]  # the words back to the previous hyphen
    for w, sep in reversed(pairs[:i]):
        if "-" in sep or "–" in sep:
            break
        before.insert(0, bare(w))
    if before[-1] in GENERIC_END or "campus" in after or after[:2] == ["main", "campus"]:
        return True
    if "of" in before:  # University of Wisconsin-Parkside, City Colleges of Chicago-Malcolm X College
        k = len(before) - 1 - before[::-1].index("of")
        if place(before[k + 1:], cities):
            return True
    return place(after, cities) and not place(before[-1:], cities)  # Urbana-Champaign joins two cities


def pick(a, b):
    """The title's word a or IPEDS's word b, which have the same letters: the one with more punctuation, IPEDS's
    capitals where only they differ, and the title's comma."""
    comma = a.endswith(",") or b.endswith(",")
    a, b = a.rstrip(","), b.rstrip(",")
    pa = re.sub(r"[^.'’]", "", a.replace("’", "'"))
    pb = re.sub(r"[^.'’]", "", b)
    if a == b or a.replace("’", "'") == b or len(pa) > len(pb):
        word = a  # the title keeps what IPEDS drops (A.T.)
    else:
        word = b  # IPEDS's period, apostrophe or capitals (St., Mary's, SOWELA, O'odham)
    return word + ("," if comma else "")


def restore_punctuation(title, ipeds, cities):
    """The title with the punctuation inside the name that IPEDS has and the title lacks, or None if the two can't
    be lined up word for word."""
    tw, nw = split(title), split(ipeds)
    if not tw or len(tw) != len(nw):
        return None
    out = []
    for i, ((a, sep_a), (b, sep_b)) in enumerate(zip(tw, nw)):
        if bare(a) != bare(b) and {bare(a), bare(b)} != {"and", "&"}:
            return None
        word = a if "&" in (a, b) else pick(a, b)
        sep = sep_a
        if "-" in sep_b and sep_a == " " and not campus_hyphen(nw, i, cities):
            sep = "-"
        out.append(word + sep)
    return "".join(out).strip()


def display(ipeds, cities):
    """An IPEDS name as a page title: a campus hyphen becomes a space and "-Main Campus" goes."""
    pairs = split(re.sub(r"\s*-\s*Main Campus$", "", clean(ipeds)))
    out = []
    for i, (w, sep) in enumerate(pairs):
        if ("-" in sep or "–" in sep) and campus_hyphen(pairs, i, cities):
            sep = " "
        out.append(w + sep)
    return "".join(out).strip()


def former(title, name, cities):
    """The old title as the page mentions it ("formerly ..."): with the punctuation of the IPEDS name it matched and
    without "Main Campus"."""
    title = re.sub(r"\s+Main Campus$", "", title)
    name = display(name, cities)
    if name.isupper():
        return title
    return restore_punctuation(title, name, cities) or title


def is_place(ws, cities):
    """Whether words name a campus or place: a state, a city, "Main Campus", "Birmingham Campus", "of Pennsylvania"."""
    ws = list(ws)
    while ws and ws[0] in ("of", "at", "in"):
        ws = ws[1:]
    return bool(ws) and (place(ws, cities) or all(w in CAMPUS_WORDS for w in ws)
                         or ws[-1] in ("campus", "campuses", "branch"))


def same_name(o, n):
    """Whether two differing stretches of words are the same name written another way: a connector, an initial,
    "Inc", or a spelling (Centeville, Centerville)."""
    ws = set(o + n)
    if ws <= CONNECTORS or ws <= LEGAL or all(len(w) == 1 for w in ws):
        return True
    return bool(o and n) and difflib.SequenceMatcher(None, " ".join(o), " ".join(n)).ratio() >= 0.8


def classify(title, ipeds, new, cities, shared_title, shared_name):
    """('kind', '') for a change, or (None, why the title stays)."""
    if re.search(r"\band &|& and\b", ipeds):
        return None, "IPEDS typo"
    old_w, new_w = words(title), words(new)
    if old_w == new_w:
        return None, "only Saint/St. differs"
    if sorted(old_w) == sorted(new_w):
        return None, "only the word order differs"
    ops = [op for op in difflib.SequenceMatcher(None, old_w, new_w, autojunk=False).get_opcodes() if op[0] != "equal"]
    segs = [(old_w[i1:i2], new_w[j1:j2]) for _, i1, i2, j1, j2 in ops]
    changed = {w for o, n in segs for w in o + n}
    if changed <= {"the"}:
        return ("name form", "") if "(the)" in title.lower() else (None, 'only "The" differs')
    if new_w[-1] in ("district", "system") and new_w[-1] not in old_w:
        return None, "IPEDS names the district or system"
    if all(same_name(o, n) for o, n in segs):
        # The University of Tennessee at Chattanooga: IPEDS writes The University of Tennessee-Chattanooga
        for _, i1, i2, j1, j2 in ops:
            if j1 == j2 and set(old_w[i1:i2]) <= {"at", "in"} and i2 < len(old_w) \
                    and "-" + old_w[i2] in ipeds.lower():
                return None, 'IPEDS joins the campus with a hyphen where the title has "at"'
        return "name form", ""
    # A campus or place at the end of the name (Herzing University-Kenner), not one inside it (Azusa Pacific Online
    # University is Los Angeles Pacific University)
    _, i1, i2, j1, j2 = ops[0]
    if len(ops) == 1 and i2 == len(old_w) and j2 == len(new_w) and \
            (i1 == i2 or is_place(old_w[i1:i2], cities)) and (j1 == j2 or is_place(new_w[j1:j2], cities)):
        if all(not o for o, n in segs):
            if shared_title:
                return "campus name", ""
            return None, "IPEDS adds a campus or place to a title only this page uses"
        if all(not n for o, n in segs) and shared_name:
            return None, "without the campus or place, other colleges share the name"
        return "campus name", ""
    return "former name", ""


def main():
    with open(DATA / "institutions.csv", newline="", encoding="utf-8") as f:
        inst = {r["unitid"]: r for r in csv.DictReader(f)}
    cities = defaultdict(set)
    for r in inst.values():
        cities[r["state"]].add(" ".join(words(r["city"])))
    ipeds_names = Counter(norm(r["name"]) for r in inst.values())
    history = defaultdict(list)
    with open(DATA / "review" / "ipeds_names.csv", newline="", encoding="utf-8") as f:
        for r in csv.DictReader(f):
            history[r["unitid"]].append(r)
    state = compose()
    pages = {slug: p for slug, p in state.posts.items() if p["status"] == "publish"}
    titles = Counter(norm(p["title"]) for p in pages.values())
    rows, kept, counts = [], [], Counter()
    for slug, p in sorted(pages.items()):
        uid = str(p["fields"].get("ipeds_unitid") or "").strip()
        if uid not in inst:
            continue
        title, ipeds = clean(p["title"]), clean(inst[uid]["name"])
        st_cities = cities[inst[uid]["state"]]
        if title == ipeds:
            continue
        if squash(title) == squash(ipeds):
            new = restore_punctuation(title, ipeds, st_cities)
            if new and new != title:
                rows.append([slug, uid, title, new, "punctuation", "", f"IPEDS 2024: {ipeds}"])
                counts["punctuation"] += 1
            continue
        older = [h for h in history.get(uid, []) if squash(h["name"]) != squash(ipeds)
                 and words(title) in (words(h["name"]), words(re.sub(r"\s*-\s*Main Campus$", "", h["name"])))]
        if not older:
            continue
        h = older[-1]
        years = h["first_year"] if h["first_year"] == h["last_year"] else f"{h['first_year']}-{h['last_year']}"
        evidence = f"IPEDS {years}: {clean(h['name'])}; 2024: {ipeds}"
        new = display(ipeds, st_cities)
        if slug in KEEP:
            kind, why = None, KEEP[slug]
        else:
            shared_name = ipeds_names[norm(new)] > 1 or titles[norm(new)] > (norm(new) == norm(title))
            kind, why = classify(title, ipeds, new, st_cities, titles[norm(title)] > 1, shared_name)
        if not kind:
            kept.append([slug, uid, title, ipeds, why, evidence])
            continue
        old_name = former(title, h["name"], st_cities) if kind == "former name" else ""
        rows.append([slug, uid, title, new, kind, old_name, evidence])
        counts[kind] += 1
    for path, cols, out in ((OUT, COLS, rows), (KEPT, KEPT_COLS, kept)):
        with open(path, "w", newline="", encoding="utf-8") as f:
            w = csv.writer(f, lineterminator="\n")
            w.writerow(cols)
            w.writerows(out)
    after = Counter(titles)
    for r in rows:
        after[norm(r[2])] -= 1
        after[norm(r[3])] += 1
    print(f"{len(rows)} titles to change: " + ", ".join(f"{n} {k}" for k, n in counts.most_common())
          + f"; {len(kept)} former names kept as they are")
    print(f"titles shared by several pages: {sum(1 for n in titles.values() if n > 1)} now, "
          f"{sum(1 for n in after.values() if n > 1)} after")


if __name__ == "__main__":
    main()
