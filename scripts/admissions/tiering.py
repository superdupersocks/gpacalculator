#!/usr/bin/env python3
"""Tiers for the /admissions/ college pages (Digant's plan of 2026-10-03, step 2).

    python scripts/admissions/tiering.py      write data/admissions/tiering/tiers.csv and summary.md

Each published college page gets a tier from the figures it shows:

- A, complete: an average GPA the college reported in its Common Data Set (cited), the fall acceptance rate, and SAT or
  ACT scores (middle 50%), or none because IPEDS lists the college as test blind.
- B, partial: one or two of those three.
- C, open admission or none: IPEDS lists the college as open admission, or the page has none of the three.

and a recommendation: tiers A and B stay in search (index); tier C comes out (noindex) unless the page had a click in
the last 12 months, counting its old addresses (traffic overrides), or shows a GPA the college reported.

The figures come from the files that set them on the live site, keyed by the address each page had then: the Phase 2
E, R, S and N imports (data/admissions/audit/), H's 53 emptied pages and B2's cited GPAs; Phase 3's names give the
titles. The cleanup's renames (data/admissions/cleanup_qa/renames.csv) move them to each page's address now, and the
colleges sitemap (data/admissions/live_checks/sitemap_colleges.csv) lists the published pages. Search Console's clicks
and impressions (data/admissions/search_console/pages_12m.csv, 2025-10-03 to 2026-10-02, from Windsor.ai) count for a
page from both forms of its address (/admissions/ and the old /admission/), its address before a rename, and every
old address the cleanup's live check found redirecting to it in one step.
"""
import csv
import os
import re
import sys
from collections import Counter, defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SITE = "https://gpacalculator.net"
AUDIT = os.path.join(ROOT, "data/admissions/audit")
QA = os.path.join(ROOT, "data/admissions/cleanup_qa")
OUT = os.path.join(ROOT, "data/admissions/tiering")
IMPORTS = ["phase2_e_import.csv", "phase2_r_import.csv", "phase2_s_pages.csv", "phase2_n_pages.csv"]
SAT = ["sat_math_25", "sat_math_75", "sat_reading_25", "sat_reading_75", "sat_composite_25", "sat_composite_75"]
ACT = ["act_composite_25", "act_composite_75"]
COLS = ["slug", "title", "state", "tier", "why", "open_admission", "cited_gpa", "acceptance_rate", "sat", "act",
        "clicks_12m", "impressions_12m", "addresses_counted", "recommendation", "reason", "old_slug"]


def read_csv(path, delimiter=","):
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f, delimiter=delimiter))


def slug_of(url):
    m = re.match(r"https?://(?:www\.)?gpacalculator\.net/admissions?/([^/?#]+)", url or "")
    return m.group(1).lower() if m else ""


def form_of(url):
    return "admission" if re.match(r"https?://(?:www\.)?gpacalculator\.net/admission/", url or "") else "admissions"


def filled(row, keys):
    return any((row.get(k) or "").strip() for k in keys)


def fields():
    """old address (slug) -> the fields the imports set, a later import's row replacing an earlier one's."""
    rows = {}
    for name in IMPORTS:
        for r in read_csv(os.path.join(AUDIT, name)):
            rows[r["slug"]] = r
    for r in read_csv(os.path.join(AUDIT, "phase2_h_blank.csv")):
        # H emptied every field E manages but location and owning
        rows[r["slug"]] = {"slug": r["slug"], "post_title": r["post_title"], "college_state": r.get("college_state", ""),
                           "emptied": "yes"}
    return rows


def traffic():
    """(form, slug) -> [clicks, impressions] over the 12 months, every variant of the address together."""
    out = defaultdict(lambda: [0, 0])
    for r in read_csv(os.path.join(ROOT, "data/admissions/search_console/pages_12m.csv")):
        slug = slug_of(r["page"])
        if slug:
            out[(form_of(r["page"]), slug)][0] += int(r["clicks"])
            out[(form_of(r["page"]), slug)][1] += int(r["impressions"])
    return out


def redirected(live):
    """college page (slug now) -> old addresses that redirect to it in one step on the live site, as (form, slug)."""
    out = defaultdict(set)
    path = os.path.join(QA, "after/live_status.tsv")
    if not os.path.exists(path):
        path = os.path.join(QA, "live_status.tsv")
    for r in read_csv(path, delimiter="\t"):
        if r.get("status") in ("301", "308") and r.get("hops") == "1" and r.get("final_status") == "200":
            target = slug_of(r["final_url"])
            if target in live and "/admissions/" in r["final_url"] and "?" not in r["final_url"]:
                out[target].add((form_of(r["url"]), slug_of(r["url"])))
    return out


def tier(row, gpa):
    rate = filled(row, ["acceptance_rate"])
    tests = filled(row, SAT + ACT)
    # A test-blind college has no scores to show (IPEDS: not considered even if submitted), so it lacks nothing there
    blind = (row.get("admission_requirements_test_scores") or "").startswith("Not considered")
    have = [n for n, ok in (("cited GPA", gpa), ("acceptance rate", rate), ("SAT/ACT", tests)) if ok]
    missing = [n for n in ("cited GPA", "acceptance rate") if n not in have] + ([] if tests or blind else ["SAT/ACT"])
    if (row.get("adm_open_admission") or "").strip() == "Yes":
        return "C", "open admission (IPEDS)" + (f"; has {', '.join(have)}" if have else "")
    if have and not missing:
        return "A", "cited GPA, acceptance rate and " + ("SAT/ACT" if tests else "test blind")
    if have:
        return "B", f"no {' or '.join(missing)}" + ("; test blind" if blind and not tests else "")
    if row.get("emptied"):
        return "C", "figures removed in Phase 2 H (identity under review)"
    return "C", "no cited GPA, acceptance rate or SAT/ACT"


def score_range(row, lo, hi):
    a, b = (row.get(lo) or "").strip(), (row.get(hi) or "").strip()
    return f"{a}-{b}" if a and b else ""


def write_summary(rows):
    def n(x):
        return f"{x:,}"

    lines = ["# Tiers for the /admissions/ college pages (step 2 of Digant's plan, 2026-10-03)", "",
             "Written by `scripts/admissions/tiering.py` (its docstring has the rules and sources); every page is in "
             "`tiers.csv`. Search Console: 2025-10-03 to 2026-10-02, each page with its old addresses.", "",
             "| Tier | Pages | Clicks, 12 months | Impressions, 12 months | Index | Noindex |",
             "| --- | --- | --- | --- | --- | --- |"]
    for t, name in (("A", "A, complete"), ("B", "B, partial"), ("C", "C, open admission or none")):
        rs = [r for r in rows if r["tier"] == t]
        lines.append(f"| {name} | {n(len(rs))} | {n(sum(r['clicks_12m'] for r in rs))} | "
                     f"{n(sum(r['impressions_12m'] for r in rs))} | {n(sum(1 for r in rs if r['recommendation'] == 'index'))} | "
                     f"{n(sum(1 for r in rs if r['recommendation'] == 'noindex'))} |")
    lines.append(f"| All | {n(len(rows))} | {n(sum(r['clicks_12m'] for r in rows))} | "
                 f"{n(sum(r['impressions_12m'] for r in rows))} | {n(sum(1 for r in rows if r['recommendation'] == 'index'))} | "
                 f"{n(sum(1 for r in rows if r['recommendation'] == 'noindex'))} |")
    lines += ["", "## Why each page is in its tier", ""]
    for (t, why), k in sorted(Counter((r["tier"], r["why"].split(";")[0]) for r in rows).items(),
                              key=lambda x: (x[0][0], -x[1])):
        lines.append(f"- {t}: {why}: {n(k)}")
    kept = [r for r in rows if r["tier"] == "C" and r["recommendation"] == "index"]
    lines += ["", f"## Tier C pages kept in search ({len(kept)})", "",
              "Pages with a click in the last 12 months (traffic overrides) or a GPA the college reported. The most "
              "clicked:", ""]
    for r in sorted(kept, key=lambda r: (-r["clicks_12m"], -r["impressions_12m"]))[:15]:
        lines.append(f"- {r['title']} (/admissions/{r['slug']}/): {r['clicks_12m']} clicks, "
                     f"{n(r['impressions_12m'])} impressions; {r['why']}")
    out = [r for r in rows if r["recommendation"] == "noindex"]
    seen = [r for r in out if r["impressions_12m"] >= 100]
    lines += ["", f"## Out of search ({n(len(out))})", "",
              f"No clicks in 12 months. {len(seen)} had 100 or more impressions; the most:", ""]
    for r in sorted(seen, key=lambda r: -r["impressions_12m"])[:10]:
        lines.append(f"- {r['title']} (/admissions/{r['slug']}/): {n(r['impressions_12m'])} impressions; {r['why']}")
    with open(os.path.join(OUT, "summary.md"), "w", encoding="utf-8") as f:
        f.write("\n".join(lines) + "\n")


def main():
    live = [slug_of(r["url"]) for r in read_csv(os.path.join(ROOT, "data/admissions/live_checks/sitemap_colleges.csv"))]
    live = [s for s in live if s]
    moves = {r["slug"]: r["proposed_slug"] for r in read_csv(os.path.join(QA, "renames.csv")) if r["group"] == "renamed"}
    before = {new: old for old, new in moves.items()}
    data, gsc, redirects = fields(), traffic(), redirected(set(live))
    gpas = {r["slug"]: r for r in read_csv(os.path.join(AUDIT, "phase2_b2_gpa.csv"))}
    titles = {r["slug"]: r["new_title"] for r in read_csv(os.path.join(AUDIT, "phase3_names.csv"))}

    rows, problems = [], []
    for slug in sorted(set(live)):
        if slug in moves:  # a sitemap read before the renames: the page is at its new address now
            slug = moves[slug]
        old = before.get(slug, slug)
        row = data.get(old) or data.get(slug)
        if row is None:
            problems.append(f"{slug}: no import row")
            row = {}
        gpa = gpas.get(old) or gpas.get(slug)
        t, why = tier(row, bool(gpa))
        addresses = {(form, s) for form in ("admissions", "admission") for s in {slug, old}} | redirects.get(slug, set())
        clicks = sum(gsc[a][0] for a in addresses if a in gsc)
        impressions = sum(gsc[a][1] for a in addresses if a in gsc)
        if t in ("A", "B"):
            rec, reason = "index", f"tier {t}"
        elif clicks:
            rec, reason = "index", f"tier C, kept for traffic: {clicks} clicks in 12 months"
        elif gpa:
            rec, reason = "index", "tier C, kept: it shows a GPA the college reported"
        else:
            rec, reason = "noindex", "tier C, no clicks in 12 months"
        rows.append({
            "slug": slug, "title": titles.get(old) or row.get("post_title", ""), "state": row.get("college_state", ""),
            "tier": t, "why": why, "open_admission": row.get("adm_open_admission", ""),
            "cited_gpa": f"{gpa['cds_gpa']} ({gpa['cds_gpa_year']})" if gpa else "",
            "acceptance_rate": row.get("acceptance_rate", ""),
            "sat": score_range(row, "sat_composite_25", "sat_composite_75") or ", ".join(
                f"{name} {score_range(row, f'sat_{part}_25', f'sat_{part}_75')}"
                for name, part in (("math", "math"), ("reading", "reading"))
                if score_range(row, f"sat_{part}_25", f"sat_{part}_75")),
            "act": score_range(row, "act_composite_25", "act_composite_75"),
            "clicks_12m": clicks, "impressions_12m": impressions, "addresses_counted": len(addresses & set(gsc)),
            "recommendation": rec, "reason": reason, "old_slug": old if old != slug else "",
        })

    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, "tiers.csv"), "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, COLS)
        w.writeheader()
        w.writerows(rows)
    write_summary(rows)
    by_tier = Counter(r["tier"] for r in rows)
    rec = Counter((r["tier"], r["recommendation"]) for r in rows)
    clicks = defaultdict(int)
    impressions = defaultdict(int)
    for r in rows:
        clicks[r["tier"]] += r["clicks_12m"]
        impressions[r["tier"]] += r["impressions_12m"]
    print(f"{len(rows)} pages; tiers {sorted(by_tier.items())}; recommendations {sorted(rec.items())}")
    print("clicks by tier", dict(clicks), "impressions by tier", dict(impressions))
    print("why:", Counter((r["tier"], r["why"].split(";")[0]) for r in rows).most_common(12))
    if problems:
        print(f"{len(problems)} problems:", problems[:10])
        sys.exit(1)


if __name__ == "__main__":
    main()
