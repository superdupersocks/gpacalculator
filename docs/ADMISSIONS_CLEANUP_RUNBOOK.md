# Admissions cleanup QA: server runbook (step 1 of Digant's plan, 2026-10-03)

Server steps for the fixes the cleanup QA proposes (`data/admissions/cleanup_qa/report.md`), run from the repo clone on
Digant's Mac (`~/Documents/Claude/gpacalculator`, branch `claude/admissions-data-phase1-bsjda7`). Run `git pull`
first. Nothing here runs before Digant types the go in the admissions thread. Log every live change in
`docs/LIVE_CHANGELOG.md` with its undo, push, and report in the thread. `SSH` and `WP` are as in
`docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's plan (project chat, 2026-10-03 00:38 UTC), step 1: "Cleanup QA. Using the pre-cleanup backup, list every
removed /admissions/ URL with its status (301 + target, or 410). Flag chains and 404s. Switch any 410 with backlinks
or clicks in the last 12 months to a 301. Update internal links to removed URLs. Flag renamed schools (e.g.
Maharishi) with proposed renames + 301s. → Checkpoint."

What runs, all from `scripts/admissions/cleanup_fix_live.sh` (the PHP is `cleanup_fix_live.php`), into one log:
- **Redirect fixes** (`data/admissions/cleanup_qa/fixes.csv`, 330 addresses): 235 answer 410, 95 get a 301. An
  address an active Rank Math rule answers gets that rule changed when every address the rule covers is in the list
  with the same answer; any other address gets a new rule. Rows are skipped, and listed, when a published college
  page has the address, a 301 target isn't one published college page or the hub, two rules answer it, or its rule
  covers addresses the list doesn't change.
- **Leftover pages**: the published WordPress pages under /admissions/ (42 in the page sitemap on 2026-10-02, children
  of the old "Admissions" page) become drafts. Only post_status changes. The colleges post type answers their
  addresses, so nothing visible changes, except that /admission/<slug>/ stops going through them.
- **Renames** (`data/admissions/cleanup_qa/renames.csv`, group `renamed`, 302 pages): each page moves to its new
  address (wp_update_post), one rule sends both old forms there, rules that pointed at the old address point at the new
  one, and a rule that sent the new address to this page stops covering it. Skipped, and listed, unless exactly one
  published college page has the old address with the same IPEDS ID and nothing else uses the new one.

## Steps

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-cleanup.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/cleanup_fix_live.sh plan-all`. Expect, in order:
   - fixes: "N rules to change, M to add, K already right, S skipped" with N + M + K covering 330 addresses (rules
     with two addresses count once). A skipped row stays as it is; note it for the report.
   - pages: about 42 lines "page <ID> /admissions/<slug>/", one of them with "college page … has this address"
     (dongguk-university-los-angeles), then "42 published pages under /admissions/ to unpublish". A count far from 42,
     or a page whose address isn't one of those in `data/admissions/cleanup_qa/leftover_pages.csv`, stops the run:
     report it instead.
   - renames: "302 pages to move, 0 skipped" (on the local copy: 302 and 0).
3. `bash scripts/admissions/cleanup_fix_live.sh apply-all` (note the log name it prints). It clears the object,
   page and sitemap caches after.
4. Checks from the Mac, each with `curl -sI` (one request a second; the site answers 429 to bursts):
   - `/admission/liu-post/`: 301 to `/admissions/long-island-university/`, which answers 200 (one hop).
   - `/admission/kingston-university/`: 301 to `/admissions/`.
   - `/admission/bethany-university/` and `/admission/ellis-university/`: 410, no redirect first.
   - `/admissions/stratford-university/`: 301 to `/admissions/?search=Virginia`.
   - `/admissions/maharishi-university-of-management/` and `/admission/maharishi-university-of-management/`: 301 to
     `/admissions/maharishi-international-university/`, which answers 200 with the page.
   - `/admissions/harvard/`: 200 as before.
   - `https://gpacalculator.net/page-sitemap.xml`: no `/admissions/<slug>/` addresses left.
   Anything else: `bash scripts/admissions/cleanup_fix_live.sh revert <log>` and report.
5. Changelog rows (fixes, pages, renames) with the undo `bash scripts/admissions/cleanup_fix_live.sh revert <log>`;
   push; report in the thread with the log name and the plan's skipped rows. The cloud thread then re-runs the full
   live check on GitHub (`.github/workflows/admissions-cleanup-qa.yml`).

## Not in this step

- University of Minnesota-Twin Cities has no page (its old address had 1,602 impressions in 12 months); adding it is
  a new page, like Phase 2's checkpoint N, and needs its own go.
- 18 optional renames (group `optional`) stay unless Digant asks: `plan-renames optional` / `apply-renames optional`.
- Backlinks: Search Console > Links > "Top linked pages" (external) export; any retired address in it gets a 301 the
  same way (`cleanup_qa.py` rule: successor page, else the state's list, else the hub).

## Follow-up after the live re-check (2026-10-03)

The re-check of 3,188 addresses after the 02:26 run (`cleanup_qa.py after`, the GitHub check, then `cleanup_qa.py
verify`: `data/admissions/cleanup_qa/after/verify.csv`) found 3,171 answering as planned, no links to removed pages,
and 14 old /admission/ addresses still answering the old way (`cleanup_qa.py followup` writes them to `followup.csv`).
`cleanup_fix_live.sh plan-followup` / `apply-followup` runs them with the fixed comparison (decoded addresses, every
matching rule, Rank Math's remembered answers cleared); it runs as step 2 of `docs/ADMISSIONS_TIERING_RUNBOOK.md`.
