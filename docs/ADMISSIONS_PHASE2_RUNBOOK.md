# Admissions Phase 2: server runbook

Server steps for the /admissions/ overhaul, run from the repo clone on Digant's Mac (`git pull` first). Each
checkpoint runs only after Digant types its go in the admissions thread ("go B", "go C", "go D"), one checkpoint at a
time. Log every live change in `docs/LIVE_CHANGELOG.md` with its undo, push, and report in the thread.

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
4. Theme first, so the listing stops sorting by GPA before the GPAs go:
   `bash scripts/deploy_theme.sh 9a803a3 --dry-run --only functions.php,archive-colleges.php,template-parts/college-db-archive.php,single-colleges.php,database-ajax.js`.
   Only those five files may change. Then the same command without `--dry-run`; note the theme backup name.
5. Data: `bash scripts/admissions/phase2_b_live.sh apply <export name>`. The leftover count must be 0.
6. Check:
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

## C and D: retire closed colleges, redirect merged ones and duplicates

The list is `data/admissions/audit/phase2_cd_actions.csv`, made by `scripts/admissions/phase2_cd_actions.py` from the
audit lists: C has 312 pages to retire (410 Gone) and 40 to redirect (301), D has 4 to redirect. Each go covers only
its own checkpoint's rows. Five C rows redirect to the pages D keeps; if Digant's go for D keeps other pages, change
`D_KEEP` in `phase2_cd_actions.py`, rerun it, commit, and only then run C or D. `scripts/admissions/phase2_cd_live.sh`
does the rest; each rule covers the page's /admissions/ address and its old /admission/ one.

1. Database backup as in B, step 1 (`...-pre-admissions-c.sql.gz` or `-d`).
2. Dry run: `bash scripts/admissions/phase2_cd_live.sh plan C` (or `D`). It changes nothing. Every row should read
   `ok`. Report any `SKIP` row and any "active non-exact rule" line before going on. Compare the printed sample of
   how an existing /admission/ rule stores its source with the new rules (`admission/<slug>`, no domain, no slashes);
   stop and report if the existing ones look different.
3. `bash scripts/admissions/phase2_cd_live.sh apply C` (or `D`). Note the log name it prints.
4. Check: `bash scripts/admissions/phase2_cd_live.sh check C` (or `D`) must end in "0 wrong". Then /admissions/ and
   the colleges sitemap no longer list those pages (the sitemap can take a few minutes), a page that receives a 301
   (e.g. /admissions/south-louisiana-community-college/) loads, and ads show. If anything breaks:
   `bash scripts/admissions/phase2_cd_live.sh revert <log name>`, then report.
5. Changelog: one row per checkpoint with the number of pages unpublished, 410 and 301 rules, the log name and the
   undo command.

Undo: `bash scripts/admissions/phase2_cd_live.sh revert <log name>` publishes the logged pages again and deletes their
rules.
