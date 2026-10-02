# Admissions Phase 3: server runbook

Server steps for the /admissions/ templates, run from the repo clone on Digant's Mac (`~/Documents/Claude/gpacalculator`,
branch `claude/admissions-data-phase1-bsjda7`). Run `git pull` before each step. Each step runs only after Digant types
its go in the admissions thread. Log every live change in `docs/LIVE_CHANGELOG.md` with its undo, push, and report in
the thread. `SSH` and `WP` are as in `docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's go for Phase 3 (2026-10-02 19:15 UTC, decision card): build the hub and the college pages on the design
update and send screenshots before anything deploys. Screenshots went to the thread at 20:52 UTC
(`/mnt/project-files/admissions/phase3/`). At 21:39 Digant asked for hub listings that stand out, the GPA in place of
the acceptance rate, color coding for how hard each college is to get into (the rate goes in the colored tag, his
pick at 21:48) and no source line at the top of the college pages; screenshots of those went to the thread too. Each
step below still waits for Digant's typed go.

## 1. Templates: the hub and the college pages

Code: aae50f9, seven theme files (admissions.css, archive-colleges.php, template-parts/college-db-archive.php,
single-colleges.php, college-data.php, functions.php, database-ajax.js). It merges the design branch at cc09193 (live
since 21:47 UTC: rails V6, the 800px column with 720px text), so its functions.php keeps everything the design deploys
shipped. The design's CSS files are not part of this deploy.

1. Digant runs it from Terminal (this Mac's permission settings block theme deploys):
   `cd ~/Documents/Claude/gpacalculator && git pull && bash scripts/admissions/deploy_phase3.sh`.
   The script first reads the seven live files and stops, changing nothing, if one of them is a version aae50f9 doesn't
   contain (a newer design deploy): then the branch needs the design branch merged and `COMMIT` updated. Otherwise it
   backs up the live theme, ships the seven files and prints the backup name and the revert command.
2. When Digant says "deployed", check on desktop (1440 wide) and phone (390 wide), with no sideways scroll anywhere:
   - /admissions/: the hero says 3,085 (the live count) US colleges and universities, with the GPA of 267 of them, and
     ends there (no line saying where the data comes from); the search card overlaps the hero. Each college is a card
     with a colored left edge and a tag ("Open admission", "Easy · 76% admitted"), under the legend "How hard to get
     into, by fall 2024 acceptance rate". Typing "boston" lists 20 colleges, Boston University with GPA 3.86 "As
     reported by the college for 2025–26" and "Hard · 11% admitted"; "Ivy League" lists 8, "More filters" opens the
     panel; "Under 10%" with "Lowest acceptance rate" lists 33 with Caltech first, every tag "Very hard"; "Show more
     colleges" adds 30 cards; a search for "zzqx" shows "No colleges match" and hides the legend. The browser tab
     title starts "College Admissions Database: Acceptance Rates, SAT & ACT". Mid-article and bottom ad slots and the
     rails fill.
   - /admissions/harvard/: the hero ends with the intro ("It admitted 3.6% of first-year applicants for fall 2024."),
     no source line under it; quick facts, "On this page", numbered sections, "Average GPA 4.22 (weighted), as
     reported by the college for 2025–26", Sources, the FAQ; the page source has one FAQPage.
   - /admissions/lone-star-college-system/ (open admission) and /admissions/fairfax-university-of-america/ ("Figures
     under review", no figures).
   - /college-gpa-calculator/ and /gpa-scale/3-8-gpa/ look as before (their files weren't touched).
3. Changelog row: "/admissions/ hub and the college pages", deployed aae50f9 by Digant from Terminal
   (`scripts/admissions/deploy_phase3.sh`, seven files), what was checked; undo
   `bash scripts/deploy_theme.sh --revert <backup>`. If anything is broken, revert first, then report.

## 2. GPA spread on the college pages

Data: `data/admissions/audit/phase3_gpa_bands.csv` (`scripts/admissions/phase3_gpa_bands.py`): for 192 of the 267 pages
with a cited GPA, the share of first-year students in each GPA range from the same Common Data Set (C11), every value
verified in Phase 1, adding up to 99-101% and fitting the cited average. The other 75 are in
`phase3_gpa_bands_pending.csv` with the reason: 46 CDS files give fewer than three ranges, 2 give ranges that don't
add up to 99-101%, and 27 give ranges the average doesn't fit (13 weighted averages above 4.0, 14 others), so the two
may be on different bases. Those pages keep the average only.
The caption says the college doesn't say whether the GPAs are weighted. Needs step 1 live, since the new template
shows the fields. A later GPA refresh must rewrite or remove these fields along with the average.

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-gpa-bands.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/phase3_bands_live.sh plan`: expect "192 rows would change, 0 already match, 0 skipped". A
   SKIP means that page no longer shows the same GPA and year as the row: stop and report it.
3. `bash scripts/admissions/phase3_bands_live.sh apply` (note the log name it prints): "applied 192".
4. Check /admissions/wilkes-university/: under the average GPA of 3.46, "How their high school GPAs were spread"
   lists 4.0 13.3%, 3.75–3.99 22.8% and five more ranges down to 2.00–2.49 4.9%; the caption cites Wilkes's 2025–26
   Common Data Set, says none had a GPA below 2.00 and that the college doesn't say whether these GPAs are weighted or
   unweighted. /admissions/agnes-scott/ lists six ranges. /admissions/harvard/ (pending: its ranges don't fit the
   weighted 4.22) shows the average only.
5. Changelog row with the undo `bash scripts/admissions/phase3_bands_live.sh revert <log>`.

## 3. College names: lost punctuation and former names

Data: `data/admissions/audit/phase3_names.csv` (`scripts/admissions/phase3_names.py`), 417 titles, each checked against
the college's 2024 IPEDS name and the names IPEDS listed for the same college from 2002 to 2023
(`data/admissions/review/ipeds_names.csv`): 306 renamed colleges (Calvin College is Calvin University), 65 names
that lost a hyphen, apostrophe or period (Hardin-Simmons University), 30 campus names (Pace University New York is
Pace University) and 16 forms of the same name (University of Illinois Chicago). A renamed college's old name goes
into its former_name field, so the page says "Calvin University (formerly Calvin College) is ..." and the hub's
search finds the old name. Addresses don't change. The 36 former names left as they are, with the reason, are in
`phase3_names_kept.csv`. Needs step 1 live, since the new template shows the former name.

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-names.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/phase3_names_live.sh plan`: expect "417 rows would change (306 former name, 65
   punctuation, 30 campus name, 16 name form), 0 already match, 0 skipped". A SKIP means the page's title or college
   is no longer the row's: stop and report it.
3. `bash scripts/admissions/phase3_names_live.sh apply` (note the log name it prints): "applied 417".
4. Check /admissions/calvin/: the heading and browser tab say Calvin University, and the opening line reads "Calvin
   University (formerly Calvin College) is a private nonprofit 4-year university in Grand Rapids, Michigan";
   /admissions/hardin-simmons-university/ says Hardin-Simmons University; /admissions/uiuc/ says University of
   Illinois Urbana-Champaign with no "formerly"; on /admissions/, searching "Calvin College" lists Calvin University.
5. Changelog row with the undo `bash scripts/admissions/phase3_names_live.sh revert <log>` (it restores every title
   and removes the former names; the posts keep the new modified date).
