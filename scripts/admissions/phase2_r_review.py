#!/usr/bin/env python3
"""Identity review of the live /admissions/ pages E left unchanged (Admissions Phase 2, after E).

    python3 scripts/admissions/phase2_r_review.py

Phase 1 found no confident IPEDS match for these pages, and the go of 05:58 kept them unchanged pending review. The
audit (audit.py, unmatched.csv and merged.csv) already traced most of them through the IPEDS directories back to
2002: a college whose exact name, city and state an older directory gives to a UNITID that IPEDS still lists under a
new name, a chain of UNITIDs linked by NEWID, a college that left IPEDS. This turns that evidence into an outcome per
page, by the rules E and C already use:

- the college is confirmed and IPEDS 2024 reports it: E's fresh fields (checkpoint R, like E);
- the college closed (a closing date in IPEDS), or merged into a college that has a page here: 410 or 301 (R, like C);
- the college now reports as a campus of one of the six colleges S adds: 301 to that page (P, after S);
- several of our pages now report as one college that has no page here: a page for that college and a 301 from
  each (N, waits for Digant's word, like S);
- a college that left IPEDS, or that no IPEDS directory since 2002 lists, and that Federal Student Aid's closed-school
  file lists as closed (by its OPEID from IPEDS, else by exact name, city and state): 410 (R, like C). E's held pages
  that IPEDS 2024 lists but College Scorecard doesn't, or calls closed, get the same check;
- everything else stays unchanged, with what would settle it (the college's own site).

FSA's file and the colleges' older IPEDS records with their OPEIDs come from scripts/admissions/review_sources.py, which
runs on GitHub (data/admissions/review/); without them the FSA check finds nothing.

Writes data/admissions/audit/:
- phase2_r_review.csv: every page reviewed, with the college it now is, the outcome and why
- phase2_r_import.csv: E's fields for checkpoint R's pages (same columns as phase2_e_import.csv)
- phase2_r_actions.csv: checkpoints R, P and N (same columns as phase2_cd_actions.csv)
- phase2_n_new.csv, phase2_n_pages.csv: the pages N adds and their E fields (as phase2_s_new.csv, phase2_s_pages.csv)
Changes nothing on the site.
"""
import csv
import re
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import phase2_e_import as e  # noqa: E402

AUDIT = e.AUDIT
SITE = "https://gpacalculator.net/admissions/"
REVIEW_COLUMNS = ["slug", "url", "title", "location", "finding", "unitid", "ipeds_name", "ipeds_city", "ipeds_state",
                  "outcome", "checkpoint", "target", "reason"]
ACTION_COLUMNS = ["checkpoint", "slug", "action", "target", "reason"]

# Colleges that several of our pages now report as, under a name none of our pages carries (checkpoint N): IPEDS
# UNITID -> (slug, title) of the page to add. Each formed when the colleges or campuses our pages describe merged.
NEW_PAGES = {
    "129367": ("connecticut-state-community-college", "Connecticut State Community College"),
    "101161": ("coastal-alabama-community-college", "Coastal Alabama Community College"),
    "177995": ("metropolitan-community-college-kansas-city", "Metropolitan Community College-Kansas City"),
    "231165": ("vermont-state-university", "Vermont State University"),
    "192448": ("long-island-university", "Long Island University"),
    "235237": ("pierce-college-district", "Pierce College District"),
    "214111": ("montgomery-county-community-college", "Montgomery County Community College"),
}

# Judgment calls on the audit's evidence: slug -> ("unitid", UNITID, why) to settle which college a page is, or
# ("hold", why) to leave it unchanged.
MANUAL = {
    "medvance-institute-baton-rouge": (
        "unitid", "439738", "the Medvance Institute-Baton Rouge IPEDS lists until it became Fortis College-Baton "
        "Rouge; the other record with that name closed in 2004"),
    "pennsylvania-state-university-main-campus": (
        "unitid", "214777", "IPEDS names UNITID 214777 Pennsylvania State University-Main Campus; the other two "
        "records with this name are World Campus and a record that merged into 214777"),
    "ats-institute-of-technology": (
        "hold", "its IPEDS ID now belongs to MDT College of Health Sciences in Chicago, so whether the Ohio school "
        "still operates needs checking"),
    "horizon-college-san-diego": (
        "hold", "IPEDS now lists the college as Horizon University in Indianapolis, while this page names San Diego"),
    "arizona-college-of-allied-health": (
        "hold", "its old IPEDS ID is now Arizona College of Nursing-Tempe, and Arizona College-Glendale has an ID of "
        "its own, so which one this Glendale page means needs checking"),
    "national-college-lexington": (
        "hold", "its IPEDS ID now reports American National University's Pikeville campus, and the Lexington campus "
        "has no record of its own"),
    "the-university-of-texas-at-brownsville": (
        "hold", "its IPEDS ID now belongs to Texas Southmost College, a different college"),
    "santa-barbara-business-college-ventura": (
        "hold", "its IPEDS ID now belongs to California Aeronautical University in Bakersfield"),
    "bethel-college": (
        "hold", "two IPEDS records carried this name in Hampton: one listed only in 2009, and Ascent College, now in "
        "Gainesville; which one this page means needs checking"),
}
# Ivy Tech's regional colleges left IPEDS after 2011, when Ivy Tech became one college; IPEDS lists a campus of Ivy
# Tech Community College (UNITID 150987) in each region's city today.
IVY_TECH_REGIONS = {"ivy-tech-community-college-east-central", "ivy-tech-community-college-northcentral",
                    "ivy-tech-community-college-northeast", "ivy-tech-community-college-northwest",
                    "ivy-tech-community-college-south-central", "ivy-tech-community-college-southeast",
                    "ivy-tech-community-college-southwest", "ivy-tech-community-college-wabash-valley"}

SEGMENT = re.compile(r"IPEDS HD(\d{4})-HD(\d{4}): (.*) \(UNITID (\d+)\), ([^,]*), ([A-Z]{2})(.*)$")


def segments(older):
    """The audit's older_ipeds text as records: name, unitid, city, state, years, closed, newid."""
    out = []
    for part in filter(None, (p.strip() for p in older.split(" | "))):
        m = SEGMENT.match(part)
        if not m:
            raise SystemExit(f"can't read the audit's older_ipeds text: {part!r}")
        rest = m.group(7)
        closed = re.search(r"closed (\S+) \(CLOSEDAT", rest)
        newid = re.search(r"merged into UNITID (\d+) \(NEWID\)", rest)
        out.append({"first": int(m.group(1)), "last": int(m.group(2)), "name": m.group(3), "unitid": m.group(4),
                    "city": m.group(5), "state": m.group(6), "closed": closed.group(1) if closed else "",
                    "newid": newid.group(1) if newid else ""})
    return out


def chain_end(segs):
    """The one UNITID every record in segs leads to through NEWID, or '' when they lead to more than one."""
    succ = {s["unitid"]: s["newid"] for s in segs}
    ends = set()
    for s in segs:
        u, seen = s["unitid"], set()
        while succ.get(u) and u not in seen:
            seen.add(u)
            u = succ[u]
        ends.add(u)
    return ends.pop() if len(ends) == 1 else ""


REVIEW = e.DATA / "review"
LEFT = "left IPEDS without a closing date: check FSA's closed-school list"
NEVER = "no IPEDS directory since 2002 lists this name in this state"
ABBREVIATIONS = {"st": "saint", "ft": "fort", "mt": "mount"}
SPELLED_OUT = {"purdue global": "purdue university global"}  # our titles' short form -> FSA's


def norm(s):
    """A name or city for comparison: lower case, '&' as 'and', no punctuation, 'the', 'inc' or 'campus', St. as
    Saint, and the names FSA spells out (SPELLED_OUT)."""
    words = re.sub(r"[^a-z0-9 ]", " ", (s or "").lower().replace("&", " and ")).split()
    out = " ".join(ABBREVIATIONS.get(w, w) for w in words if w not in ("the", "inc", "campus"))
    for short, full in SPELLED_OUT.items():
        out = re.sub(rf"\b{short}\b", full, out)
    return out


def place_names(names, city):
    """The forms a college's names may take in FSA's file: each as is, and without the city at its end ("Bryan
    University Topeka" is FSA's "Bryan University" in Topeka)."""
    c, out = norm(city), []
    for n in map(norm, names):
        out += [n, n[:-len(c)].strip() if c and n.endswith(" " + c) else ""]
    return [x for x in dict.fromkeys(out) if x]


def iso(date):
    """A closing date as YYYY-MM-DD (FSA's may read MM/DD/YYYY or YYYY-MM-DD...), else as given."""
    m = re.match(r"(\d{1,2})/(\d{1,2})/(\d{4})", date or "")
    if m:
        return f"{m.group(3)}-{int(m.group(1)):02d}-{int(m.group(2)):02d}"
    m = re.match(r"\d{4}-\d{2}-\d{2}", date or "")
    return m.group(0) if m else (date or "")


def fsa_closures():
    """FSA's closed-school file by OPEID and by (name, city, state); both empty until review_sources.py has run."""
    by_ope, by_place = {}, {}
    path = REVIEW / "fsa_closed_schools.csv"
    if path.exists():
        for r in e.read(path):
            r = {**r, "close_date": iso(r["close_date"]), "state": r["state"].strip().upper()}
            by_ope.setdefault(r["opeid"], []).append(r)
            by_place.setdefault((norm(r["name"]), norm(r["city"]), r["state"]), []).append(r)
    return by_ope, by_place


def real_opeid(o):
    """An OPEID, not IPEDS's code for none (-1 or -2, which reads 00000001 or 00000002 zero-filled)."""
    return bool(o) and o[:6].strip("0") != ""


def latest_opeid(uids, hist):
    """(OPEID, first year) of the newest IPEDS record among these UNITIDs that has an OPEID: the college's federal
    ID when it left IPEDS, and the first directory that gives it that ID. Older OPEIDs don't count: a college
    usually takes a new one when another college takes it over, and FSA then lists the old one as closed."""
    spans = [h for u in uids for h in hist.get(u, []) if real_opeid(h["opeid"])]
    if not spans:
        return "", 0
    last = max(spans, key=lambda h: int(h["last_year"]))
    return last["opeid"], min(int(h["first_year"]) for h in hist[last["unitid"]] if h["opeid"] == last["opeid"])


def fsa_closed(opeids, place, since, by_ope, by_place):
    """(closing date, evidence) when FSA's closed-school file lists the college as closed, else ('', why not).

    opeids: the college's current or last OPEID from IPEDS, looked up first; place: (names, city, two-letter state),
    matched exactly (after norm and place_names) when FSA doesn't list the OPEID, and the state an OPEID's listing must
    be in. since:
    a year IPEDS lists the college under this OPEID (or open); a closing date more than a year before it belongs to
    something else.
    """
    found, how = [r for o in opeids for r in by_ope.get(o, [])], "OPEID"
    if not found and place:
        found = [r for n in place_names(place[0], place[1]) for r in by_place.get((n, norm(place[1]), place[2]), [])]
        how = "name, city and state"
    if not found:
        if not (by_ope or by_place):
            return "", "FSA's closed-school file hasn't been downloaded (review_sources.py)"
        return "", (f"FSA's closed-school file doesn't list OPEID {', '.join(opeids)}" if opeids else
                    "FSA's closed-school file lists no school with this name in this city and state")
    r = max(found, key=lambda x: x["close_date"])
    said = f"FSA's closed-school file lists OPEID {r['opeid']} ({r['name']}, {r['city']}, {r['state']})"
    if not re.match(r"\d{4}-\d{2}-\d{2}$", r["close_date"]):
        return "", f"{said} with a closing date that can't be read: {r['close_date']!r}"
    if place and r["state"] != place[2]:
        return "", f"{said}, not in {place[2]}"
    if since and int(r["close_date"][:4]) < since - 1:
        return "", f"{said} closed {r['close_date']}, but IPEDS lists the college in HD{since}"
    return r["close_date"], f"closed {r['close_date']} ({said}, matched by {how})"


def identity(slug, finding, older, merged, match):
    """(UNITID, evidence) for the college a page names now, or ('', why not)."""
    if slug in MANUAL and MANUAL[slug][0] == "unitid":
        return MANUAL[slug][1], MANUAL[slug][2]
    if slug in merged:
        r = merged[slug]
        return r["successor_unitid"], f"merged into {r['successor_name']} (IPEDS NEWID, audit)"
    if not finding:
        if match["method"] == "fuzzy" and match["unitid"]:
            return match["unitid"], f"near-exact name in the same city and state: {match['ipeds_name']}"
        return "", "no IPEDS directory since 2002 lists this name in this state"
    kind = finding.split(":")[0]
    if kind in ("renamed", "consolidated", "name plus city"):
        u = re.search(r"UNITID (\d+)", finding).group(1)
        return u, finding
    if kind == "ambiguous":
        segs = segments(older)
        u = chain_end(segs)
        if not u:
            return "", finding
        last = next(x for x in segs if x["unitid"] == u)
        closed = f"; UNITID {u} closed {last['closed']} (IPEDS CLOSEDAT, HD{last['last']})" if last["closed"] else ""
        return u, f"each IPEDS record with this name and city leads to UNITID {u} (NEWID){closed}"
    if kind == "left IPEDS":
        return "", finding
    raise SystemExit(f"{slug}: unexpected audit finding {finding!r}")


def outcome(slug, uid, inst, page_of, s_pages):
    """(outcome, checkpoint, target UNITID or '', reason) for a page whose college is UNITID uid."""
    if uid in NEW_PAGES:
        return "301", "N", uid, f"now part of {inst[uid]['name']}, which gets a page"
    if uid in s_pages:
        return "301", "P", uid, f"now part of {inst[uid]['name']}, whose page S adds"
    i = inst.get(uid)
    if i is None:
        return "hold", "", "", f"UNITID {uid} isn't in IPEDS 2024"
    if i["closed_date"]:
        return "retire", "R", "", f"{i['name']} closed {i['closed_date']} (IPEDS)"
    if uid in page_of:
        return "301", "R", uid, f"{i['name']} already has a page"
    if i["operating"] == "No":
        return "hold", "", "", "College Scorecard (June 2026) says it no longer operates"
    if i["operating"] != "Yes":
        return "hold", "", "", "open in IPEDS 2024 but missing from College Scorecard's June 2026 release"
    if not (i["control"] and i["level"]):
        parent = e.parent_of(uid, inst)
        if parent in s_pages:
            return "301", "P", parent, f"a campus {inst[parent]['name']} reports for, whose page S adds"
        if parent in page_of:
            return "301", "R", parent, f"a campus {inst[parent]['name']} reports for (IPEDS)"
        return "hold", "", "", "no IPEDS 2024 record of its own, and the college that reports for it has no page"
    return "import", "R", "", ""


def review(pages, unmatched, merged, match, inst, page_of, s_pages):
    """One review row per page, plus the E rows R imports need (slug -> UNITID)."""
    rows, imports = [], {}
    for slug in pages:
        u = unmatched.get(slug, {})
        m = match[slug]
        row = {"slug": slug, "url": f"{SITE}{slug}/", "title": m["title"], "location": m["location"],
               "finding": u.get("finding") or ("merged" if slug in merged else m["method"])}
        if slug in MANUAL and MANUAL[slug][0] == "hold":
            row.update(outcome="hold", checkpoint="", target="", reason=MANUAL[slug][1])
            rows.append(row)
            continue
        if slug in IVY_TECH_REGIONS:
            row.update(unitid="150987", ipeds_name=inst["150987"]["name"], ipeds_city=inst["150987"]["city"],
                       ipeds_state="IN", outcome="301", checkpoint="P", target=f"{SITE}{s_pages['150987']}/",
                       reason="Ivy Tech's regional colleges became campuses of one college after 2011; IPEDS lists "
                              "an Ivy Tech campus in this city under Ivy Tech Community College")
            rows.append(row)
            continue
        uid, evidence = identity(slug, u.get("finding", ""), u.get("older_ipeds", ""), merged, m)
        if not uid:
            row.update(outcome="hold", checkpoint="", target="",
                       reason=("left IPEDS without a closing date: check FSA's closed-school list"
                               if evidence.startswith("left IPEDS") else evidence))
            rows.append(row)
            continue
        i = inst.get(uid, {})
        row.update(unitid=uid, ipeds_name=i.get("name", ""), ipeds_city=i.get("city", ""),
                   ipeds_state=i.get("state", ""))
        what, cp, target, why = outcome(slug, uid, inst, page_of, s_pages)
        if uid not in inst and "(IPEDS CLOSEDAT" in evidence:
            what, cp, target, why = "retire", "R", "", ""
        if slug in merged and what == "import":  # the college it merged into is confirmed below, or it waits
            what, cp, target, why = "hold", "", "", "the college it merged into has no page yet"
        page = NEW_PAGES.get(target, (None,))[0] or s_pages.get(target) or page_of.get(target)
        row.update(outcome=what, checkpoint=cp, target=f"{SITE}{page}/" if target else "",
                   reason="; ".join(x for x in (evidence, why) if x))
        if what == "import":
            imports[slug] = uid
        rows.append(row)
    # A college confirmed for two pages: the page whose old name matches IPEDS's keeps it, the rest wait
    twice = Counter(imports.values())
    for r in rows:
        if r["outcome"] == "import" and twice[r["unitid"]] > 1:
            r.update(outcome="hold", checkpoint="", reason=r["reason"] + "; another page here is the same college")
            del imports[r["slug"]]
    return rows, imports


def ipeds_history():
    """review_sources.py's older IPEDS records by UNITID (with their OPEIDs); empty until it has run."""
    hist = {}
    if (REVIEW / "ipeds_history.csv").exists():
        for h in e.read(REVIEW / "ipeds_history.csv"):
            hist.setdefault(h["unitid"], []).append(h)
    return hist


def fsa_review(rows, unmatched, match, inst, held, hist, by_ope, by_place):
    """FSA's closed-school file for the review's colleges that left IPEDS or that no directory since 2002 lists
    (updates rows in place), and for E's held pages that IPEDS 2024 lists but College Scorecard doesn't or calls closed
    (returns their rows). A closure FSA confirms retires the page in R."""
    for r in rows:
        if r["outcome"] != "hold" or r["reason"] not in (LEFT, NEVER):
            continue
        if r["reason"] == LEFT:
            segs = segments(unmatched[r["slug"]]["older_ipeds"])
            last = max(segs, key=lambda s: s["last"])
            ope, since = latest_opeid([s["unitid"] for s in segs], hist)
            date, why = fsa_closed([ope] if ope else [], ([last["name"], r["title"]], last["city"], last["state"]),
                                   since or last["first"], by_ope, by_place)
            before = f"left IPEDS after HD{last['last']} without a closing date"
        else:
            city, _, state = r["location"].rpartition(", ")
            date, why = fsa_closed([], ([r["title"], r["slug"].replace("-", " ")], city, e.STATES.get(state, "")), 0,
                                   by_ope, by_place)
            before = NEVER
        r.update(outcome="retire", checkpoint="R", target="", reason=f"{before}; {why}") if date else \
            r.update(reason=f"{before}; {why}")
    out = []
    for h in held:
        if not h["why"].startswith(("open in IPEDS 2024", "not operating according to College Scorecard")):
            continue
        i = inst.get(h["unitid"], {})
        ope = i.get("opeid", "")
        date, why = fsa_closed([ope] if real_opeid(ope) else [], ([i.get("name", ""), h["post_title"]],
                               i.get("city", ""), i.get("state", "")), 2024, by_ope, by_place)
        out.append({"slug": h["slug"], "url": f"{SITE}{h['slug']}/", "title": h["post_title"],
                    "location": match.get(h["slug"], {}).get("location", ""), "finding": "E held",
                    "unitid": h["unitid"], "ipeds_name": i.get("name", ""), "ipeds_city": i.get("city", ""),
                    "ipeds_state": i.get("state", ""), "outcome": "retire" if date else "hold",
                    "checkpoint": "R" if date else "", "target": "", "reason": f"{h['why']}; {why}"})
    return out


def main():
    inst = e.institutions()
    years = e.source_years()
    read = e.read
    match = {r["slug"]: r for r in read(e.DATA / "match.csv")}
    done = ({r["slug"] for r in read(AUDIT / "phase2_cd_actions.csv")}
            | {r["slug"] for r in read(AUDIT / "phase2_e_import.csv")}
            | {r["slug"] for r in read(AUDIT / "phase2_e_held.csv")}
            | {r["slug"] for r in read(AUDIT / "phase2_c_held.csv")})
    pages = sorted(s for s in match if s not in done)
    unmatched = {r["slug"]: r for r in read(AUDIT / "unmatched.csv")}
    merged = {r["slug"]: r for r in read(AUDIT / "merged.csv")}
    page_of = {r["ipeds_unitid"]: r["slug"] for r in read(AUDIT / "phase2_e_import.csv")}
    s_titles = {r["slug"] for r in read(AUDIT / "phase2_s_new.csv")}
    s_pages = {r["ipeds_unitid"]: r["slug"] for r in read(AUDIT / "phase2_s_pages.csv") if r["slug"] in s_titles}
    for u in NEW_PAGES:
        if u in page_of or u in s_pages:
            raise SystemExit(f"UNITID {u} already has a page")

    rows, imports = review(pages, unmatched, merged, match, inst, page_of, s_pages)
    # Pages whose college R confirms can take redirects in R (Northwood's Texas campus to its Michigan page)
    confirmed = {uid: slug for slug, uid in imports.items()}
    for r in rows:
        if r["outcome"] == "hold" and r.get("unitid") in confirmed and r["finding"] == "merged":
            r.update(outcome="301", checkpoint="R", target=f"{SITE}{confirmed[r['unitid']]}/",
                     reason=r["reason"].replace("the college it merged into has no page yet",
                                                "R imports that college's page"))

    rows += fsa_review(rows, unmatched, match, inst, read(AUDIT / "phase2_e_held.csv"), ipeds_history(),
                       *fsa_closures())

    import_rows = [e.row_for({"slug": s, "title": match[s]["title"]}, inst[u], years) for s, u in sorted(imports.items())]
    new = [{"slug": s, "post_title": t} for s, t in NEW_PAGES.values()]
    new_rows = [e.row_for({"slug": s, "title": t}, inst[u], years) for u, (s, t) in NEW_PAGES.items()]
    actions = [{"checkpoint": r["checkpoint"], "slug": r["slug"], "action": r["outcome"], "target": r["target"],
                "reason": r["reason"]} for r in rows if r["outcome"] in ("301", "retire")]
    actions.sort(key=lambda a: (a["checkpoint"], a["slug"]))
    for name, cols, data in (("phase2_r_review.csv", REVIEW_COLUMNS, rows),
                             ("phase2_r_import.csv", e.COLUMNS, import_rows),
                             ("phase2_r_actions.csv", ACTION_COLUMNS, actions),
                             ("phase2_n_new.csv", ["slug", "post_title"], new),
                             ("phase2_n_pages.csv", e.COLUMNS, new_rows)):
        with open(AUDIT / name, "w", newline="", encoding="utf-8") as f:
            w = csv.DictWriter(f, fieldnames=cols, lineterminator="\n", extrasaction="ignore")
            w.writeheader()
            w.writerows(data)
    n = Counter((r["outcome"], r["checkpoint"]) for r in rows)
    fsa = sum(r["outcome"] == "retire" and "FSA's closed-school file" in r["reason"] for r in rows)
    print(f"{len(rows)} pages: import {n[('import', 'R')]}; R: {n[('retire', 'R')]} x 410 ({fsa} closed per FSA), "
          f"{n[('301', 'R')]} x 301; P: {n[('301', 'P')]} x 301; N: {len(new)} new pages, {n[('301', 'N')]} x 301; "
          f"hold {n[('hold', '')]}")


if __name__ == "__main__":
    main()
