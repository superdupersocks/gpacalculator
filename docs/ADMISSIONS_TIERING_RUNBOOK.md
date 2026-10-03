# Admissions tiering: server runbook (step 2 of Digant's plan, 2026-10-03)

Server steps for the tiers and index/noindex recommendations in `data/admissions/tiering/` (`summary.md`, one row per
page in `tiers.csv`, both from `scripts/admissions/tiering.py`), run from the repo clone on Digant's Mac
(`~/Documents/Claude/gpacalculator`, branch `claude/admissions-data-phase1-bsjda7`). Run `git pull` first. Nothing here
runs before Digant types the go in the admissions thread. Log every live change in `docs/LIVE_CHANGELOG.md` with its
undo, push, and report in the thread. `SSH` and `WP` are as in `docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's plan (project chat, 2026-10-03 00:38 UTC), step 2: "Tiering. A = complete data, B = partial, C =
open-admission/no GPA. Add Search Console impressions and clicks (last 12 months) via Windsor.ai. Recommend index or
noindex per page; traffic overrides. → Checkpoint."

What runs:
- **Tiers and noindex** (`scripts/admissions/tiering_live.sh`; the PHP is `tiering_live.php`): every college page gets
  the post meta `admissions_tier` (A, B or C; step 3's template flag reads it). The 1,318 pages the tiering takes out
  of search get Rank Math's own robots setting for the page (`rank_math_robots`, the page's Advanced tab) set to
  No Index, keeping the rest of what the page had; Rank Math then also leaves them out of the colleges sitemap. The
  pages stay published and on the hub. Only those two meta values change: no content, address, title or modified
  date. A row is skipped, and listed, unless exactly one published college page has its slug.
- **Step 1 follow-up** (`scripts/admissions/cleanup_fix_live.sh plan` / `apply`, from `data/admissions/cleanup_qa/fixes.csv`):
  the 10 redirect rows step 1 skipped because their Rank Math rule also covers addresses that answer differently. The
  script now splits such a rule: the addresses that need a new answer move to a new rule, the rest stay. A 301 whose
  target no longer has a page (a dead end) becomes a 410 for that address alone. Rows already answering as listed
  count as already right.

## Steps

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-tiers.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/cleanup_fix_live.sh plan`. Expect "0 rules to change, N to split, M to add, K already
   right, 0 skipped" with the split and set lines naming only the step 1 rows listed in the 02:26 changelog row
   (Argosy Washington DC, Bethany, the DeVry Keller campuses, LIU Rockland, Shorter, the SIU systems office, UMDNJ,
   University of Phoenix Minneapolis-St. Paul). Anything else (a "set" or "add" line for another address, or a
   skipped row): stop and report the plan output.
   Then `bash scripts/admissions/cleanup_fix_live.sh apply` (note the log name it prints).
3. `bash scripts/admissions/tiering_live.sh plan`. Expect "N values to write (noindex: X, tier A: 253, tier B: 1355,
   tier C: 1477), 0 skipped" with X at most 1,318 (a page already set to No Index counts as right). "had a value"
   lines show robots settings pages already had; they are kept. Stop and report instead if the plan prints a LIFT line
   (a page someone set to No Index that the tiering would put back in search), a SKIP line, or other tier counts.
4. `bash scripts/admissions/tiering_live.sh apply` (note the log name it prints). It clears the object, page and
   sitemap caches after.
5. Checks from the Mac, one request a second (the site answers 429 to bursts):
   - `curl -s https://gpacalculator.net/admissions/loma-linda-university/ | grep -o '<meta name="robots"[^>]*>'`
     says `noindex` (tier C, no clicks).
   - The same for `/admissions/harvard/` (tier A) and `/admissions/university-of-st-augustine-for-health-sciences/`
     (tier C, kept for its clicks): `index`, as before.
   - The colleges sitemaps listed in `/sitemap_index.xml` hold about 1,768 addresses in all (1,767 pages and the hub;
     3,086 before), and none of them is `/admissions/loma-linda-university/`.
   - `curl -sI` on `/admission/devry-universitys-keller-graduate-school-of-management-michigan/`: 301 to
     `/admissions/devry-university-illinois/`, which answers 200; `/admission/bethany-university/`: 410.
   Anything else: `bash scripts/admissions/tiering_live.sh revert <log>` (and `cleanup_fix_live.sh revert <log>` for
   the follow-up), then report.
6. Changelog rows (the step 1 follow-up, the tiers) with each undo; push; report both log names in the thread. The
   cloud thread then re-runs the live checks on GitHub (the cleanup check and the indexing check).

## Undo

`bash scripts/admissions/tiering_live.sh revert <log>` puts every logged value back (newest first): a page that had no
tier or robots setting loses it again, and one that had a setting gets it back exactly. The log is in `~/backups/` on
the server and `~/gpacalculator-backups/` on the Mac. Pages left out of search come back with the next crawl.

## Not in this step

- The new template (step 3) reads `admissions_tier`; nothing visible changes on the pages until its flag is on.
- Re-tiering after new data (more cited GPAs, a new IPEDS year): run `scripts/admissions/tiering.py`, then plan and
  apply again; only changed values are written.
