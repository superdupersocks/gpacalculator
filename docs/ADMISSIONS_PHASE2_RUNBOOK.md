# Admissions Phase 2: server runbook

Server steps for the /admissions/ overhaul, run from the repo clone on Digant's Mac. Run `git pull` before each
checkpoint, not just once: steps get fixed and added while the others run. Each checkpoint runs only after Digant types
its go in the admissions thread, one checkpoint at a time. Log every live change in `docs/LIVE_CHANGELOG.md` with its
undo, push, and report in the thread.

Digant's go (2026-10-02 05:58 UTC): B, D and C, with these limits. B removes the unsupported claims and the competitor
images; the cited CDS GPAs replace them in step B2, right after B. D copies useful details into the
surviving pages before its four 301s. C retires the verified closures and redirects the confirmed mergers; the merged
pages in `data/admissions/audit/phase2_c_held.csv` and everything still under review stay unchanged. Individual pages
within that scope need no further approval; report counts and exceptions.

`SSH` below means `ssh -i ~/.ssh/gpacalculator_cloudways -o IdentitiesOnly=yes master_rfzfmbbwze@67.205.161.226`, and
`WP` is `applications/xwnzegvpyy/public_html` on the server.

## B: stop publishing unsupported GPA, standards and competition claims and the hotlinked photos

Code: commit 9a803a3 (five theme files). Data: `scripts/admissions/phase2_b_live.sh` blanks `average_gpa`,
`admission_standards`, `applicant_competition` and `img_url` on every college post, after saving their values. No
college post's body text or excerpt mentions a GPA, those labels or collegesimply (checked in the October 1 export in
`data/colleges/`) and none has its own Rank Math title, description or schema, so checkpoint A's open content scan
isn't needed.

1. Database backup, as in checkpoint A: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-admissions-b.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. The live copies of the five theme files must match the repo before this change. Stop and report if any differs:
   ```
   for f in functions.php archive-colleges.php template-parts/college-db-archive.php single-colleges.php database-ajax.js; do
     SSH "cat WP/wp-content/themes/generatepress-child/$f" | cmp -s - <(git show 9a803a3^:child-theme/generatepress-child/$f) \
       && echo "same    $f" || echo "DIFFERS $f"
   done
   ```
3. `bash scripts/admissions/phase2_b_live.sh count`, then `bash scripts/admissions/phase2_b_live.sh export` (note the
   export name it prints). The export is the audit copy of the original values Digant asked to keep.
4. Theme first, so the listing stops sorting by GPA before the GPAs go. B2's data is already in (06:27), so B, B2 and
   E's template ship together from af793b4: B's five files plus E's new college-data.php. That commit holds B, B2's GPA
   card, the exact-slug 404 guess and E's template (which changes a page only once E's data reaches it), so B2 step 3
   and E step 2 are then done:
   `bash scripts/deploy_theme.sh af793b4 --dry-run --only functions.php,archive-colleges.php,template-parts/college-db-archive.php,single-colleges.php,database-ajax.js,college-data.php`.
   Only those six files may change (college-data.php is new). Then the same command without `--dry-run`; note the
   theme backup name. If this Mac's permission settings block the deploy, Digant runs that command (without
   `--dry-run`) in Terminal and types "deployed" in the thread; find the backup name with
   `bash scripts/deploy_theme.sh --list`. If 759f5b7 was deployed instead (the earlier version of this step), B is
   deployed and E step 2 ships the rest.
5. Data: `bash scripts/admissions/phase2_b_live.sh apply <export name>`. The leftover count must be 0.
6. Check (and run B2 step 5's checks too when the theme came from af793b4 or 759f5b7):
   - Profiles that had a GPA (abilene-christian-university, harvard, clark-atlanta-university, hardin-simmons-university)
     and two that didn't (westcliff-university, a-t-still-university) return 200. Their `<title>`, meta description,
     `og:title` and H1 carry no GPA figure and no "GPA Requirements"; the page has no "Average GPA" stat, no "Admission
     Standards" or "Applicant Competition" badge, no "What GPA do I need" question; the JSON-LD parses and has no
     "Average GPA"; no `collegesimply.imgix.net` anywhere in the HTML.
   - /admissions/ returns 200, its cards show no GPA and run A to Z; Load More (the AJAX listing) returns the next
     colleges without repeats; the acceptance-rate and SAT filters still return colleges.
   - Freestar ad tags are on a profile and on the hub; /high-school-gpa-calculator/ loads with its calculator script.
   If anything breaks: `bash scripts/admissions/phase2_b_live.sh revert <export name>` and
   `bash scripts/deploy_theme.sh --revert <theme backup>`, then report.
7. Changelog: one row for the theme files and one for the four fields, each with its undo command.

Undo: `bash scripts/admissions/phase2_b_live.sh revert <export name>` puts every value back;
`bash scripts/deploy_theme.sh --revert <theme backup>` restores the theme files.

## B2: cited Common Data Set GPAs

Data done 2026-10-02 06:27 (243 pages); its theme part ships with B step 4. The more GPAs found since then go in with
"B2, second list" below, after E.

Digant's go for B includes "Replace GPA with verified, cited CDS values wherever available". Run this after B. Commit
a15c8a7 changes two theme files (deploy them from 759f5b7, which adds the exact-slug 404 guess to functions.php on top,
so neither change undoes the other): a college with `cds_gpa` fields gets an "Average high school GPA" card noted "As
reported by the college, <year>" (plus "weighted" when the average is above 4.0) and a FAQ, in the page and its
JSON-LD, that states the value with its year and cites the college's own file. Titles and descriptions don't change.
`data/admissions/audit/phase2_b2_gpa.csv` has the 243 pages whose college published the file on its own site;
`phase2_b2_gpa_pending.csv` has the 99 that wait (files on Google Drive, Box or other hosts need the college's page that
links them; two files belong to other colleges). Don't write any CDS value into `average_gpa`.

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-b2.sql.gz`).
2. The live copies of the two files must be B's (9a803a3), or already a15c8a7's or 759f5b7's if B2 or the 404 guess
   fix went out first. Stop and report if a file matches none of them:
   ```
   for f in functions.php single-colleges.php; do
     SSH "cat WP/wp-content/themes/generatepress-child/$f" > /tmp/live-$f
     for c in 9a803a3 a15c8a7 759f5b7; do
       git show $c:child-theme/generatepress-child/$f | cmp -s - /tmp/live-$f && echo "live $f = $c"
     done
   done
   ```
3. Theme: `bash scripts/deploy_theme.sh 759f5b7 --dry-run --only functions.php,single-colleges.php`. Only those two
   files may change. Then the same command without `--dry-run`; note the theme backup name.
4. Data: `bash scripts/admissions/phase2_b2_live.sh plan` must end "243 rows ready" (report any `SKIP`), then
   `bash scripts/admissions/phase2_b2_live.sh apply`; note the log name.
5. Check /admissions/pitt/ (4.06, weighted), /admissions/unc/ (4.47, weighted), /admissions/alabama/ (3.85) and
   /admissions/agnes-scott/ (3.63): the card shows the GPA with "As reported by the college, 2025–26"; the FAQ "What
   is the average high school GPA at ...?" gives the same value and links to the college's Common Data Set file; the
   JSON-LD parses and its FAQPage has that question; `<title>` and the meta description carry no GPA figure (from
   af793b4 they say "Average GPA" and "See its average GPA"). /admissions/harvard/ (pending) shows no GPA. Freestar
   tags are on the page. If anything breaks:
   `bash scripts/admissions/phase2_b2_live.sh revert <log name>` and `bash scripts/deploy_theme.sh --revert <theme backup>`.
6. Changelog: one row for the two theme files and one for the 243 pages' fields, each with its undo command.

Undo: `bash scripts/admissions/phase2_b2_live.sh revert <log name>` puts every field back;
`bash scripts/deploy_theme.sh --revert <theme backup>` restores the two theme files.

## E: fresh federal data on the confidently matched pages

Digant's go (05:58) ends: "Fix the missing legacy redirects next, then prioritize importing the fresh data and
finishing the template." Digant's terms for E (04:35): confidently matched posts get the verified Phase 1 values with
their sources and reporting years, uncertain matches are reviewed separately, and the template must support the new
fields and labels before the import. Run E after B (its theme deploy and its data step).

Code: commit af793b4 (functions.php, single-colleges.php and the new college-data.php). A page with the import shows
each figure with its year and source: acceptance rate with the counts behind it, SAT/ACT middle 50% and medians of
fall 2024 entrants, IPEDS's admission factors in its own wording, undergraduates, net price (2022–23), AP and
life-experience credit (2024–25), College Scorecard's average SAT labeled as its estimate, and a Sources section
linking College Navigator. Its FAQ and FAQPage schema come from one builder. Titles and descriptions follow the new
fields; pages with a cited CDS GPA say "Average GPA" without the figure. Pages without the import render as before.

Data: `data/admissions/audit/phase2_e_import.csv` (2,787 posts, made by `scripts/admissions/phase2_e_import.py`;
counts in `phase2_e_summary.md`). Each row replaces the plan's fields; a field with no fresh value is emptied, never
left with the old unsourced one. College Scorecard's enrollment and net price fallbacks are left out because its
file doesn't state their year. `phase2_e_held.csv` lists the 44 confident matches that stay unchanged, with the
reason: 27 branch campuses with no IPEDS record of their own (their parent college reports for them), 10 missing from
College Scorecard's June 2026 release, 4 the audit left unconfirmed, 3 whose match the audit corrected. The 357
uncertain matches and the 371 unmatched pages aren't in the file either.

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-e.sql.gz`).
2. Theme, unless B step 4 already deployed af793b4: the live functions.php and single-colleges.php must be 759f5b7's
   (stop and report otherwise, as in B2 step 2), then
   `bash scripts/deploy_theme.sh af793b4 --dry-run --only functions.php,single-colleges.php,college-data.php`.
   Only those three files may change. Then the same command without `--dry-run`; note the theme backup name.
3. Data: `bash scripts/admissions/phase2_e_live.sh plan`. It changes nothing; it prints how many posts each field
   would change and should end "2787 posts would change, 0 already match, 0 skipped". Report every `SKIP` line (a
   post unpublished or retitled since the October 1 export) and go on without it. Then
   `bash scripts/admissions/phase2_e_live.sh apply`; note the log name. It ends "updated N posts (N fields), skipped N".
4. Check:
   - /admissions/harvard/: title "Harvard University Acceptance Rate (3.6%) & SAT/ACT Scores"; acceptance rate 3.6%
     for fall 2024 (1,970 of 54,008 applicants); SAT 740–780 reading and writing, 770–800 math; "Average SAT (College
     Scorecard estimate)" 1553; requirements in IPEDS wording; a Sources section linking College Navigator.
   - /admissions/pitt/: the GPA card (4.06, weighted) and its Common Data Set FAQ remain; the acceptance rate reads
     58% for fall 2024.
   - /admissions/blue-cliff-college-metairie/ (open admission): an "Open Admission" badge, no acceptance rate, and the
     requirements card says it accepts any student who applies.
   - Unchanged: /admissions/ivy-tech-community-college-kokomo/ (held) and a page under review.
   - On each: the JSON-LD parses, its FAQPage questions are the page's FAQ questions, no `collegesimply` anywhere,
     Freestar tags present.
   - /admissions/ loads with the new values on its cards; the acceptance-rate filters, the high-acceptance quick
     filter (it includes open admission) and the SAT sort return colleges; a calculator page works.
   If anything breaks: `bash scripts/admissions/phase2_e_live.sh revert <log name>` (and the theme revert if step 2
   ran), then report.
5. Changelog: one row for the 2,787 pages' fields (and one for the theme files if step 2 ran), each with its undo.

Undo: `bash scripts/admissions/phase2_e_live.sh revert <log name>` puts every changed field back and removes the ones
the import added; `bash scripts/deploy_theme.sh --revert <theme backup>` restores the theme files (the new
college-data.php stays on the server, unused: the restored functions.php doesn't load it).

## Hub filters: skip colleges with no figure (after E)

E emptied 1,245 unsourced acceptance rates and every SAT average without a fresh source. The hub's "Under 10%",
"Under 25%" and "Under 50%" filters and its SAT "Under 1200" filter read an empty value as 0, so they listed those
colleges ("Under 10%" showed 1,335). Commits 623589d (acceptance rate) and 5b5018f (SAT) fix both; functions.php is the
only file that differs from af793b4. Digant runs the deploy from Terminal, since this session's permissions block
deploy_theme.sh. "B2, second list" below doesn't wait for it.

1. Digant runs `git pull && bash scripts/deploy_theme.sh 5b5018f --only functions.php` and types "deployed".
2. Check: the live functions.php is 5b5018f's
   (`SSH "cat WP/wp-content/themes/generatepress-child/functions.php" | cmp -s - <(git show 5b5018f:child-theme/generatepress-child/functions.php) && echo same || echo DIFFERS`).
   On /admissions/, "Under 10%" lists only colleges with a rate under 10% (about 30), "Under 1200" only colleges with
   an SAT figure under 1200, and the other filters, the sorts and Load More work as before; a profile and a calculator
   page load with Freestar tags.
3. Changelog: one row with the undo, `bash scripts/deploy_theme.sh --revert <theme backup>` (puts af793b4's
   functions.php back).

## B2, second list: GPAs cited on the college's own page

Run after E (E's Harvard title check expects Harvard without a GPA; if this runs first, that title reads "Harvard
University Average GPA & Acceptance Rate (3.6%)" instead). Digant's rule for CDS files on Google Drive, Sheets or
other hosts: use one only if the college's own website links to it, and cite that page. `scripts/admissions/cds_pages.py`
(run by the Admissions CDS workflow) searched each such college's website and found the page that links the file by its
Google file ID or its path, or links a file on the college's own site with exactly the bytes the GPA was read from
(SHA-256; many Drive and Sheets addresses in the CDS index are copies of a file the college publishes itself). Each
page is in `data/admissions/cds_pages.csv` with the link on it and how it matched.

`data/admissions/audit/phase2_b2_gpa.csv` now has 267 rows: the 242 already live, unchanged, and 25 new ones citing the
college's page (Harvard 4.22 weighted, Stanford 3.94, Penn 3.9, Michigan State 3.74 and 21 more). Central College left
the list: the GPA in its new 2025–26 file couldn't be confirmed, so its page keeps the 2024–25 value already live (the
script never removes a value). 72 GPAs still wait in `phase2_b2_gpa_pending.csv`: no page on the college's own site was
found linking the file.

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-b2-second.sql.gz`).
2. No theme change. The live functions.php must be af793b4's (from B step 4 or E step 2), or 5b5018f's once the hub
   filter fix above is deployed; stop and report otherwise:
   `for c in af793b4 5b5018f; do SSH "cat WP/wp-content/themes/generatepress-child/functions.php" | cmp -s - <(git show $c:child-theme/generatepress-child/functions.php) && echo "same as $c"; done`
   (no output means it differs from both)
3. Data: `bash scripts/admissions/phase2_b2_live.sh plan` must end "25 rows would change, 242 already match, 0
   skipped" (report any `SKIP`), then `bash scripts/admissions/phase2_b2_live.sh apply`; it ends "applied 25, already
   matched 242, skipped 0" and names the log. It writes only fields that change.
4. Check:
   - /admissions/harvard/: the GPA card shows 4.22 "As reported by the college, 2025–26 (weighted)"; the FAQ "What is
     the average high school GPA at Harvard University?" gives 4.22 and links "Harvard University Common Data Set
     2025–26" to https://oira.harvard.edu/common-data-set/; title "Harvard University Average GPA & Acceptance Rate
     (3.6%)"; the meta description carries no GPA figure.
   - /admissions/stanford/ (3.94, 2025–26, https://irds.stanford.edu/data-findings/cds), /admissions/upenn/ (3.9,
     2025–26, https://ira.upenn.edu/penn-numbers/common-data-set) and /admissions/csu-fullerton/ (3.434, 2024–25): same.
   - /admissions/central-college/ still shows 3.56 (2024–25); /admissions/chicago/ (still waiting) shows no GPA.
   - On each: the JSON-LD parses and its FAQPage has the GPA question; Freestar tags present.
   If anything breaks: `bash scripts/admissions/phase2_b2_live.sh revert <log name>`, then report.
5. Changelog: one row for the 25 pages' fields with the undo command.

Undo: `bash scripts/admissions/phase2_b2_live.sh revert <log name>` removes the 25 pages' GPA fields.

## Held pages: six new college pages, and redirects to them (S) and to parent colleges (M)

Waits for Digant's word in this thread: the go of 05:58 held these pages. C held 25 merged colleges' pages because the
college they merged into has no page here, and E held 27 branch campuses whose parent college reports for them.
`scripts/admissions/phase2_s_pages.py` builds from those two lists:

- six new pages (`data/admissions/audit/phase2_s_new.csv`), each with E's fresh fields (`phase2_s_pages.csv`): Baker
  College, University of Phoenix (its Arizona unit, which reports for the online university), Commonwealth University
  of Pennsylvania, Ivy Tech Community College, Illinois Eastern Community Colleges and Minnesota North College. The same
  file has E's fields for /admissions/fortis-institute/, the same Cookeville campus under its new IPEDS ID (494436);
- checkpoint S (`phase2_s_actions.csv`): 32 pages 301 to those six, and 3 retire (410) because the college they merged
  into closed (University of Phoenix-Nevada) or isn't operating (Florida Career College-Miami);
- checkpoint M (same file): 16 branch campuses 301 to their parent college's existing page (Georgia Military College,
  Bryant & Stratton, Delaware Tech, Ohio Business College, San Diego State, South College, University of Maine).

If Digant approves only part of it, run only that part: S needs the new pages (steps 2 and 4), M stands alone (step 5).

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-s.sql.gz`).
2. New pages: `bash scripts/admissions/phase2_s_live.sh plan` must end "6 pages would be added, 0 skipped" (report any
   `SKIP`: a post or a redirect already uses that address), then `bash scripts/admissions/phase2_s_live.sh apply`. It
   ends "added 6, skipped 0" and names the log. Each page is published with all its fields in one insert.
3. Fortis Institute: `bash scripts/admissions/phase2_e_live.sh plan data/admissions/audit/phase2_s_pages.csv` must end
   "1 posts would change, 6 already match, 0 skipped"; then the same command with `apply`.
4. S: `bash scripts/admissions/phase2_cd_live.sh plan S` must end "35 rows ready, 0 fields to copy" (report any `SKIP`),
   then `apply S` and `check S` ("70 addresses checked, 0 wrong").
5. M: the same with `M`: "16 rows ready, 0 fields to copy", then `apply M` and `check M` ("32 addresses checked, 0
   wrong").
6. Check: /admissions/baker-college/ shows 82% for fall 2024 with SAT 500–600 and 460–560;
   /admissions/university-of-phoenix/, /admissions/ivy-tech-community-college/ and /admissions/fortis-institute/ show
   Open Admission and their 2024–25 requirements; on each new page the JSON-LD parses, its FAQPage questions are the
   page's FAQ and Freestar tags are present; /admissions/ loads and its high-acceptance filter lists the new
   open-admission colleges.
   If anything breaks, undo in reverse order: `bash scripts/admissions/phase2_cd_live.sh revert <M log>`, then the S
   log, `bash scripts/admissions/phase2_e_live.sh revert <log>`, `bash scripts/admissions/phase2_s_live.sh revert <log>`.
7. Changelog: one row each for the new pages, Fortis's fields, S and M, each with its undo.

## Identity review: fresh figures for 212 more pages, and 19 closed or merged colleges (R)

Within the go of 05:58, on E's and C's terms: it kept the 399 live pages without a confident match unchanged pending
review, and `scripts/admissions/phase2_r_review.py` is that review (`data/admissions/audit/phase2_r_review.csv` has
every page, the college it is now and why). It uses the audit's own evidence: a college whose exact name, city and
state an older IPEDS directory gives to a UNITID that IPEDS 2024 still lists under a new name (Adirondack Community
College is SUNY Adirondack), or a chain of UNITIDs linked by IPEDS's NEWID. A page gets E's fields only when that
college is open in IPEDS 2024, operating in College Scorecard and no other page here has it. Two judgment calls
settle which college a page is and seven leave a page unchanged (`MANUAL` in the script). Runs after "B2, second
list"; P and N below wait for Digant's word.

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-r.sql.gz`).
2. Fresh figures: `bash scripts/admissions/phase2_e_live.sh plan data/admissions/audit/phase2_r_import.csv` must end
   "212 posts would change, 0 already match, 0 skipped" (report any skip), then the same command with `apply`.
3. Closed and merged colleges: `bash scripts/admissions/phase2_cd_live.sh plan R` must end "19 rows ready, 0 fields
   to copy" (16 retire: 15 DeVry campuses and University of Phoenix's Colorado campus, each with a closing date in
   IPEDS; 3 x 301: Milligan College to Milligan University, Southwest Georgia Technical College to Southern Regional
   Technical College, Northwood University's Texas campus to its Michigan page, which step 2 updates). Then
   `apply R` and `check R` ("38 addresses checked, 0 wrong").
4. Check: /admissions/pennsylvania-state-university-main-campus/ shows 61% for fall 2024 (53,579 of 88,478
   applicants), SAT 620–700 reading and writing and 620–720 math; /admissions/miami-university/ 75% (29,843 of
   39,580); /admissions/arizona-state-university/ 90% (63,756 of 70,928); /admissions/devry-university-utah/ answers
   410 and /admissions/milligan-college/ lands on /admissions/milligan-university/. On each updated page the JSON-LD
   parses and Freestar tags are present.
   If anything breaks: `bash scripts/admissions/phase2_cd_live.sh revert <R log>`, then
   `bash scripts/admissions/phase2_e_live.sh revert <log>`.
5. Changelog: one row for the 212 pages' fields and one for R, each with its undo.

## P: 16 more pages into S's new pages (with S)

Runs right after "Held pages" step 4, only if Digant approves S: redirects of the same kind, to the same six pages,
that the identity review found. Ivy Tech's nine regional pages and its Bloomington page go to Ivy Tech Community
College, Hibbing, Itasca, Mesabi Range and Vermilion to Minnesota North College, Baker College of Flint to Baker
College and Olney Central College to Illinois Eastern Community Colleges.

1. `bash scripts/admissions/phase2_cd_live.sh plan P` must end "16 rows ready, 0 fields to copy" (a `SKIP` for a
   target that isn't published means S's pages aren't there yet), then `apply P` and `check P` ("32 addresses
   checked, 0 wrong").
2. Changelog: one row with its undo, `bash scripts/admissions/phase2_cd_live.sh revert <P log>`.

## N: pages for seven colleges formed by mergers, and 30 redirects to them (waits for Digant's word)

Like S: several of our pages now report to IPEDS as one college that has no page here. Connecticut State Community
College (12 former colleges), Coastal Alabama Community College (3), Metropolitan Community College-Kansas City (5),
Vermont State University (4), Long Island University (2), Pierce College District (2) and Montgomery County Community
College (2) each get a page with E's fresh fields (`phase2_n_new.csv`, `phase2_n_pages.csv`), and the 30 pages 301
there (checkpoint N in `phase2_r_actions.csv`).

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-n.sql.gz`).
2. New pages: `bash scripts/admissions/phase2_s_live.sh plan N` must end "7 pages would be added, 0 skipped" (report
   any `SKIP`), then `bash scripts/admissions/phase2_s_live.sh apply N`; it ends "added 7, skipped 0" and names the
   log. Then `bash scripts/admissions/phase2_e_live.sh plan data/admissions/audit/phase2_n_pages.csv` must end "0 posts
   would change, 7 already match, 0 skipped".
3. N: `bash scripts/admissions/phase2_cd_live.sh plan N` must end "30 rows ready, 0 fields to copy", then `apply N` and
   `check N` ("60 addresses checked, 0 wrong").
4. Check: /admissions/connecticut-state-community-college/ shows Open Admission and 36,315 undergraduates (fall 2024);
   /admissions/long-island-university/ 86% for fall 2024; the JSON-LD parses and Freestar tags are present on each.
   If anything breaks: `bash scripts/admissions/phase2_cd_live.sh revert <N log>`, then
   `bash scripts/admissions/phase2_s_live.sh revert <log>`.
5. Changelog: one row for the new pages and one for N, each with its undo.

## C and D: retire closed colleges, redirect merged ones and duplicates

The list is `data/admissions/audit/phase2_cd_actions.csv`, made by `scripts/admissions/phase2_cd_actions.py` from the
audit lists: C has 287 pages to retire (410 Gone: 257 closed colleges and 30 University of Phoenix campuses whose
successor closed too or is no longer listed) and 40 to redirect (301 to the successor's page); D has 4 to redirect. The
25 merged pages in `phase2_c_held.csv` have no row and stay as they are, each with a recommended treatment for Digant.
Each go covers only its own checkpoint's rows; run D before C, since five C rows redirect to pages D keeps.
`scripts/admissions/phase2_cd_live.sh` does the rest; each rule covers the page's /admissions/ address and its old
/admission/ one.

For D, the page that stays first takes the 26 fields listed in `phase2_d_consolidate.csv` (admission requirements,
AP, dual and life-experience credit the duplicate had and the survivor lacks, checked against the Phase 1 IPEDS
record; Georgia Military College's "credit for life experiences: Yes" isn't copied because IPEDS says No). Each value
is logged before it is written and copied only while the survivor's field is empty or "-". Numbers that change every
year (enrollment, net price, scores) come later from E's import, with their year. If D was applied before this list
existed, run `plan D` and `apply D` again: the rows skip (already done) and only the fields are copied, under a new log.

1. Database backup as in B, step 1 (`...-pre-admissions-d.sql.gz` or `-c`).
2. Dry run: `bash scripts/admissions/phase2_cd_live.sh plan D` (or `C`). It changes nothing. Every row should read
   `ok`, and D should list 26 "copy" lines. Report any `SKIP` row, any "not copied" warning and any "active non-exact
   rule" line before going on. Compare the printed sample of how an existing /admission/ rule stores its source with
   the new rules (`admission/<slug>`, no domain, no slashes); stop and report if the existing ones look different. If
   the plan warns that a Rank Math method is missing, stop and report: apply would refuse.
3. `bash scripts/admissions/phase2_cd_live.sh apply D` (or `C`). Note the log name it prints. It ends with
   "applied N, skipped N, copied N fields".
4. Check: `bash scripts/admissions/phase2_cd_live.sh check D` (or `C`) must end in "0 wrong". Then /admissions/ and
   the colleges sitemap no longer list those pages (the sitemap can take a few minutes), a page that receives a 301
   (D: /admissions/georgia-military-college/; C: /admissions/south-louisiana-community-college/) loads, D's survivors
   show the copied requirements, and ads show. If anything breaks: `bash scripts/admissions/phase2_cd_live.sh revert
   <log name>`, then report.
5. Changelog: one row per checkpoint with the number of pages unpublished, 410 and 301 rules, fields copied, the log
   name and the undo command.

Undo: `bash scripts/admissions/phase2_cd_live.sh revert <log name>` puts the copied fields back as they were,
publishes the logged pages again and deletes their rules.

## Old /admission/ addresses

Digant's go: "Fix the missing legacy redirects next". `data/admissions/redirects/legacy_redirect_map.csv`, made on
GitHub by `scripts/admissions/legacy_redirects.py` with the IPEDS directories back to 2002, has 150 old slugs that end
on a 404 today (or on WordPress's guess, which lands on a 404 for all but one): 41 get a 301 to their college's page
(slugs that changed, such as alabama-a-and-m-university, and mergers such as the six colleges now part of Dallas
College, Georgia's 2013-2018 mergers, Purdue Northwest and Berklee), 109 a 410 because IPEDS shows the college, or the
one it merged into, closed (Argosy, Brown Mackie, Everest, Vatterott, Virginia College, Birmingham-Southern, Wells and
others). Each rule covers
`admission/<old>` and `admissions/<old>`. The 112 in `legacy_unresolved.csv` stay as they are, with the reason (no
college found, a college with no page here yet, or IPEDS shows neither a closing nor a successor);
`legacy_existing_wrong.csv` is empty: no existing Rank Math redirect sends an old address to another college.

Run this after C and D: some 301s point at pages they keep, and the plan checks each target is published.

1. `git pull`, then a database backup as in B, step 1 (`...-pre-admissions-legacy.sql.gz`).
2. Dry run: `bash scripts/admissions/legacy_redirects_live.sh plan`. It changes nothing and should end "150 rows
   ready, 0 already done, 0 skipped". Report every `SKIP` line (a page uses the old slug again, a 301 target that
   isn't one published page, or an address another active rule answers) before going on.
3. `bash scripts/admissions/legacy_redirects_live.sh apply`; note the log name it prints. It ends "added N rules, 0
   already there, N skipped".
4. Check: `bash scripts/admissions/legacy_redirects_live.sh check` should end "300 addresses checked, 0 wrong" (rows
   skipped in step 2 show up as WRONG). Then /admission/brookhaven-college/ lands on Dallas College's page
   (/admissions/el-centro-college/), /admissions/augusta-state-university/ on Augusta University's, and
   /admission/argosy-university-atlanta/ answers 410. A 410 that becomes a 301 to some other college is WordPress's
   slug guess: it stops once the exact-slug 404 guess (functions.php from 759f5b7) is live. If anything else
   breaks: `bash scripts/admissions/legacy_redirects_live.sh revert <log name>`, then report.
5. Changelog: one row with the number of 301 and 410 rules, the log name and the undo command.

Undo: `bash scripts/admissions/legacy_redirects_live.sh revert <log name>` deletes the rules it added.

Don't switch on Rank Math's inactive regex rule `^admission/(.+)/?$`: it would send renamed colleges to dead addresses.
