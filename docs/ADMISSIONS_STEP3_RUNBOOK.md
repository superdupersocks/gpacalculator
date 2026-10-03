# Admissions step 3: new college template, server runbook (Digant's plan, 2026-10-03)

Server steps for college template v2, run from the repo clone on Digant's Mac (`~/Documents/Claude/gpacalculator`,
branch `claude/admissions-data-phase1-bsjda7`, after the step 3 PR is merged into it). Run `git pull` first. Nothing
here runs before Digant types the go in the admissions thread. Log every live change in `docs/LIVE_CHANGELOG.md` with
its undo, push, and report in the thread. `SSH` and `WP` are as in `docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's plan (project chat, 2026-10-03 00:38 UTC), step 3: "New template from the mockup: compare box (open-admission
version where needed), data-driven FAQs replacing the current ones (hide below 3), similar colleges + state hub,
official admissions link. Put it behind a feature flag by tier. Show me 5 sample pages across tiers, then turn it on
for tier A only. → Checkpoint."

What it is (`child-theme/generatepress-child/college-v2.php`, `college-compare.js`, `single-colleges.php`):
- **Switch by tier.** Post meta `admissions_tier` (step 2) and the option `gpa_admissions_v2_tiers` (the tiers that get
  v2). No option = every page on today's template. Logged-in editors can preview any page with `?gpa_v2=1`.
- **Compare box** under the quick facts: GPA (unweighted or weighted) and an optional ACT or SAT score. A GPA is placed
  only against an average the college published in its Common Data Set with a stated basis, and only on that basis;
  otherwise the box says why it can't. The SAT range is the two section ranges added together, labeled approximate.
  Open-admission colleges get a short version with no inputs. Pages with no figures (under review) get no box.
- **FAQs** built from the page's own figures (can I get in with a X GPA, what GPA you need, acceptance rate, is it
  hard to get into, SAT/ACT required, SAT/ACT scores, how much GPA matters, AP credit, net price), each only when the
  data answers it. Fewer than three: no FAQ section and no FAQPage (both come from `gpa_college_faqs()`).
- **Order after the FAQ** (Digant, 2026-10-03 04:52): FAQ, "Before you apply", Similar colleges, Keep exploring,
  Sources. Those three H2s are unnumbered and stay out of the TOC; every section H2 sits 64px below what's above it
  (48px on phones).
- **Similar colleges in {State}**: up to five indexed colleges in the same state and of the same kind (4-year or
  2-year), closest in acceptance rate and size, as link cards; open-admission colleges get other open-admission
  colleges. **Keep exploring**: the hub filtered to the state (`/admissions/?search=<State>`, noindex like every
  filtered hub view) and the weighted GPA calculator. The compare box's buttons are "Colleges where a {GPA} fits" (the
  state's colleges that admit 50% or more, since the hub has no GPA filter) and "Plan the grades I need" (Raise GPA
  calculator); its help line links the high school GPA calculator. Each target linked once per page.
- **Official admissions link** ("Before you apply"): post meta `college_admissions_url` and
  `college_admissions_url_kind`, from `data/admissions/audit/step3_admissions_links.csv`. Every address was requested
  from GitHub (`admissions-links.yml`, `data/admissions/step3/links_status.tsv`); a page links only to an address that
  answered 200 on the college's own site: the admissions office page IPEDS lists, else the college's website, else
  nothing. 2,701 of 3,032 colleges with an IPEDS ID get one (2,466 admissions pages, 235 websites); 331 none.

Samples (local preview of the live data, `scripts/admissions/preview/setup_local.sh` + `render.py`):
`/mnt/project-files/admissions/step3/` — Harvard and UCLA (A), Miami University and Calvin (B), Lone Star College
(C, open admission), desktop and phone, with the compare box filled in.

## Steps (the go: tier A)

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-step3.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. Links: `bash scripts/admissions/step3_links_live.sh plan`. Expect "pages 3085, no IPEDS ID 53, no checked link 331,
   already right 0; to write 2701" (counts move only if pages were added or removed). Then `apply` (note the log).
   Today's template doesn't read these fields, so nothing visible changes.
3. Theme: `bash scripts/admissions/deploy_phase3.sh --dry-run`, then without `--dry-run`. It stops if a live file has
   changes this commit lacks. With the option unset, every page still shows today's template: check
   /admissions/harvard/ and /admissions/lone-star-college-system/ look as before, and /admissions/harvard/?gpa_v2=1
   (logged in) shows the compare box, the new FAQ, similar colleges and "Before you apply".
4. Switch: `bash scripts/admissions/step3_switch.sh set A` (prints the old value and clears caches).
5. Check (logged out, desktop and phone): /admissions/harvard/ and /admissions/ucla/ show v2 (type a GPA: the verdict
   updates); /admissions/miami-university/ (B) and /admissions/lone-star-college-system/ (C) don't; Harvard's
   JSON-LD FAQPage lists the same questions as the page; ads show (in-content and rails); no sideways scroll at 390.
6. Changelog rows (links, theme, switch) with their undos; re-run the indexing check (edit the "taken for" line in
   `data/admissions/live_checks/indexing_urls.txt` and push).

## Undo

- Switch everything back to today's template: `bash scripts/admissions/step3_switch.sh off`.
- Links: `bash scripts/admissions/step3_links_live.sh revert <log>`.
- Theme: `bash scripts/deploy_theme.sh --revert <the backup the deploy printed>`.

## Later

- Tiers B and C: `step3_switch.sh set A,B` (and `A,B,C`) after Digant's word.
- The 331 colleges without a checked link: 148 sites answered 403 to GitHub's runner (bot blocking, not a missing
  page) and 111 answered 404; a re-check from another network or a manual pass could add some.
- State hub: the link goes to the hub's search for the state, so "Virginia" also lists West Virginia colleges. Real
  state pages (/admissions/<state>/, indexable) would be their own build.
