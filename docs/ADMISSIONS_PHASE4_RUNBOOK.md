# Admissions Phase 4: server runbook

Server steps for what search engines get from /admissions/, run from the repo clone on Digant's Mac
(`~/Documents/Claude/gpacalculator`, branch `claude/admissions-data-phase1-bsjda7`). Run `git pull` before each step.
Each step runs only after Digant types its go in the admissions thread. Log every live change in
`docs/LIVE_CHANGELOG.md` with its undo, push, and report in the thread. `SSH` and `WP` are as in
`docs/ADMISSIONS_PHASE2_RUNBOOK.md`.

Digant's go to start Phase 4: "let's kick off phase 4" (2026-10-02 22:47 UTC). The audit (read-only) is in
`data/admissions/live_checks/indexing.md` (scripts/admissions/indexing_check.py, run on GitHub by
admissions-indexing.yml) and the Phase 4 tab of the admissions doc. What it found:

- The colleges sitemaps list each of the 3,085 published colleges once, plus the hub, and none of the retired or
  redirected addresses; robots.txt points to the sitemap index. But 2,996 addresses still give lastmod 19 April 2026:
  the Phase 2 and 3 imports changed fields without touching the posts' dates. Google last read the index at 00:46 UTC
  on 2 October, before all of that day's changes.
- /admissions/page/2/ to /admissions/page/309/ answer 200, indexable, each its own canonical, all showing the same
  first 30 colleges (the hub's head links to page 2).
- College pages: the CollegeOrUniversity's url is our page, an "Admissions" EducationalOccupationalProgram carries the
  net price, and there's no WebPage, WebSite or Organization node. The hub has two CollectionPage nodes.
- The 53 pages under review (checkpoint H) are indexable and in the sitemap with no figures.

## 1. Code: the hub's pages, structured data, pages under review

Code: `college-data.php`, `functions.php`, `template-parts/college-db-archive.php`, `database-ajax.js` and
`admissions.css`, all among the seven files `scripts/admissions/deploy_phase3.sh` ships. Before the deploy command goes
to Digant, merge the design branch head into this branch (the design thread's rails fix changes
`gpa_freestar_siderails` in functions.php) and set `COMMIT` in deploy_phase3.sh to the merge, so the deploy keeps
everything the design shipped. The command stays the same:
`cd ~/Documents/Claude/gpacalculator && git pull && bash scripts/admissions/deploy_phase3.sh`.

What changes:
- The hub's own pages: /admissions/page/N/ lists colleges 30(N-1)+1 to 30N by name (page 103 has the last 25), its
  title ends "– Page N", and "Show more colleges" is a link to the next page that the script still loads in place;
  pages after 103 are 404s. A search or filter from page N goes back to /admissions/?search=....
- College pages: one page node (WebPage, and FAQPage when the page has questions, as on the calculator pages) about
  the college, with its breadcrumb and dates; the CollegeOrUniversity without our page as its url, with the former
  name as alternateName (Calvin College); the site's WebSite and Organization (logo) nodes; no
  EducationalOccupationalProgram.
- The hub: Rank Math's CollectionPage alone, with the description and the page's ItemList (numberOfItems 3,085).
- Pages under review: noindex, follow, and left out of the colleges sitemap until their figures are verified.

Checks after "deployed" (the GitHub indexing check, `data/admissions/live_checks/indexing_urls.txt`, plus a look on
desktop and phone):
- /admissions/: as before; "Show more colleges" adds 30 cards and the address stays /admissions/.
- /admissions/page/2/: Allan Hancock College first, title "... – Page 2", canonical itself; /admissions/page/103/: 25
  colleges, no "Show more"; /admissions/page/104/: 404.
- /admissions/harvard/: JSON-LD has CollegeOrUniversity (no url), WebPage + FAQPage with six questions, Organization,
  WebSite, BreadcrumbList, and nothing else; /admissions/calvin/: alternateName "Calvin College".
- /admissions/fairfax-university-of-america/: robots "follow, noindex".
- The colleges sitemaps: 3,033 addresses (3,032 colleges and the hub), none under review.
- Changelog row; undo `bash scripts/deploy_theme.sh --revert <backup>`.

## 2. Modified dates and the sitemap cache

`scripts/admissions/phase4_dates_live.sh` moves each published college post's modified date to 22:32 UTC on
2 October 2026 (the Phase 3 template deploy, which changed every page) unless it is later already, logging the old
dates, then clears Rank Math's sitemap cache. Only post_modified and post_modified_gmt change.

1. Database backup: `SSH 'cd WP && wp db export - | gzip > ~/backups/gpacalculator-<UTC date-time>-pre-dates.sql.gz'`,
   then `gunzip -t`, the dump ends with "-- Dump completed", copy it to `~/gpacalculator-backups/`, SHA-256 matches.
2. `bash scripts/admissions/phase4_dates_live.sh plan`: 2,668 posts would move if the 417 renamed at 22:39 already
   carry that date, 3,085 if they don't (then the stale lastmod wasn't the cache); it also prints how Rank Math stores
   its sitemap cache.
3. `bash scripts/admissions/phase4_dates_live.sh apply` (note the log name it prints).
4. Run the indexing check again: the colleges sitemaps' lastmod should read 2026-10-02 for every address. If they still
   say 19 April, the cache wasn't cleared: Rank Math > Sitemap Settings > Save Changes, then check again.
5. Changelog row with the undo `bash scripts/admissions/phase4_dates_live.sh revert <log>`.

## 3. Search Console (Digant, in the browser)

1. Search Console > Sitemaps: submit `sitemap_index.xml` again (it was last submitted on 20 September).
2. URL Inspection: `https://gpacalculator.net/admissions/`, then Request indexing.

## 4. Follow-up

Search Console checks against the September baseline (`search-console-findings-2026-10` in project memory) on
16 and 30 October, and 13 and 27 November 2026, reported in the admissions thread: clicks, impressions, position and
CTR for /admissions/ and the old /admission/ addresses together, and the pages that dropped.
