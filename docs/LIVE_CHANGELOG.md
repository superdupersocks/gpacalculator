# Live changes on gpacalculator.net

Every change made to the live site, newest first: when (UTC), where, what, and how to revert. Backups named
`~/backups/...` are on the server (outside the web root) and copied to `~/gpacalculator-backups/` on Digant's Mac.
Content edits went through WordPress, so each page also has a revision (Pages > Edit > Revisions). Revisions are
enabled and unlimited (`WP_POST_REVISIONS` is not set in wp-config.php; `wp_revisions_to_keep()` returns -1).

Restoring a full database backup undoes everything after it, so prefer the narrower revert listed per change.
Full DB restore: `gunzip -c ~/backups/<file>.sql.gz | wp db import -` then `wp breeze purge --cache=all`.

## Rules (Digant, 2026-10-01)
1. Content: edit through WordPress so a revision is saved.
2. Code (theme, plugins): commit to the repo first, then deploy from it (`scripts/deploy_theme.sh`); never edit server files directly.
3. Settings, plugins, menus: Digant dropped the Cloudways on-demand backup requirement (2026-10-01 21:41). Before these
   changes take a database backup (`wp db export` to `~/backups/`, copied to the Mac) and note the old value or state here.
4. After every push: affected pages load, calculators work, ads show. If anything breaks, revert at once and report.
5. Log every live change here.

## 2026-10-01

| Time (UTC) | URL(s) | What changed | Revert |
| --- | --- | --- | --- |
| 21:12 | /weighted-gpa-calculator/ (page 36303) | Internal Link Juicer keyword set to "weighted GPA calculator" (was empty). Checked: page loads, calculator renders, ads fill. | `wp post meta update 36303 ilj_linkdefinition '[]' --format=json`; DB backup `gpacalculator-2026-10-01-pre-ilj.sql.gz` |
| 21:03 | 31 × /gpa-scale/x-x-gpa/ | FAQ replaced with 6 new questions per page (Rank Math FAQ block); 18 duplicate internal links unlinked on 8 pages (`content/gpa-scale-link-changes.md`). | Page revisions; DB backup `gpacalculator-2026-10-01-pre-faq.sql.gz` |
| 21:03 | theme `content-styles.css` | FAQ card styles appended (overrides the older FAQ rules). Applied on top of the live file, not deployed from the repo. | `~/backups/pre-faq-content-styles.css` → copy back over the live file |
| 20:44 | /gpa-scale/ (hub, page 22335) | Grade points values in the scale table link to the 4.0 … 1.0 pages (11 links). | Page revision |
| 20:43 | 31 × /gpa-scale/x-x-gpa/ | Quick facts wrapped in a quote + lead-in line before the table; external links trimmed to one (NAEP); FAQ converted to a Rank Math FAQ block; template set to `page-templates/template-content.php` on 30 pages (was the unregistered `gpa-content-page`). | Page revisions (template: set "GPA – Content Page" back by hand if needed); DB backup `gpacalculator-2026-10-01-pre-pass5.sql.gz` |
| 20:43 | theme `functions.php` | `gpa_heading_faq_schema()` skips pages with a Rank Math FAQ block (one FAQPage per page). | `~/backups/pre-pass5-functions.php` |
| 20:21 | 30 × /gpa-scale/x-x-gpa/ (not 4.0) | One-structure rewrite (`content/gpa-scale-rewrite/`): quick facts, "Is it good?", "What it means for college", "How to raise" with classes-needed table, FAQ; wrong year notes on 2.x/1.x replaced; 2.2 and 1.1 FAQ fixes. | Page revisions; DB backup `gpacalculator-2026-10-01-pre-rewrite.sql.gz` |
| 20:09 | 28 × /gpa-scale/x-x-gpa/ | 64 sentences pointing at the removed search tool rewritten with calculator links; NAEP citation; weighted-GPA note on 3.5+; 4.0 FAQ block and 3.9 FAQ H2 fixed; pasted `<div>` FAQs unwrapped. | Page revisions; DB backup `gpacalculator-2026-10-01-pre-content.sql.gz` |
| 20:09 | theme `functions.php`, `content-styles.css` | "Updated <date>" under the hero and prev / next / hub links on /gpa-scale/ pages. | `~/backups/pre-content-functions.php`, `~/backups/pre-content-content-styles.css` |
| 20:00 | 31 chart images in /wp-content/uploads/ (`x.x-GPA*.png`, `.webp`) | New GPA scale charts written over the originals (same names and sizes, 512 files incl. sizes and .webp); alt text updated; Rank Math social image set on each page. Cloudflare purged by Digant at 20:30. | `tar xzf ~/backups/gpa-charts-orig-2026-10-01.tar.gz -C wp-content/uploads`, then purge Breeze + Cloudflare; meta: DB backup `gpacalculator-2026-10-01-pre-images.sql.gz` |
| 19:54 | 31 × /gpa-scale/x-x-gpa/ | Rank Math title and description replaced (`content/gpa-scale-meta.md`; old values in the "Current" columns). | Restore the old values from that file, or DB backup `gpacalculator-2026-10-01-pre-meta.sql.gz` |
| 19:47 | /gpa-scale/4-0-gpa/ | Yellow `<mark>` highlight removed from 2 headings. | Page revision |
| 19:44 | 31 × /gpa-scale/x-x-gpa/ | Chart image in the content replaced by a Table block (class `gpa-scale-table`); inline bold/colour removed from 108 headings. | Page revisions; DB backup `gpacalculator-2026-10-01-pre-table.sql.gz` |
| 19:44 | theme `functions.php`, `content-styles.css`, `gpa-design-tokens.css` | Scale-table row highlight filter, table CSS, `--gpa-band-*` tokens. Applied on top of the live files. | `~/backups/pre-table-functions.php`, `pre-table-content-styles.css`, `pre-table-gpa-design-tokens.css` |
| 19:16 | 31 × /gpa-scale/x-x-gpa/ | Classic content converted to blocks; intros added; "admission chances" section and `[CollegeDB]` removed; 3 figure fixes. Revisions were added by hand afterwards (WP-CLI save skipped them). | Page revisions; DB backup `gpacalculator-2026-10-01-pre-blocks.sql.gz` |
| 19:16 | theme `functions.php` | Removed the temporary hide-section filter (19:05). | `~/backups/functions-2026-10-01-pre-blocks.php` |
| 19:05 | theme `functions.php` | Temporary filter hiding the closing "admission chances" section (removed at 19:16). | `~/backups/functions-2026-10-01-pre-fullsection.php` |
| 19:00 | theme `functions.php` | Temporary filter hiding the "List of Colleges" heading (replaced at 19:05). | `~/backups/functions-2026-10-01-pre-listheading.php` |
| 18:45 | plugins | Deactivated `CollegeDB.disabled` and `UniversityTemplate`; deleted the empty `wp-content/plugins/CollegeDB/` folder (held only a `.gitignore`). | `wp plugin activate CollegeDB.disabled UniversityTemplate`; DB backup `gpacalculator-2026-10-01-pre-collegedb.sql.gz` |
| 18:44 | theme `functions.php` | Empty `[CollegeDB]` / `[CollegeDB_full]` placeholder shortcodes. | `~/backups/functions-2026-10-01-pre-collegedb.php` |
| earlier | options | `gpcm_university_profiles` autoload off; leftover options from removed plugins deleted. Not made from this machine (see PROJECT.md); listed for completeness. | Ask whoever made it; no backup recorded here |

Not changed: Rank Math "Open external links in new window" stays on (Digant, 21:11).

## Known gaps against the rules (today's changes were made before the rules)
- Theme files were patched on the server (live file + addition) instead of deployed from the repo. The repo has
  every one of those changes; the only difference between live and the repo is the unreleased theme 1.2 work
  (calculator tokens, `.gpacalc-mount` isolation, version 1.2). Future theme changes go through `scripts/deploy_theme.sh`.
- No Cloudways on-demand backup was taken before the 18:45 plugin deactivation (DB backup only). Cloudways backups
  need access to the Cloudways account or API, which this machine doesn't have.
