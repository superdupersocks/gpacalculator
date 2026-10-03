#!/usr/bin/env python3
"""Cleanup QA for /admissions/ (Digant's plan of 2026-10-03, step 1): every college page the cleanup removed, what it
answers today, the redirect chains and 404s around it, its Search Console clicks, and the links that still point at it.

    python scripts/admissions/cleanup_qa.py urls      write data/admissions/cleanup_qa/urls.tsv and pages.txt
    python scripts/admissions/cleanup_qa.py renames   write renames.csv: college pages whose address has an older name
    python scripts/admissions/cleanup_qa.py report    read the live check's results and write removed_urls.csv,
                                                      other_addresses.csv, fixes.csv, leftover_pages.csv and
                                                      internal_link_fixes.csv (report.md sums them up)
    python scripts/admissions/cleanup_qa.py after     once the fixes are live: write after/urls.tsv (every address
                                                      above plus the renamed colleges' old and new ones, each with the
                                                      answer it should give now), after/pages.txt and
                                                      after/sitemap_colleges.csv for the same live check
    python scripts/admissions/cleanup_qa.py verify    read that check's results and write after/verify.csv
    python scripts/admissions/cleanup_qa.py followup  write followup.csv: the rows of fixes.csv whose address still
                                                      answers otherwise in verify.csv, for cleanup_fix_live.sh

`urls` lists each address to check on the live site: both forms (/admissions/<slug>/ and the old /admission/<slug>/)
of the 516 college pages published in the pre-cleanup export (data/colleges/, 2026-10-01) that the colleges sitemap
no longer lists, the old addresses Phase 2 gave rules (legacy map, the 358 /admissions/ twins, the first pass), the 112
it left unresolved, and every other non-live address Search Console showed in the last 12 months. pages.txt lists the
pages whose links are checked: everything in the other sitemaps, the hub and two college pages (their header and
footer).

.github/workflows/admissions-cleanup-qa.yml runs scripts/admissions/cleanup_qa_check.py on GitHub with these and
commits live_status.tsv and internal_links.tsv, which `report` reads.
"""
import csv
import html
import glob
import json
import os
import re
import sys
import unicodedata
from collections import Counter, defaultdict
from urllib.parse import quote, unquote

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SITE = "https://gpacalculator.net"
AUDIT = os.path.join(ROOT, "data/admissions/audit")
OUT = os.path.join(ROOT, "data/admissions/cleanup_qa")
LIVE_CSV = os.path.join(ROOT, "data/admissions/live_checks/sitemap_colleges.csv")
OTHER_CSV = os.path.join(ROOT, "data/admissions/live_checks/sitemap_other.csv")
GSC_CSV = os.path.join(ROOT, "data/admissions/search_console/pages_12m.csv")
ACTION_FILES = ["phase2_cd_actions.csv", "phase2_s_actions.csv", "phase2_r_actions.csv"]
URL_COLS = ["url", "slug", "form", "source", "expected", "clicks_12m", "impressions_12m"]


def read_csv(path, **kw):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f, **kw))


def slug_of(url):
    """The college slug of an /admissions/ or /admission/ address, or ''."""
    m = re.match(r"https?://(?:www\.)?gpacalculator\.net/admissions?/([^/?#]*)", url or "")
    return m.group(1).lower() if m else ""


def address(form, slug):
    return f"{SITE}/{form}/{slug}/"


def pre_cleanup():
    """slug -> record of every college published in the 2026-10-01 export."""
    out = {}
    for path in glob.glob(os.path.join(ROOT, "data/colleges/*.json")):
        if os.path.basename(path).startswith("_"):
            continue
        with open(path, encoding="utf-8") as f:
            rec = json.load(f)
        out[rec["slug"]] = rec
    return out


def live_slugs():
    return {slug_of(r["url"]) for r in read_csv(LIVE_CSV) if slug_of(r["url"])}


def phase2_actions():
    out = {}
    for name in ACTION_FILES:
        for r in read_csv(os.path.join(AUDIT, name)):
            out[r["slug"]] = r
    return out


def search_console():
    """(form, slug) -> [clicks, impressions] over the last 12 months, all URL variants of the address together."""
    agg = defaultdict(lambda: [0, 0])
    for r in read_csv(GSC_CSV):
        m = re.match(r"https?://(?:www\.)?gpacalculator\.net/(admissions?)/([^/?#]+)", r["page"])
        if m:
            key = (m.group(1), m.group(2).lower())
            agg[key][0] += int(r["clicks"])
            agg[key][1] += int(r["impressions"])
    return agg


def expected_rules():
    """slug -> (source, expected answer) for each old address a Phase 2 rule or list covers, first source first."""
    out = {}
    redirects = os.path.join(ROOT, "data/admissions/redirects")
    first = os.path.join(ROOT, "scripts/admissions/legacy_first_pass")
    for r in read_csv(os.path.join(redirects, "legacy_redirect_map.csv")):
        out.setdefault(r["old_slug"], ("legacy map", "410" if r["action"] == "410" else f"301 {r['target']}"))
    for r in read_csv(os.path.join(first, "actions.csv")):
        out.setdefault(r["old_slug"], ("old-slug twin", "410" if r["code"] == "410" else f"301 {r['target']}"))
    for r in read_csv(os.path.join(first, "actions-20261002-0631.csv")):
        out.setdefault(r["old_slug"], ("first pass", f"301 {r['target']}"))
    for r in read_csv(os.path.join(redirects, "legacy_unresolved.csv")):
        out.setdefault(r["old_slug"], ("unresolved", "unresolved"))
    return out


def cmd_urls():
    pre, live, acts, gsc = pre_cleanup(), live_slugs(), phase2_actions(), search_console()
    rows = {}

    def add(form, slug, source, expected):
        url = address(form, slug)
        if url in rows:
            if source not in rows[url]["source"]:
                rows[url]["source"] += "; " + source
            return
        clicks, impressions = gsc.get((form, slug), [0, 0])
        rows[url] = {"url": url, "slug": slug, "form": form, "source": source, "expected": expected,
                     "clicks_12m": clicks, "impressions_12m": impressions}

    removed = sorted(set(pre) - live)
    for slug in removed:
        a = acts[slug]
        expected = "410" if a["action"] == "retire" else f"301 {a['target']}"
        for form in ("admissions", "admission"):
            add(form, slug, f"removed ({a['checkpoint']})", expected)
    for slug, (source, expected) in sorted(expected_rules().items()):
        if slug in live:
            continue
        for form in ("admission", "admissions"):
            add(form, slug, source, expected)
    for (form, slug), (clicks, impressions) in sorted(gsc.items()):
        if slug not in live:
            add(form, slug, "search console", "")
    # A few live colleges' old /admission/ addresses, which WordPress's exact-slug guess should send on in one hop
    for slug in ("harvard", "maharishi-university-of-management", "el-centro-college", "uc-berkeley"):
        add("admission", slug, "live college, old form", f"301 {address('admissions', slug)}")

    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "urls.tsv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, URL_COLS, delimiter="\t")
        w.writeheader()
        w.writerows(rows[u] for u in sorted(rows))
    pages = [r["url"] for r in read_csv(OTHER_CSV)]
    pages += [f"{SITE}/admissions/", f"{SITE}/admissions/harvard/", f"{SITE}/admissions/el-centro-college/"]
    with open(os.path.join(OUT, "pages.txt"), "w", encoding="utf-8") as f:
        f.write("# Pages whose links into /admission(s)/ the live check reads (scripts/admissions/cleanup_qa.py urls)\n")
        f.write("\n".join(dict.fromkeys(pages)) + "\n")
    print(f"{len(removed)} removed college pages; {len(rows)} addresses to check "
          f"({Counter(r['source'].split(';')[0].split(' (')[0] for r in rows.values()).most_common()}); "
          f"{len(set(pages))} pages to read for links")


# Renamed schools ---------------------------------------------------------------------------------------------------

STOP = {"the", "of", "and", "at", "in", "a", "an", "for", "on", "inc", "llc"}
BRANDS = {"suny", "cuny"}  # suny-binghamton: the system's name, still the college's common name
QUALIFIERS = {"main", "campus", "branch"}  # "Arkansas State University Main Campus": a fuller name, not an old one
STATE_NAMES = {
    "AL": "Alabama", "AK": "Alaska", "AZ": "Arizona", "AR": "Arkansas", "CA": "California", "CO": "Colorado",
    "CT": "Connecticut", "DE": "Delaware", "DC": "District of Columbia", "FL": "Florida", "GA": "Georgia",
    "HI": "Hawaii", "ID": "Idaho", "IL": "Illinois", "IN": "Indiana", "IA": "Iowa", "KS": "Kansas", "KY": "Kentucky",
    "LA": "Louisiana", "ME": "Maine", "MD": "Maryland", "MA": "Massachusetts", "MI": "Michigan", "MN": "Minnesota",
    "MS": "Mississippi", "MO": "Missouri", "MT": "Montana", "NE": "Nebraska", "NV": "Nevada", "NH": "New Hampshire",
    "NJ": "New Jersey", "NM": "New Mexico", "NY": "New York", "NC": "North Carolina", "ND": "North Dakota",
    "OH": "Ohio", "OK": "Oklahoma", "OR": "Oregon", "PA": "Pennsylvania", "RI": "Rhode Island",
    "SC": "South Carolina", "SD": "South Dakota", "TN": "Tennessee", "TX": "Texas", "UT": "Utah", "VT": "Vermont",
    "VA": "Virginia", "WA": "Washington", "WV": "West Virginia", "WI": "Wisconsin", "WY": "Wyoming",
    "PR": "Puerto Rico", "GU": "Guam", "VI": "Virgin Islands",
}
# Pages without an IPEDS ID (under review) whose address carries the college's old name, checked by hand
RENAMED_BY_HAND = {"kaplan-university-davenport-campus": "Kaplan University became Purdue University Global (2018)"}
RENAME_COLS = ["slug", "title", "ipeds_unitid", "state", "group", "old_words", "evidence", "proposed_slug", "note",
               "clicks_12m", "impressions_12m", "rules_pointing_here"]


def words(text):
    text = html.unescape(text or "")
    text = unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode()
    text = text.lower().replace("&", " ").replace("'", "").replace("’", "")
    return [w for w in re.split(r"[^a-z0-9]+", text) if w]


def proposed(title):
    """The address WordPress would give the title, with & read as a word break (Texas A&M -> texas-a-m)."""
    return "-".join(words(title))


def current_titles(pre):
    titles = {slug: html.unescape(rec["title"]) for slug, rec in pre.items()}
    for r in read_csv(os.path.join(AUDIT, "phase3_names.csv")):
        titles[r["slug"]] = r["new_title"]
    for name in ("phase2_s_pages.csv", "phase2_n_pages.csv"):
        for r in read_csv(os.path.join(AUDIT, name)):
            titles[r["slug"]] = r["post_title"]
    return titles


def unitids():
    out = {}
    for name in ("phase2_e_import.csv", "phase2_r_import.csv", "phase2_s_pages.csv", "phase2_n_pages.csv"):
        for r in read_csv(os.path.join(AUDIT, name)):
            if r.get("ipeds_unitid"):
                out.setdefault(r["slug"], r["ipeds_unitid"])
    return out


def rule_targets(acts):
    """slug -> old addresses whose rule sends them to that college page today (Phase 2 lists and old-slug rules)."""
    out = defaultdict(set)
    first = os.path.join(ROOT, "scripts/admissions/legacy_first_pass")
    rows = [(r["old_slug"], r["target"]) for r in read_csv(os.path.join(ROOT, "data/admissions/redirects/legacy_redirect_map.csv"))
            if r["action"] == "301"]
    rows += [(r["old_slug"], r["target"]) for r in read_csv(os.path.join(first, "actions.csv")) if r["code"] == "301"]
    rows += [(r["old_slug"], r["target"]) for r in read_csv(os.path.join(first, "actions-20261002-0631.csv"))]
    rows += [(slug, a["target"]) for slug, a in acts.items() if a["action"] == "301"]
    for old, target in rows:
        if slug_of(target):
            out[slug_of(target)].add(old)
    return out


def in_order(needle, hay):
    """Whether the words of needle appear in hay in the same order."""
    it = iter(hay)
    return all(w in it for w in needle)


def address_free(candidate, slug, live, pre, answers, proposed_so_far):
    """Whether a college page can move to this address, and a note on it."""
    if candidate in (live | set(pre)) - {slug}:
        return False, "is another college page's address (live or retired)"
    if candidate in proposed_so_far:
        return False, "is proposed for another page"
    if candidate in answers:
        if answers[candidate] == slug:
            return True, "takes over an old address that redirects here today"
        return False, "is an old address that answers for another college or a closure"
    return True, ""


def cmd_renames():
    pre, live, acts, gsc = pre_cleanup(), live_slugs(), phase2_actions(), search_console()
    titles, uids = current_titles(pre), unitids()
    inst = {r["unitid"]: r for r in read_csv(os.path.join(ROOT, "data/admissions/institutions.csv"))}
    names = defaultdict(list)
    for r in read_csv(os.path.join(ROOT, "data/admissions/review/ipeds_names.csv")):
        names[r["unitid"]].append(r)
    p3 = {r["slug"]: r for r in read_csv(os.path.join(AUDIT, "phase3_names.csv"))}
    answers = {old: slug_of(exp[4:]) if exp.startswith("301 ") else "" for old, (src, exp) in expected_rules().items()}
    pointing = rule_targets(acts)
    live_titles = [titles.get(s, "").lower() for s in live]
    rows = []
    for slug in sorted(live):
        title, unitid = titles.get(slug, ""), uids.get(slug, "")
        today = set(words(title))
        if unitid in inst:
            today |= set(words(inst[unitid]["name"]))
        extra = [w for w in words(slug) if w not in today and w not in STOP]
        if not extra or set(extra) <= QUALIFIERS | BRANDS:
            continue
        former, evidence = set(), []
        for r in names.get(unitid, []):
            if set(words(r["name"])) & set(extra):
                former |= set(words(r["name"]))
                evidence.append(f"IPEDS {r['first_year']}-{r['last_year']}: {r['name']}")
        if slug in p3 and p3[slug]["former_name"]:
            former |= set(words(p3[slug]["former_name"]))
            if not evidence:
                evidence.append(p3[slug]["evidence"])
        if slug in pre and html.unescape(pre[slug]["title"]) != title:
            former |= set(words(pre[slug]["title"]))
            if not evidence:
                evidence.append(f"title until 2026-10-02: {html.unescape(pre[slug]['title'])}")
        if slug in RENAMED_BY_HAND:
            evidence, former = [RENAMED_BY_HAND[slug]], former | set(extra)
        old = [w for w in extra if w in former and w not in BRANDS]
        if not old:
            continue  # abbreviations (ucla, ut-austin) and state tags (bethel-university-tn) stay
        state = inst[unitid]["state"].lower() if unitid in inst else ""
        city = words(inst[unitid]["city"]) if unitid in inst else []
        # A renamed college (IPEDS or our title history shows the old name), or an address that only adds the
        # campus's own town or state, or fixes a spelling: an optional tidy-up
        place = set(city) | set(words(STATE_NAMES.get(state.upper(), ""))) | {state} | QUALIFIERS
        place |= {short for short, full in (("st", "saint"), ("ft", "fort"), ("mt", "mount")) if full in place}
        kind = p3.get(slug, {}).get("kind", "")
        if kind == "former name" or slug in RENAMED_BY_HAND:
            group = "renamed"
        elif kind == "name form" or set(old) <= place:
            group = "optional"
        else:
            group = "renamed"
        base = proposed(title)
        # A SUNY or CUNY college's short name when the address already reads that way minus the old words (suny-
        # college-at-cortland -> suny-cortland), then its name, then the name with the state tag the address had, the
        # state or the town
        aliases = inst[unitid]["alias"] if unitid in inst else ""
        short = [proposed(a) for a in re.split(r"\s*[,|;]\s*|\s{2,}", aliases) if a]
        short = [a for a in short if len(words(a)) > 1 and words(a)[0] in BRANDS and a != slug
                 and in_order(words(a), words(slug))]
        unique = sum(1 for t in live_titles if t == title.lower()) <= 1
        rest = [w for w in extra if w not in former]  # a state tag the address kept to tell two colleges apart
        tagged = "-".join([base] + rest) if rest and rest == words(slug)[-len(rest):] else base
        candidates = short + ([base] if unique else []) + [tagged]
        candidates += [f"{tagged}-{state}" if state else "", "-".join([tagged] + city) if city else ""]
        new, note, why_taken = "", "", ""
        for candidate in dict.fromkeys(c for c in candidates if c):
            ok, why = address_free(candidate, slug, live, pre, answers, {r["proposed_slug"] for r in rows})
            if ok:
                new = candidate
                if candidate in short:
                    note = "the college's short name" + (f"; {why}" if why else "")
                elif candidate in (base, tagged) or not why_taken:
                    note = why
                else:
                    note = f"{tagged} {why_taken}, so the {'state' if candidate.endswith('-' + state) else 'town'} is added"
                break
            if candidate == tagged:
                why_taken = why
        if not new:
            note = f"{tagged} {why_taken}: needs a choice"
        clicks = sum(gsc.get((form, slug), [0, 0])[0] for form in ("admissions", "admission"))
        impressions = sum(gsc.get((form, slug), [0, 0])[1] for form in ("admissions", "admission"))
        rows.append({"slug": slug, "title": title, "ipeds_unitid": unitid, "state": state.upper(), "group": group,
                     "old_words": " ".join(old), "evidence": " | ".join(evidence[-2:]), "proposed_slug": new,
                     "note": note, "clicks_12m": clicks, "impressions_12m": impressions,
                     "rules_pointing_here": " ".join(sorted(pointing.get(slug, ())))})
    with open(os.path.join(OUT, "renames.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, RENAME_COLS)
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows)} college pages whose address carries an older name "
          f"({sum(1 for r in rows if r['group'] == 'renamed')} renamed, "
          f"{sum(1 for r in rows if r['group'] == 'optional')} optional); "
          f"{sum(1 for r in rows if 'needs a choice' in r['note'])} need a choice, "
          f"{sum(int(r['clicks_12m']) for r in rows)} clicks and {sum(int(r['impressions_12m']) for r in rows)} "
          f"impressions in 12 months")


# Report --------------------------------------------------------------------------------------------------------------

# Where a closed college's address goes when Digant's rule (clicks or backlinks in the last 12 months) turns its 410
# into a 301: the institution's own page when the college was one campus or office of one still running here,
# otherwise the hub searching the college's state.
SUCCESSORS = [
    (r"^university-of-phoenix-", "university-of-phoenix", "University of Phoenix still runs; its page"),
    (r"^devry-", "devry-university-illinois", "DeVry University still runs; its main campus's page"),
    (r"^university-of-illinois-university-administration$", "uiuc", "the University of Illinois system's flagship"),
    (r"^university-of-alaska-system-of-higher-education$", "university-of-alaska-fairbanks",
     "the University of Alaska system's flagship"),
    (r"^the-texas-am-university-system-office$", "texas-a-and-m-university-college-station",
     "the Texas A&M system's flagship"),
]
# Old addresses of a college that has a page here under another address (the same institution, or the one IPEDS says
# it merged into): they go there whatever their clicks
SAME_COLLEGE = {
    "roger-williams-university-school-of-law": "roger-williams-university",
    "antioch-university-new-england": "antioch-university",
    "antioch-university-system-administration": "antioch-university",
    "ottawa-university-kansas-city": "ottawa-university-ottawa",
    "south-university-off-campus-programs": "south-university",
    "saint-thomas-university": "st-thomas-university",
    "central-methodist-university-college-of-graduate-and-extended-studies":
        "central-methodist-university-college-of-liberal-arts-and-sciences",
    "central-methodist-uni-college-of-graduate-studies": "central-methodist-university-college-of-liberal-arts-and-sciences",
    "johnson-wales-university-online": "johnson-wales-university-providence",
    "whitworth-university-adult-degree-programs": "whitworth-university",
    "university-of-phoenix-phoenix-campus": "university-of-phoenix",  # merged into University of Phoenix-Arizona
    **{old: "long-island-university" for old in (  # merged into Long Island University (IPEDS NEWID)
        "liu-brentwood", "liu-hudson-at-westchester", "liu-riverhead", "liu-university-center-campus",
        "long-island-university-brentwood-campus", "long-island-university-riverhead-campus",
        "long-island-university-university-center-campus", "long-island-university-westchester-campus")},
}
# An old address for one of several colleges with the same name: the hub's search for the name
NAME_SEARCH = {"university-of-st-thomas-3": "St. Thomas"}
# Closed colleges whose state the IPEDS files in the repo don't give, from FSA's closed-school file
# (data/admissions/review/fsa_closed_schools.csv; OPEID, city)
STATE_BY_HAND = {
    "argosy-university-phoenix": "Arizona",  # 02179907, Phoenix
    "brown-mackie-college-indianapolis": "Indiana",  # 04051318, Indianapolis
    "cardinal-stritch-university": "Wisconsin",  # 00383700, Milwaukee
    "itt-technical-institute-university-park": "Florida",  # 10732933, Bradenton
    "morrison-university": "Nevada",  # 00844103, Reno
}
# Operating colleges with no page here whose old address had searches: a page to add, not a redirect
CONTENT_GAPS = {"university-of-minnesota-twin-cities": "University of Minnesota-Twin Cities (IPEDS 174066)"}
# Phase 2's unresolved addresses for colleges that may still get a page: they stay 404 unless one of the above applies
STILL_OPEN = ("operating", "no page here; open in IPEDS")
REMOVED_COLS = ["slug", "title", "location", "checkpoint", "planned", "admissions_answer", "admission_answer",
                "clicks_12m", "impressions_12m", "flag", "fix"]
OTHER_COLS = ["url", "source", "planned", "answer", "clicks_12m", "impressions_12m", "flag", "fix"]
FIX_COLS = ["address", "now", "code", "target", "why"]
LINK_COLS = ["page", "link", "text", "region", "answer", "flag", "fix"]
PAGE_COLS = ["url", "sitemap", "lastmod", "college_page_here", "answer", "old_form_answer"]


def read_tsv(path):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f, delimiter="\t"))


def norm(url):
    return (url or "").split("#")[0].split("?")[0].rstrip("/").lower()


def state_names(pre, slug):
    """The state a removed college page was in, from its location ("Marylhurst, Oregon")."""
    loc = (pre.get(slug, {}).get("fields") or {}).get("location", "") if slug in pre else ""
    if "," in loc:
        return loc.rsplit(",", 1)[1].strip()
    return ""


def legacy_states():
    """old slug -> the state of the college Phase 2's legacy lists matched it to (IPEDS, open or closed)."""
    codes = {r["unitid"]: r["state"] for r in read_csv(os.path.join(ROOT, "data/admissions/institutions.csv"))}
    for r in read_csv(os.path.join(ROOT, "data/admissions/review/ipeds_history.csv")):
        codes.setdefault(r["unitid"], r["state"])
    out = {}
    for name in ("legacy_redirect_map.csv", "legacy_unresolved.csv"):
        for r in read_csv(os.path.join(ROOT, "data/admissions/redirects", name)):
            code = codes.get(r.get("unitid") or "", "").upper()
            if code in STATE_NAMES:
                out.setdefault(r["old_slug"], STATE_NAMES[code])
    return out


def fallback(slug, state):
    """Where a closed college's old address goes when its clicks call for a 301: (target, why)."""
    if slug in SAME_COLLEGE:
        return address("admissions", SAME_COLLEGE[slug]), "the same college's page here"
    for pattern, target, why in SUCCESSORS:
        if re.search(pattern, slug):
            return address("admissions", target), why
    if slug in NAME_SEARCH:
        name = NAME_SEARCH[slug]
        return f"{SITE}/admissions/?search={quote(name)}", f"several colleges have the name; the hub's search for {name}"
    state = state or STATE_BY_HAND.get(slug, "")
    if state:
        return f"{SITE}/admissions/?search={quote(state)}", f"no successor; the hub's colleges in {state}"
    return f"{SITE}/admissions/", "no successor and no state on record; the hub"


def verdict(res):
    """What an address answers: (kind, short answer). Kinds: ok-301, 410, 404, live, chain, dead-end, error."""
    if not res or res.get("error"):
        return "error", (res or {}).get("error", "not checked")
    first, final, hops = res["status"], res["final_status"], int(res["hops"] or 0)
    chain = res["chain"]
    if first in ("301", "302", "307", "308"):
        if final == "200":
            return ("ok-301" if hops == 1 else "chain"), chain
        return ("dead-end" if hops == 1 else "chain"), chain
    if first == "410":
        return "410", chain
    if first == "404":
        return "404", chain
    if first == "200":
        return "live", chain
    return "error", chain


def fix_for(url, slug, res, clicks, planned, state, unresolved=""):
    """The answer an address should give instead, as (code, target, why), or None to leave it. unresolved: why Phase 2
    left the address unresolved, if it did."""
    kind, _ = verdict(res)
    same = address("admissions", SAME_COLLEGE[slug]) if slug in SAME_COLLEGE else ""
    if kind == "chain" and res["final_status"] == "200":
        return 301, res["final_url"], f"{res['hops']} redirects in a row: send it straight to the last page"
    if kind in ("chain", "dead-end"):  # ends on a 410 or 404
        ends = f"redirects to a page that answers {res['final_status']}"
        if same:
            return 301, same, f"{ends}: the same college's page here"
        if clicks:
            target, why = fallback(slug, state)
            return 301, target, f"{ends}; {clicks} clicks in 12 months: {why}"
        return 410, "", f"{ends}: answer 410 itself"
    if kind == "ok-301" and planned.startswith("301 ") and norm(res["final_url"]) != norm(planned[4:]):
        return 301, planned[4:], "lands on another page than Phase 2 planned"
    if kind == "410" and clicks:
        target, why = fallback(slug, state)
        return 301, target, f"{clicks} clicks in 12 months: {why}"
    if kind == "404":
        if same:
            return 301, same, "the same college's page here"
        if slug in CONTENT_GAPS:
            return None
        if clicks:
            target, why = fallback(slug, state)
            return 301, target, f"{clicks} clicks in 12 months: {why}"
        if planned == "410":
            return 410, "", "Phase 2 planned a 410"
        if planned.startswith("301 "):
            return 301, planned[4:], "Phase 2 planned this 301"
        if unresolved and not unresolved.startswith(STILL_OPEN):
            return 410, "", f"{unresolved}: gone for good"
    return None


def path_of(url):
    return norm(url).replace(SITE.lower() + "/", "")


def cmd_report():
    pre, acts = pre_cleanup(), phase2_actions()
    urls = {r["url"]: r for r in read_tsv(os.path.join(OUT, "urls.tsv"))}
    status = {r["url"]: r for r in read_tsv(os.path.join(OUT, "live_status.tsv"))}
    missing = [u for u in urls if u not in status]
    fixes, removed_rows, other_rows = {}, [], []

    def add_fix(url, now, fx):
        code, target, why = fx
        fixes[path_of(url)] = {"address": path_of(url), "now": now, "code": code, "target": target, "why": why}

    for slug in sorted({r["slug"] for r in urls.values() if r["source"].startswith("removed")}):
        a, rec = acts[slug], pre[slug]
        planned = "410" if a["action"] == "retire" else f"301 {a['target']}"
        state = state_names(pre, slug)
        row = {"slug": slug, "title": html.unescape(rec["title"]), "location": rec["fields"].get("location", ""),
               "checkpoint": a["checkpoint"], "planned": planned}
        flags, fixtext, clicks, impressions = [], [], 0, 0
        for form in ("admissions", "admission"):
            u = urls[address(form, slug)]
            res = status.get(u["url"])
            kind, answer = verdict(res)
            row[f"{form}_answer"] = answer
            c = int(u["clicks_12m"])
            clicks, impressions = clicks + c, impressions + int(u["impressions_12m"])
            if kind in ("chain", "dead-end", "404", "live", "error"):
                flags.append(f"/{form}/: {kind}")
            fx = fix_for(u["url"], slug, res, c + int(urls[address('admission' if form == 'admissions' else 'admissions', slug)]["clicks_12m"]), planned, state)
            if fx:
                add_fix(u["url"], answer, fx)
                fixtext.append(f"/{form}/: {fx[0]}{' ' + fx[1] if fx[1] else ''}")
        if clicks and planned == "410":
            flags.append("410 with clicks")
        row.update(clicks_12m=clicks, impressions_12m=impressions, flag="; ".join(flags), fix="; ".join(fixtext))
        removed_rows.append(row)

    states, gsc = legacy_states(), search_console()
    # Phase 2's reason for each unresolved address; a college it took for open that FSA's closed-school file lists
    # under its OPEID has closed since
    fsa = {r["opeid"]: r for r in read_csv(os.path.join(ROOT, "data/admissions/review/fsa_closed_schools.csv"))}
    opeids = {r["unitid"]: r["opeid"] for r in read_csv(os.path.join(ROOT, "data/admissions/institutions.csv"))}
    unresolved = {}
    for r in read_csv(os.path.join(ROOT, "data/admissions/redirects/legacy_unresolved.csv")):
        closed = fsa.get(opeids.get(r["unitid"], "") or "-")
        unresolved[r["old_slug"]] = (f"closed {closed['close_date']} (FSA's closed-school file, OPEID {closed['opeid']})"
                                     if closed and r["why"].startswith(STILL_OPEN) else r["why"])
    for url, u in sorted(urls.items()):
        if u["source"].startswith("removed"):
            continue
        res = status.get(url)
        kind, answer = verdict(res)
        clicks = int(u["clicks_12m"])
        # Both forms of an address answer alike, so a click on either counts for both
        both = sum(gsc.get((form, u["slug"]), [0, 0])[0] for form in ("admissions", "admission"))
        fx = fix_for(url, u["slug"], res, both, u["expected"], states.get(u["slug"], ""), unresolved.get(u["slug"], ""))
        flag = kind if kind in ("chain", "dead-end", "404", "live", "error") else ("410 with clicks" if kind == "410" and both else "")
        if flag == "live":
            flag = ""  # an old form that serves a page is fine
        if fx:
            add_fix(url, answer, fx)
        if flag or fx:
            other_rows.append({"url": url, "source": u["source"], "planned": u["expected"], "answer": answer,
                               "clicks_12m": clicks, "impressions_12m": u["impressions_12m"], "flag": flag,
                               "fix": f"{fx[0]}{' ' + fx[1] if fx[1] else ''} ({fx[2]})" if fx else ""})

    link_rows = []
    links_path = os.path.join(OUT, "internal_links.tsv")
    if os.path.exists(links_path):
        for r in read_tsv(links_path):
            if not r.get("url") or r.get("target") == "live":
                continue
            kind, answer = verdict(r if r.get("status") else None)
            if kind == "ok-301" or (kind == "chain" and r["final_status"] == "200"):
                flag, fix = "goes through a redirect", f"link to {r['final_url']}"
            elif kind == "live":
                continue
            else:
                flag, fix = f"ends on {r.get('final_status') or kind}", "remove the link or point it at a live page"
            link_rows.append({"page": r["page"], "link": r["url"], "text": r["text"], "region": r["region"],
                              "answer": answer, "flag": flag, "fix": fix})

    # WordPress pages left under /admissions/ (children of the old "Admissions" page) that the page sitemap lists
    live = live_slugs()
    page_rows = []
    for r in read_csv(OTHER_CSV):
        slug = slug_of(r["url"])
        if not slug or not r["url"].startswith(f"{SITE}/admissions/"):
            continue
        new, old = status.get(r["url"]), status.get(address("admission", slug))
        page_rows.append({"url": r["url"], "sitemap": r["sitemap"], "lastmod": r["lastmod"],
                          "college_page_here": "yes" if slug in live else "",
                          "answer": verdict(new)[1] if new else "not checked",
                          "old_form_answer": verdict(old)[1] if old else "not checked"})

    for name, cols, rows in (("removed_urls.csv", REMOVED_COLS, removed_rows), ("other_addresses.csv", OTHER_COLS, other_rows),
                             ("leftover_pages.csv", PAGE_COLS, page_rows),
                             ("fixes.csv", FIX_COLS, [fixes[k] for k in sorted(fixes)]),
                             ("internal_link_fixes.csv", LINK_COLS, link_rows)):
        with open(os.path.join(OUT, name), "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, cols)
            w.writeheader()
            w.writerows(rows)
    kinds = Counter()
    for slug in {r["slug"] for r in removed_rows}:
        for form in ("admissions", "admission"):
            kinds[(form, verdict(status.get(address(form, slug)))[0])] += 1
    print(f"{len(urls)} addresses, {len(status)} checked, {len(missing)} missing from the results")
    print("removed pages:", sorted(kinds.items()))
    print("flagged removed:", sum(1 for r in removed_rows if r["flag"]), "| other flagged:",
          Counter(r["flag"] for r in other_rows if r["flag"]).most_common())
    print("fixes:", Counter((f["code"], f["why"].split(":")[0][:40]) for f in fixes.values()).most_common())
    print("internal links to fix:", len(link_rows), Counter(r["flag"] for r in link_rows).most_common())
    print("pages left under /admissions/ in the page sitemap:", len(page_rows),
          Counter(r["answer"].split(" > ")[0] for r in page_rows).most_common())


# After the fixes ---------------------------------------------------------------------------------------------------
AFTER = os.path.join(OUT, "after")
AFTER_COLS = ["url", "slug", "form", "source", "expected", "before"]
VERIFY_COLS = ["url", "source", "expected", "before", "after", "result"]


def renamed():
    """old slug -> new slug of the renames the go ran (group renamed)."""
    return {r["slug"]: r["proposed_slug"] for r in read_csv(os.path.join(OUT, "renames.csv")) if r["group"] == "renamed"}


def moved(url, moves):
    """url with a renamed college's old /admissions/ address replaced by its new one."""
    slug = slug_of(url)
    if slug in moves and norm(url) == norm(address("admissions", slug)):
        return address("admissions", moves[slug])
    return url


def moved_chain(chain, moves):
    """A chain ("301 Rank Math > /admissions/x/ | 200") with renamed colleges' old addresses replaced by new ones."""
    return re.sub(r"/admissions/([^/?#\s|]+)/", lambda m: f"/admissions/{moves.get(m.group(1), m.group(1))}/", chain)


def chain_key(chain):
    """A chain without who sent each redirect: a rule that replaces WordPress's guess gives the same answer."""
    return re.sub(r"(\d{3}) [^>|]*>", r"\1 >", chain or "").strip()


def exact(url):
    """An address compared with its query: /admissions/?search=St.%20Thomas and ?search=St. Thomas are the same."""
    return unquote(url or "").replace("+", " ").rstrip("/").lower().replace("/?", "?")


def answers(expected, res):
    """Whether a live check result gives the expected answer: "410", "404", "200", "301 <address>" (one redirect
    straight to it, which answers 200), "same <chain>" (the same hops as before, whoever sends them), "no WordPress
    guess" (any answer but WordPress's redirect to a page with the slug) or "-" (nothing expected)."""
    if not res or res.get("error"):
        return False
    first, chain = res["status"], res["chain"]
    if expected in ("410", "404", "200"):
        return first == expected
    if expected.startswith("301 "):
        return (first in ("301", "308") and int(res["hops"] or 0) == 1 and res["final_status"] == "200"
                and exact(res["final_url"]) == exact(expected[4:]))
    if expected.startswith("same "):
        return chain_key(chain) == chain_key(expected[5:])
    if expected == "no WordPress guess":
        return not chain.startswith("301 WordPress")
    return expected == "-"


def cmd_after():
    urls = {r["url"]: r for r in read_tsv(os.path.join(OUT, "urls.tsv"))}
    before = {r["url"]: r for r in read_tsv(os.path.join(OUT, "live_status.tsv"))}
    fixes = {r["address"]: r for r in read_csv(os.path.join(OUT, "fixes.csv"))}
    moves, live = renamed(), live_slugs()
    rows = {}

    def add(url, source, expected=None):
        if url in rows:
            return
        res = before.get(url)
        chain = (res or {}).get("chain", "") if not (res or {}).get("error") else ""
        if expected is None:
            fx = fixes.get(path_of(url))
            if fx:
                expected = "410" if fx["code"] == "410" else f"301 {moved(fx['target'], moves)}"
            elif not chain:
                expected = "-"
            elif chain.startswith("301 WordPress") and slug_of(res["location"]) not in live:
                # WordPress sent it to a leftover page with the slug; unpublished, the rule or a 404 answers
                expected = "no WordPress guess"
            else:
                expected = f"same {moved_chain(chain, moves)}"
        rows[url] = {"url": url, "slug": slug_of(url), "form": "admissions" if "/admissions/" in url else "admission",
                     "source": source, "expected": expected, "before": chain}

    # The renames first: a new address that an old rule sent to the college's old one now serves the page itself
    for old, new in sorted(moves.items()):
        add(address("admissions", old), "renamed: old address", f"301 {address('admissions', new)}")
        add(address("admission", old), "renamed: old /admission/ form", f"301 {address('admissions', new)}")
        add(address("admissions", new), "renamed: new address", "200")
    for url, u in urls.items():
        add(url, u["source"].split(";")[0].split(" (")[0])
    for r in read_csv(os.path.join(OUT, "leftover_pages.csv")):
        slug = slug_of(r["url"])
        here = slug in live and slug not in moves
        add(address("admissions", slug), "leftover page", "200" if here else None)
        add(address("admission", slug), "leftover page, old form", f"301 {address('admissions', slug)}" if here else None)
    add(f"{SITE}/admissions/", "hub", "200")
    add(f"{SITE}/admissions/harvard/", "live college", "200")

    os.makedirs(AFTER, exist_ok=True)
    with open(os.path.join(AFTER, "urls.tsv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, AFTER_COLS, delimiter="\t")
        w.writeheader()
        w.writerows(rows[u] for u in sorted(rows))
    leftover = {norm(r["url"]) for r in read_csv(os.path.join(OUT, "leftover_pages.csv"))}
    with open(os.path.join(OUT, "pages.txt"), encoding="utf-8") as f:
        pages = [line.strip() for line in f if line.strip() and not line.startswith("#")]
    pages = [moved(p, moves) for p in pages if norm(p) not in leftover]
    with open(os.path.join(AFTER, "pages.txt"), "w", encoding="utf-8") as f:
        f.write("# Pages whose links into /admission(s)/ the check after the cleanup reads (cleanup_qa.py after)\n")
        f.write("\n".join(dict.fromkeys(pages)) + "\n")
    sitemap = read_csv(LIVE_CSV)
    with open(os.path.join(AFTER, "sitemap_colleges.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, list(sitemap[0].keys()))
        w.writeheader()
        w.writerows({**r, "url": moved(r["url"], moves)} for r in sitemap)
    print(f"{len(rows)} addresses to check after the cleanup: "
          f"{Counter(r['source'] for r in rows.values()).most_common()}; "
          f"expected {Counter(r['expected'].split(' ')[0] for r in rows.values()).most_common()}; "
          f"{len(set(pages))} pages to read for links")


def cmd_verify():
    rows = read_tsv(os.path.join(AFTER, "urls.tsv"))
    status = {r["url"]: r for r in read_tsv(os.path.join(AFTER, "live_status.tsv"))}
    out = []
    for r in rows:
        res = status.get(r["url"])
        got = (res or {}).get("error") or (res or {}).get("chain") or "not checked"
        out.append({"url": r["url"], "source": r["source"], "expected": r["expected"], "before": r["before"],
                    "after": got, "result": "ok" if answers(r["expected"], res) else "differs"})
    links = []
    links_path = os.path.join(AFTER, "internal_links.tsv")
    if os.path.exists(links_path):
        for r in read_tsv(links_path):
            if r.get("error") and not r.get("url"):
                links.append(f"{r['page']}: {r['error']}")
            elif r.get("url") and r.get("target") != "live" and not (r.get("status") == "200"):
                links.append(f"{r['page']} links to {r['url']}: {r.get('chain') or r.get('error')}")
    with open(os.path.join(AFTER, "verify.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, VERIFY_COLS)
        w.writeheader()
        w.writerows(out)
    print(f"{len(out)} addresses, {sum(1 for r in out if r['after'] != 'not checked')} checked")
    print("by source:", sorted(Counter((r["source"], r["result"]) for r in out).items()))
    for r in [r for r in out if r["result"] != "ok"][:60]:
        print(f"  differs: {r['url'][len(SITE):]}  expected {r['expected'][:90]}  got {r['after'][:120]}")
    print(f"links that don't reach a page directly: {len(links)}")
    for line in links[:30]:
        print("  " + line)


def cmd_followup():
    """The fixes the live check found not working yet: fixes.csv's rows for every address verify.csv marks differs."""
    fixes = read_csv(os.path.join(OUT, "fixes.csv"))
    key = lambda a: unquote(a or "").strip("/").lower()  # noqa: E731 (both encodings of an address are one address)
    by_key = {key(r["address"]): r for r in fixes}
    out, seen, other = [], set(), []
    for r in read_csv(os.path.join(AFTER, "verify.csv")):
        if r["result"] != "differs":
            continue
        k = key(re.sub(r"^https?://[^/]+/", "", r["url"]))
        if k in by_key and k not in seen:
            seen.add(k)
            out.append(by_key[k])
        elif k not in by_key:
            other.append(r["url"])
    with open(os.path.join(OUT, "followup.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, list(fixes[0].keys()))
        w.writeheader()
        w.writerows(out)
    print(f"{len(out)} fixes to run again; {len(other)} addresses that differ aren't in fixes.csv: {other[:10]}")


if __name__ == "__main__":
    cmd = sys.argv[1] if len(sys.argv) > 1 else ""
    if cmd == "urls":
        cmd_urls()
    elif cmd == "renames":
        cmd_renames()
    elif cmd == "report":
        cmd_report()
    elif cmd == "after":
        cmd_after()
    elif cmd == "verify":
        cmd_verify()
    elif cmd == "followup":
        cmd_followup()
    else:
        sys.exit(__doc__)
