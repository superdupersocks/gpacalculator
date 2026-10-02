"""Phase 2 audit of the existing /admissions/ posts: closed and merged colleges, unmatched posts, duplicates.

    python3 scripts/admissions/audit.py [--first-year 2002] [--out DIR]

Reads Phase 1's match.csv and institutions.csv, the October 1 post export (data/colleges/), and the IPEDS
directory (HD) files of earlier years, which keep a college's record, closing date (CLOSEDAT), year of deletion
(DEATHYR) and successor (NEWID) after it leaves the current file. Writes data/admissions/audit/:

- closed.csv: posts for colleges federal data reports as closed, with the evidence and a recommended treatment
- merged.csv: posts for colleges IPEDS reports as merged into another, with the successor and its post if any
- unmatched.csv: posts without a confident IPEDS match, left unchanged pending identity review, with what the
  older directories show for each
- duplicates.csv: posts that share one IPEDS ID, with how complete each post is
- unconfirmed.csv: matched colleges that federal data flags as inactive or not operating without a closing date
- corrections.csv: Phase 1 matches the older directories contradict, with the college the post really names
- summary.md: the counts

A closure counts as confirmed only with a closing date (CLOSEDAT). An inactive flag (CYACTIVE) or College
Scorecard's not-operating flag (CURROPER) alone also fits a campus that changed owner or merged, and a college
that merely dropped out of IPEDS may have left federal aid and still teach, so those stay unconfirmed. Older
directories confirm an identity only on an exact name in the same state and city. Phase 1's matches are checked
the same way: a post whose exact name and city IPEDS gave to another college, never to the matched one, is
reassigned to that college, and so is a post matched in another city when IPEDS lists the same name plus the
post's city. Changes nothing on the site.
"""
import argparse
import csv
import html
import io
import json
import os
import sys
import zipfile
from collections import defaultdict
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import COLLEGES, OUT, RAW, STATES, norm_name, write_csv  # noqa: E402
from fetch import IPEDS, get, pick_member  # noqa: E402
from match import strip_campus  # noqa: E402

HIST = RAW / "ipeds_history"
KEEP = ("UNITID", "INSTNM", "IALIAS", "CITY", "STABBR", "CLOSEDAT", "NEWID", "DEATHYR", "CYACTIVE")
NOT_SET = {"", "-1", "-2", "-3", ".", "N/A"}
HUB = "https://gpacalculator.net/admissions/"
CLOSED_COLS = ["slug", "url", "title", "location", "unitid", "ipeds_name", "closed_on", "evidence", "treatment"]
MERGED_COLS = ["slug", "url", "title", "location", "unitid", "ipeds_name", "successor_unitid", "successor_name",
               "successor_url", "evidence", "treatment"]
UNMATCHED_COLS = ["slug", "url", "title", "location", "match", "candidates", "older_ipeds", "finding", "treatment"]
DUP_COLS = ["unitid", "ipeds_name", "ipeds_city", "slug", "url", "title", "location", "filled_fields", "published",
            "modified", "older_ipeds"]
CHECK_COLS = ["slug", "url", "title", "location", "unitid", "ipeds_name", "evidence", "treatment"]
FIX_COLS = ["slug", "url", "title", "location", "phase1_method", "phase1_unitid", "phase1_name", "unitid", "ipeds_name",
            "outcome", "evidence"]
EMPTY = {"", "n/a", "na", "-", "none", "null", "not reported", "unavailable"}


def is_set(v):
    return (v or "").strip() not in NOT_SET


def decode(data):
    try:
        return data.decode("utf-8-sig")
    except UnicodeDecodeError:
        return data.decode("latin-1")


def load_history(first, last, download=True):
    """{unitid: {year: record}} from the HD files first..last (missing years are skipped)."""
    hist = defaultdict(dict)
    HIST.mkdir(parents=True, exist_ok=True)
    for year in range(first, last + 1):
        path = HIST / f"HD{year}.zip"
        if not path.exists():
            if not download:
                continue
            try:
                path.write_bytes(get(f"{IPEDS}HD{year}.zip"))
            except Exception as e:  # noqa: BLE001 - a missing year only narrows the history
                print(f"HD{year}: not downloaded ({e})")
                continue
        with zipfile.ZipFile(path) as zf:
            text = decode(zf.read(pick_member(zf, ".csv")))
        n = 0
        for r in csv.DictReader(io.StringIO(text)):
            r = {(k or "").strip().upper(): (v or "").strip() for k, v in r.items()}
            if r.get("UNITID"):
                hist[r["UNITID"]][year] = {k: r.get(k, "") for k in KEEP}
                n += 1
        print(f"HD{year}: {n:,} colleges")
    return hist


def name_index(hist):
    """(state, cleaned name) -> unitids, over every name and alias a college has carried."""
    idx = defaultdict(set)
    for uid, years in hist.items():
        for rec in years.values():
            names = {norm_name(rec["INSTNM"]), strip_campus(norm_name(rec["INSTNM"]))}
            names |= {norm_name(a) for a in rec.get("IALIAS", "").replace("|", ",").split(",") if a.strip()}
            for n in names - {""}:
                idx[(rec["STABBR"], n)].add(uid)
    return idx


def last_seen(years):
    y = max(years)
    return y, years[y]


def closing(years):
    """(year, CLOSEDAT) of the latest record giving a closing date, else None."""
    for y in sorted(years, reverse=True):
        if is_set(years[y].get("CLOSEDAT")):
            return y, years[y]["CLOSEDAT"]
    return None


def successor(uid, hist, current, hops=6):
    """Follow NEWID to the college that absorbed uid: (unitid, name, chain) or None. A college still in the
    current directory counts as merged only if its current record says so."""
    chain, seen = [], {uid}
    while hops:
        hops -= 1
        if uid in current:
            nxt = current[uid].get("merged_into") or ""
        else:
            nxt = next((r["NEWID"] for _, r in sorted(hist.get(uid, {}).items(), reverse=True)
                        if is_set(r.get("NEWID"))), "")
        if not is_set(nxt) or nxt in seen:
            break
        chain.append(nxt)
        seen.add(nxt)
        uid = nxt
    if not chain:
        return None
    name = current[uid]["name"] if uid in current else last_seen(hist[uid])[1]["INSTNM"] if uid in hist else ""
    return uid, name, chain


def older_hits(post, idx, hist):
    """Unitids whose name in some year's directory equals the post's, same state; same city first."""
    name = norm_name(html.unescape(post["title"]))
    state = post["state"]
    hits = set()
    for n in {name, strip_campus(name)}:
        hits |= idx.get((state, n), set())
    city = post["city"].lower()
    same_city = {u for u in hits if any(r["CITY"].lower() == city for r in hist[u].values())}
    return sorted(same_city), sorted(hits - same_city)


def describe(uid, hist):
    years = hist[uid]
    y, rec = last_seen(years)
    first = min(years)
    out = f"IPEDS HD{first}-HD{y}: {rec['INSTNM']} (UNITID {uid}), {rec['CITY']}, {rec['STABBR']}"
    shut = closing(years)
    if shut:
        out += f"; closed {shut[1]} (CLOSEDAT, HD{shut[0]})"
    if is_set(rec.get("DEATHYR")):
        out += f"; deleted from IPEDS {rec['DEATHYR']} (DEATHYR)"
    if is_set(rec.get("NEWID")):
        out += f"; merged into UNITID {rec['NEWID']} (NEWID)"
    return out


def current_evidence(inst):
    """Closure evidence in the current files for a matched college: (closed_on, [evidence]). Only a closing date
    confirms a closure."""
    ev, closed_on = [], ""
    if is_set(inst.get("closed_date")):
        closed_on = inst["closed_date"]
        ev.append(f"IPEDS HD2024: closed {closed_on} (CLOSEDAT)")
    if inst.get("active") == "No":
        ev.append("IPEDS HD2024: not active in the 2024-25 collection year (CYACTIVE)")
    if inst.get("operating") == "No":
        ev.append("College Scorecard, June 10, 2026 release: not currently operating (CURROPER)")
    return closed_on, ev


def filled(fields):
    return sum(1 for v in (fields or {}).values() if str(v or "").strip().lower() not in EMPTY)


def current_names(rec):
    name = norm_name(rec.get("name", ""))
    names = {name, strip_campus(name)}
    names |= {norm_name(a) for a in (rec.get("alias") or "").replace("|", ",").split(",") if a.strip()}
    return names - {""}


def current_index(current):
    """(state, cleaned name) -> unitids in the current directory."""
    idx = defaultdict(set)
    for uid, rec in current.items():
        for n in current_names(rec):
            idx[(rec.get("state", ""), n)].add(uid)
    return idx


def absorbed_index(hist, current):
    """unitid -> the unitids IPEDS says merged into it."""
    into = defaultdict(set)
    for uid, years in hist.items():
        for rec in years.values():
            if is_set(rec.get("NEWID")) and rec["NEWID"] != uid:
                into[rec["NEWID"]].add(uid)
    for uid, rec in current.items():
        if is_set(rec.get("merged_into")):
            into[rec["merged_into"]].add(uid)
    return into


def by_city(p, cur_idx, skip=""):
    """The one current college named as the post plus its city ('Miller Motte College' in Jacksonville ->
    'Miller-Motte College-Jacksonville'), else ''."""
    name = strip_campus(norm_name(html.unescape(p["title"])))
    hits = cur_idx.get((p["state"], f"{name} {norm_name(p['city'])}"), set()) - {skip}
    return next(iter(hits)) if len(hits) == 1 else ""


def recheck(m, p, idx, hist, current, cur_idx):
    """Phase 1's match checked against every name IPEDS has used: (unitid, evidence) of the college the post
    really names, or None when the match stands."""
    uid = m["unitid"]
    rec = current.get(uid, {})
    name = norm_name(html.unescape(p["title"]))
    same_city, elsewhere = older_hits(p, idx, hist)
    ours = uid in same_city + elsewhere or bool({name, strip_campus(name)} & current_names(rec))
    if not ours and len(same_city) == 1:
        u = same_city[0]
        return u, f"{describe(u, hist)}; IPEDS never listed UNITID {uid} under this name"
    if (rec.get("city") or "").lower() != p["city"].lower():
        u = by_city(p, cur_idx, skip=uid)
        if u:
            return u, (f"IPEDS HD2024: {current[u]['name']} (UNITID {u}), {current[u]['city']}: this name plus the "
                       f"post's city, while UNITID {uid} is in {rec.get('city', '')}")
    return None


def identify(p, idx, hist, current, cur_idx, absorbed):
    """What IPEDS says a post without a confident match is: (kind, unitid, finding). kind is renamed,
    consolidated, merged, closed, left, ambiguous, city, or '' when no college carried the name."""
    same_city, _ = older_hits(p, idx, hist)
    if len(same_city) > 1:
        return "ambiguous", "", f"ambiguous: {len(same_city)} colleges carried this name in this city"
    if len(same_city) == 1:
        uid = same_city[0]
        if uid in current:
            now = current[uid]["name"]
            if absorbed.get(uid):
                n = len(absorbed[uid])
                return "consolidated", uid, (f"consolidated: same name, city and state as UNITID {uid}, now {now}, "
                                             f"which {n} other college{'s' if n > 1 else ''} merged into")
            return "renamed", uid, f"renamed: same name, city and state as UNITID {uid}, now {now}"
        if successor(uid, hist, current):
            return "merged", uid, ""
        if closing(hist[uid]):
            return "closed", uid, ""
        return "left", uid, f"left IPEDS: last listed in HD{max(hist[uid])}, no closing date recorded"
    u = by_city(p, cur_idx)
    if u:
        return "city", u, f"name plus city: IPEDS lists UNITID {u} as {current[u]['name']}"
    return "", "", ""


def merged_row(base, uid, name, succ, pages, pending, url, evidence, current, hist):
    s, s_name, chain = succ
    targets = pages.get(s, [])
    if len(targets) == 1:
        to, treatment = url[targets[0]], f"301 to {url[targets[0]]}"
    elif targets:
        to, treatment = "", f"301 to whichever of {', '.join(targets)} survives checkpoint D"
    elif s not in current:
        to, treatment = "", f"retire like a closure: {s_name} is no longer listed either"
        if s in hist:
            evidence += f"; successor: {describe(s, hist)}"
    elif len(pending.get(s, [])) == 1:
        to = url[pending[s][0]]
        treatment = f"301 to {to} once its identity review is approved (the successor's page under a former name)"
    else:
        to, treatment = "", f"retire like a closure; redirect it later if a page for {s_name} is added"
    if "merged into" not in evidence:
        evidence += f"; merged into UNITID {chain[0]} (NEWID)"
    if len(chain) > 1:
        evidence += f"; which later merged into UNITID {' -> '.join(chain[1:])}"
    return {**base, "unitid": uid, "ipeds_name": name, "successor_unitid": s, "successor_name": s_name,
            "successor_url": to, "evidence": evidence, "treatment": treatment}


RETIRE = "retire: unpublish (kept as a draft) and answer 410 Gone"


def classify(posts, matches, current, hist):
    idx, cur_idx, absorbed = name_index(hist), current_index(current), absorbed_index(hist, current)
    url = {p["slug"]: p["url"] for p in posts}
    post = {p["slug"]: p for p in posts}
    closed, merged, unmatched, dups, unconfirmed, fixes = [], [], [], [], [], []
    checked = []
    for m in matches:
        p = post[m["slug"]]
        alt = None
        if m.get("unitid") and m["method"] not in ("none", "review"):
            alt = recheck(m, p, idx, hist, current, cur_idx)
        if alt:
            u, ev = alt
            fix = {"slug": m["slug"], "url": p["url"], "title": html.unescape(m["title"]), "location": m["location"],
                   "phase1_method": m["method"], "phase1_unitid": m["unitid"],
                   "phase1_name": current.get(m["unitid"], {}).get("name", ""), "unitid": u, "evidence": ev}
            if u in current:
                fix.update(ipeds_name=current[u]["name"], outcome="matched to this college instead")
                m = {**m, "unitid": u, "method": "audit"}
            else:
                fix.update(ipeds_name=last_seen(hist[u])[1]["INSTNM"], outcome="no longer listed")
                m = {**m, "unitid": "", "method": "none"}
            fixes.append(fix)
        checked.append(m)
    found = {m["slug"]: identify(post[m["slug"]], idx, hist, current, cur_idx, absorbed)
             for m in checked if m["method"] in ("none", "review")}
    pages, pending = defaultdict(list), defaultdict(list)
    for m in checked:
        if m["method"] not in ("none", "review"):
            pages[m["unitid"]].append(m["slug"])
        elif found[m["slug"]][0] in ("renamed", "consolidated", "city"):
            pending[found[m["slug"]][1]].append(m["slug"])
    for m in checked:
        p = post[m["slug"]]
        base = {"slug": m["slug"], "url": p["url"], "title": html.unescape(m["title"]), "location": m["location"]}
        if m["method"] in ("none", "review"):
            kind, uid, finding = found[m["slug"]]
            if kind in ("merged", "closed"):
                years = hist[uid]
                ev = f"{describe(uid, hist)}; identified by exact name, city and state"
                if kind == "merged":
                    merged.append(merged_row(base, uid, last_seen(years)[1]["INSTNM"], successor(uid, hist, current),
                                             pages, pending, url, ev, current, hist))
                else:
                    closed.append({**base, "unitid": uid, "ipeds_name": last_seen(years)[1]["INSTNM"],
                                   "closed_on": closing(years)[1], "evidence": ev, "treatment": RETIRE})
                continue
            if kind in ("renamed", "consolidated", "city") and pages.get(uid):
                finding += f"; also the IPEDS ID of {', '.join(pages[uid])}"
            same_city, elsewhere = older_hits(p, idx, hist)
            unmatched.append({**base, "match": m["method"], "candidates": m.get("candidates", ""),
                              "older_ipeds": " | ".join(describe(u, hist) for u in same_city + elsewhere),
                              "finding": finding, "treatment": "leave unchanged pending identity review"})
            continue
        uid = m["unitid"]
        inst = current.get(uid, {})
        if len(pages[uid]) > 1:
            others = [u for u in sum(older_hits(p, idx, hist), []) if u != uid]
            dups.append({"unitid": uid, "ipeds_name": inst.get("name", ""), "ipeds_city": inst.get("city", ""),
                         **{k: base[k] for k in ("slug", "url", "title", "location")},
                         "filled_fields": filled(p.get("fields")), "published": p.get("date", ""),
                         "modified": p.get("modified", ""),
                         "older_ipeds": " | ".join(describe(u, hist) for u in others)})
        succ = successor(uid, hist, current)
        if succ:
            merged.append(merged_row(base, uid, inst.get("name", ""), succ, pages, pending, url,
                                     f"IPEDS HD2024: {inst.get('name', '')} (UNITID {uid})", current, hist))
            continue
        closed_on, ev = current_evidence(inst)
        if closed_on:
            closed.append({**base, "unitid": uid, "ipeds_name": inst.get("name", ""), "closed_on": closed_on,
                           "evidence": "; ".join(ev), "treatment": RETIRE})
        elif ev:
            unconfirmed.append({**base, "unitid": uid, "ipeds_name": inst.get("name", ""), "evidence": "; ".join(ev),
                                "treatment": "leave unchanged until a closure is confirmed (no closing date in IPEDS)"})
    lists = {r["slug"]: name for name, rows in (("closed.csv", closed), ("merged.csv", merged),
                                                 ("unmatched.csv", unmatched)) for r in rows}
    for f in fixes:
        if f["slug"] in lists:
            f["outcome"] += f": now in {lists[f['slug']]}"
    return {"closed": closed, "merged": merged, "unmatched": unmatched,
            "duplicates": sorted(dups, key=lambda r: (r["unitid"], r["slug"])), "unconfirmed": unconfirmed,
            "corrections": fixes}


def load_posts(folder):
    posts = []
    for f in sorted(Path(folder).glob("*.json")):
        if f.name.startswith("_"):
            continue
        p = json.loads(f.read_text())
        loc = (p.get("fields") or {}).get("location") or ""
        city, _, state = loc.rpartition(",")
        p.update(city=city.strip(), state=STATES.get(state.strip(), ""))
        posts.append(p)
    return posts


def summary(lists, total, years):
    kinds = defaultdict(int)
    for r in lists["unmatched"]:
        kinds[(r["match"], r["finding"].split(":")[0] if r["finding"] else "no older record with this name")] += 1
    treat = defaultdict(int)
    for r in lists["merged"]:
        treat[r["treatment"].split(" https")[0].split(":")[0].split(";")[0]] += 1
    n = {k: len(v) for k, v in lists.items()}
    lines = [
        "# Admissions audit (Phase 2, checkpoint C)", "",
        f"Out of {total:,} published posts. Older IPEDS directories read: {years}. Nothing here changed the site.", "",
        "| List | Posts |", "| --- | --- |",
        f"| Confirmed closed (`closed.csv`) | {n['closed']:,} |",
        f"| Confirmed merged (`merged.csv`) | {n['merged']:,} |",
        f"| No confident match, unchanged pending identity review (`unmatched.csv`) | {n['unmatched']:,} |",
        f"| Posts sharing one IPEDS ID (`duplicates.csv`) | {n['duplicates']:,} |",
        f"| Flagged as not operating without a closing date, unchanged (`unconfirmed.csv`) | {n['unconfirmed']:,} |",
        f"| Phase 1 matches the older directories contradict (`corrections.csv`) | {n['corrections']:,} |", "",
        "Merged posts by recommended treatment:", "",
        "| Treatment | Posts |", "| --- | --- |",
    ] + [f"| {k} | {v:,} |" for k, v in sorted(treat.items(), key=lambda t: -t[1])] + [
        "", "Unmatched posts by match step and what the older directories show:", "",
        "| Match step | Older directories | Posts |", "| --- | --- | --- |",
    ] + [f"| {m} | {k} | {v:,} |" for (m, k), v in sorted(kinds.items(), key=lambda t: -t[1])]
    return "\n".join(lines) + "\n"


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--first-year", type=int, default=2002)
    ap.add_argument("--last-year", type=int, default=2023)
    ap.add_argument("--no-download", action="store_true", help="use only the HD zips already in raw/")
    ap.add_argument("--out", default=str(OUT / "audit"))
    a = ap.parse_args(argv)
    current = {r["unitid"]: r for r in csv.DictReader(open(OUT / "institutions.csv", encoding="utf-8"))}
    matches = list(csv.DictReader(open(OUT / "match.csv", encoding="utf-8")))
    posts = load_posts(COLLEGES)
    hist = load_history(a.first_year, a.last_year, download=not a.no_download)
    got = sorted({y for years in hist.values() for y in years})
    lists = classify(posts, matches, current, hist)
    out = Path(a.out)
    for name, cols in (("closed", CLOSED_COLS), ("merged", MERGED_COLS), ("unmatched", UNMATCHED_COLS),
                       ("duplicates", DUP_COLS), ("unconfirmed", CHECK_COLS), ("corrections", FIX_COLS)):
        write_csv(out / f"{name}.csv", lists[name], cols)
    span = f"HD{got[0]}-HD{got[-1]} ({len(got)} years)" if got else "none"
    (out / "summary.md").write_text(summary(lists, len(posts), span))
    print((out / "summary.md").read_text())


if __name__ == "__main__":
    main()
