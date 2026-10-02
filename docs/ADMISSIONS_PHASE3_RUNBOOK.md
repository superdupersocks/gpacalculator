# Admissions Phase 3: server runbook

Server steps for the /admissions/ templates, run from the repo clone on Digant's Mac (`~/Documents/Claude/gpacalculator`,
branch `claude/admissions-data-phase1-bsjda7`). Run `git pull` before each step. Each step runs only after Digant types
its go in the admissions thread. Log every live change in `docs/LIVE_CHANGELOG.md` with its undo, push, and report in
the thread. `SSH` and `WP` are as in `docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's go for Phase 3 (2026-10-02 19:15 UTC, decision card): build the hub and the college pages on the design
update and send screenshots before anything deploys. Screenshots went to the thread at 20:52 UTC
(`/mnt/project-files/admissions/phase3/`). Each step below still waits for Digant's typed go.

## 1. Templates: the hub and the college pages

Code: e2a1b69, seven theme files (admissions.css, archive-colleges.php, template-parts/college-db-archive.php,
single-colleges.php, college-data.php, functions.php, database-ajax.js). It merges the design branch at 4a38e1b, so its
functions.php keeps everything the design deploys shipped. The design's CSS files are not part of this deploy.

1. Digant runs it from Terminal (this Mac's permission settings block theme deploys):
   `cd ~/Documents/Claude/gpacalculator && git pull && bash scripts/admissions/deploy_phase3.sh`.
   The script first reads the seven live files and stops, changing nothing, if one of them is a version e2a1b69 doesn't
   contain (a newer design deploy): then the branch needs the design branch merged and `COMMIT` updated. Otherwise it
   backs up the live theme, ships the seven files and prints the backup name and the revert command.
2. When Digant says "deployed", check on desktop (1440 wide) and phone (390 wide), with no sideways scroll anywhere:
   - /admissions/: the hero says 3,085 (the live count) US colleges and universities, with the GPA of 267 of them; the
     search card overlaps the hero. Typing "boston" lists 20 colleges, "Ivy League" lists 8, "More filters" opens the
     panel; "Under 10%" with "Lowest acceptance rate" lists 33 with Caltech first; "Show more colleges" adds 30 rows;
     a search for "zzqx" shows "No colleges match". The browser tab title starts "College Admissions Database:
     Acceptance Rates, SAT & ACT". Mid-article and bottom ad slots and the rails fill.
   - /admissions/harvard/: quick facts, "On this page", numbered sections, "Average GPA 4.22 (weighted), as reported
     by the college for 2025–26", Sources, the FAQ; the page source has one FAQPage.
   - /admissions/lone-star-college-system/ (open admission) and /admissions/fairfax-university-of-america/ ("Figures
     under review", no figures).
   - /college-gpa-calculator/ and /gpa-scale/3-8-gpa/ look as before (their files weren't touched).
3. Changelog row: "/admissions/ hub and the college pages", deployed e2a1b69 by Digant from Terminal
   (`scripts/admissions/deploy_phase3.sh`, seven files), what was checked; undo
   `bash scripts/deploy_theme.sh --revert <backup>`. If anything is broken, revert first, then report.

## 2. GPA spread on the college pages

Data: `data/admissions/audit/phase3_gpa_bands.csv` (`scripts/admissions/phase3_gpa_bands.py`): for 219 of the 267 pages
with a cited GPA, the share of first-year students in each GPA range from the same Common Data Set (C11), every value
verified in Phase 1 and adding up to 99-101%. The other 48 are in `phase3_gpa_bands_pending.csv` with the reason (46
CDS files give no ranges). Needs step 1 live, since the new template shows the fields.

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-gpa-bands.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/phase3_bands_live.sh plan`: expect "219 rows would change, 0 already match, 0 skipped". A
   SKIP means that page no longer shows the same GPA and year as the row: stop and report it.
3. `bash scripts/admissions/phase3_bands_live.sh apply` (note the log name it prints): "applied 219".
4. Check /admissions/harvard/: under the average GPA, "How their high school GPAs were spread" lists 4.0 74.7%,
   3.75–3.99 20.4% and five more ranges, with the caption citing Harvard's 2025–26 Common Data Set;
   /admissions/wilkes-university/ lists nine ranges. A page with a cited GPA but no ranges (e.g. one in the pending
   file) shows the average only.
5. Changelog row with the undo `bash scripts/admissions/phase3_bands_live.sh revert <log>`.
