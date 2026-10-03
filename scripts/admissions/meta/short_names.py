#!/usr/bin/env python3
"""Build the short_name field for every published college page.

Input:  a CSV of published college posts (ID, post_name, post_title, u = ipeds_unitid, t = tier), exported with
        `wp eval` (see build_meta_preview.sh), data/admissions/institutions.csv (IPEDS IALIAS in `alias`) and
        data/admissions/meta/short_names_tier_a.csv (hand-checked tier A names).
Output: data/admissions/meta/short_names.csv (slug, post_id, unitid, tier, full_name, short_name, source).

Rules (Digant 2026-10-03 09:02):
  * tier A: the hand-checked name; tier A pages not on that list keep the fallback (checked: no better short form).
  * tiers B and C: the IPEDS alias when it is a clean short form of the name: two or more words, every word taken
    from the full name or a standard abbreviation (Cal, State, Tech, UC, UNC, UMass, SUNY, CUNY, UW...). Acronyms,
    single words, former names and nicknames are skipped, and so is any alias that drops a
    distinctive word of the name (a place, a person's surname), and a short name two pages would share is not used.
  * two pages with the same name get their state: "Blue Ridge Community College (VA)".
  * fallback: the full name without a leading "The" (and without a trailing "Main Campus", which nobody searches).
"""
import csv, html, re, sys, collections
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
ABBR = {'univ', 'cal', 'tech', 'uc', 'unc', 'umass', 'suny', 'cuny', 'uw', 'csu', 'college', 'university',
        'st', 'saint', 'a&m', 'of', 'the', 'at', 'and', 'in', 'poly', 'inst'}


def clean(name):
    n = html.unescape(name).strip()
    n = re.sub(r'\bA\s*&\s*M\b', 'A&M', n)
    n = re.sub(r'\bA\s*&\s*T\b', 'A&T', n)
    return n


def fallback(name):
    n = re.sub(r'^The\s+', '', clean(name))
    n = re.sub(r'[\s-]+Main( Campus)?$', '', n)
    return n


def words(s):
    return [w for w in re.split(r'[\s\-–/,.()]+', s.lower()) if w]


def alias_candidates(alias):
    for part in re.split(r'\||;|,|/|\s{2,}|\s+or\s+', alias):
        part = re.sub(r'^The\s+', '', part.strip(' .'))
        if part:
            yield clean(part)


GENERIC = ABBR | {'community', 'technical', 'institute', 'school', 'center', 'campus', 'system', 'district', 'inc',
                  'main', 'for', 'technology', 'polytechnic', 'colleges', 'technical'}
# words a standard abbreviation may stand in for
COVERS = {'california': {'cal', 'csu', 'uc'}, 'massachusetts': {'umass'}, 'saint': {'st'}, 'technology': {'tech'},
          'polytechnic': {'poly'}}


def good_alias(cand, full):
    w = words(cand)
    if len(w) < 2 or len(cand) >= len(full) - 3 or cand == cand.lower():
        return False
    if re.fullmatch(r'[A-Z&.\- ]+', cand):  # acronyms and all-caps strings
        return False
    names = words(full)
    fw = set(names) | {re.sub(r"'s$", '', x) for x in names}
    # an abbreviation counts only for a word the name has ("Stanly Community College" is not "Stanly Tech"), and a
    # generic word only when the name has it ("University of Mary Washington" is not "Mary Washington College")
    stands = {'cal': 'california', 'uc': 'california', 'csu': 'california', 'umass': 'massachusetts',
              'st': 'saint', 'tech': ('technology', 'technical', 'tech'), 'poly': ('polytechnic', 'poly')}
    for x in w:
        if x in fw:
            continue
        need = stands.get(x)
        if need is None or not any(n in fw for n in ((need,) if isinstance(need, str) else need)):
            return False
    if w[-1] in ('community', 'technical', 'of', 'at', 'and'):
        return False
    # "Lakeland Community College" isn't "Lakeland College" (a different school): a community college keeps the word
    if 'community' in names and 'college' in w and 'community' not in w:
        return False
    # it still reads as a school: a bare place ("Central Florida", "Staten Island", "Blue Ridge") or a person's name
    # could be anything, so the alias has to carry a school word, a system prefix or the name's hyphenated form
    marks = {'college', 'university', 'state', 'tech', 'a&m', 'institute', 'mines', 'uc', 'umass', 'suny', 'cuny', 'cal'}
    if not (marks & set(w) or re.search(r'[A-Za-z][-–][A-Z]', cand)):
        return False
    # it keeps every distinctive word of the name (initials aside), in the name's order and starting where the name
    # starts, so "Concordia University Nebraska" can't become "Concordia College", "Washington State College of
    # Ohio" can't become "Washington State" and "Seattle Central College" can't become "Central Seattle"
    def distinctive(seq):
        out = []
        for x in seq:
            x = {'cal': 'california', 'uc': 'california', 'csu': 'california', 'umass': 'massachusetts',
                 'st': 'saint', 'tech': 'technology'}.get(x, x)
            if len(x) > 1 and x not in GENERIC:
                out.append(x)
        return out
    dn, dc = distinctive(names), distinctive(w)
    if not dc or dn[:1] != dc[:1] or set(dn) - set(dc):
        return False
    it = iter(dn)
    return all(x in it for x in dc)


def main(posts_csv, out_csv):
    inst = {r['unitid']: r for r in csv.DictReader(open(ROOT / 'data/admissions/institutions.csv'))}
    tier_a = {r['slug']: r['short_name'] for r in csv.DictReader(open(ROOT / 'data/admissions/meta/short_names_tier_a.csv'))}
    rows = []
    for p in csv.DictReader(open(posts_csv)):
        full = clean(p['post_title'])
        short, source = fallback(full), 'fallback'
        if p['t'] == 'A':
            if p['post_name'] in tier_a:
                short, source = tier_a[p['post_name']], 'hand-checked'
            else:
                source = 'fallback (hand-checked)'
        else:
            alias = inst.get(p['u'], {}).get('alias', '')
            cands = [c for c in alias_candidates(alias) if good_alias(c, full)]
            if cands:
                short, source = min(cands, key=len), 'IPEDS alias'
        rows.append(dict(slug=p['post_name'], post_id=p['ID'], unitid=p['u'], tier=p['t'], full_name=full,
                         short_name=short, source=source))
    # a short name two pages share is ambiguous: an alias goes back to the fallback, and pages that share a full name
    # (Blue Ridge Community College in NC and VA) add their state
    count = collections.Counter(r['short_name'].lower() for r in rows)
    for r in rows:
        if count[r['short_name'].lower()] > 1 and r['source'] == 'IPEDS alias':
            r['short_name'], r['source'] = fallback(r['full_name']), 'fallback (alias shared)'
    state = {r['slug']: r['state'] for r in csv.DictReader(open(ROOT / 'data/admissions/tiering/tiers.csv'))}
    count = collections.Counter(r['short_name'].lower() for r in rows)
    for r in rows:
        if count[r['short_name'].lower()] > 1 and state.get(r['slug']):
            r['short_name'], r['source'] = '%s (%s)' % (r['short_name'], state[r['slug']]), 'name + state'
    with open(out_csv, 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(rows[0]))
        w.writeheader()
        w.writerows(sorted(rows, key=lambda r: r['slug']))
    print(collections.Counter(r['source'] for r in rows))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else ROOT / 'data/admissions/meta/short_names.csv')
