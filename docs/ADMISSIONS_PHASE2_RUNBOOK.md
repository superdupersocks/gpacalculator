# Admissions Phase 2: server runbook

Server steps for the /admissions/ overhaul, run from the repo clone on Digant's Mac. Run `git pull` before each
checkpoint, not just once: steps get fixed and added while the others run. Each checkpoint runs only after Digant types
its go in the admissions thread, one checkpoint at a time. Log every live change in `docs/LIVE_CHANGELOG.md` with its
undo, push, and report in the thread.

Digant's go (2026-10-02 05:58 UTC): B, D and C, with these limits. B removes the unsupported claims and the competitor
images; the cited CDS GPAs replace them in step B2 once it has its steps below. D copies useful details into the
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

## B2: cited Common Data Set GPAs

Digant's go for B includes "Replace GPA with verified, cited CDS values wherever available". Commit 9a803a3 can't show
a GPA with the label, year, weighted or unweighted basis and source link that Digant's rules require, so this step adds
that to the template and imports the values into their own fields. Its steps land here when the theme change and the
import script are pushed. Until then, don't write any CDS value into `average_gpa` or any other field.

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

148 old /admission/ addresses that Search Console still shows have no redirect (90 end in a 404). Digant's go covers
fixing them. The cloud session is building the map (old address -> the college's current page, or 410 for a college
that is gone) from `data/admissions/redirects/` and the Phase 1 match, with a script like C's; its steps land here.
Don't switch on Rank Math's inactive regex rule `^admission/(.+)/?$`: it would send renamed colleges to dead addresses.
