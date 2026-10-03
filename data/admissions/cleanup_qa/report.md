# Admissions cleanup QA (step 1 of Digant's plan, 2026-10-03)

Every /admissions/ college page the cleanup removed, what it and the older addresses around it answer on the live site
today, and the fixes proposed for the checkpoint. Built by `scripts/admissions/cleanup_qa.py` (`urls`, `renames`,
`report`) from the pre-cleanup export (`data/colleges/`, 2026-10-01), the Phase 2 lists, 12 months of Search Console
(`data/admissions/search_console/pages_12m.csv`, 2025-10-03 to 2026-10-02) and a live check run on GitHub
(`.github/workflows/admissions-cleanup-qa.yml`, 2026-10-03 00:54–01:14 UTC). Nothing on the site changed.

## Files

| File | What |
| --- | --- |
| `urls.tsv` | the 2,280 addresses checked: both forms (/admissions/ and /admission/) of the 516 removed pages, the old addresses Phase 2 gave rules or left unresolved, and every other non-live address Search Console showed |
| `live_status.tsv` | what each answers today: first status, each hop, the final status |
| `removed_urls.csv` | the 516 removed pages: planned answer, both forms' answers, clicks, flag, fix |
| `other_addresses.csv` | the older addresses that are flagged or get a fix |
| `fixes.csv` | the 330 addresses to change (input of `scripts/admissions/cleanup_fix_live.sh`) |
| `leftover_pages.csv` | the 42 WordPress pages left under /admissions/ |
| `renames.csv` | the 320 college pages whose address carries an older name, with the proposed address |
| `internal_links.tsv`, `internal_link_fixes.csv` | links into /admission(s)/ on the 424 other pages, and the ones to fix (none) |

## What the check found

**Removed pages: all as planned.** The cleanup removed 516 college pages (Phase 2 checkpoints C, D, M, N, P, R, S).
Both forms of every address answer what Phase 2 planned, in one step: 368 retired pages answer 410, and 148 merged or
consolidated pages answer one 301 to a live college page. No chains, no 404s.

**13 retired pages had clicks** in the last 12 months (14 clicks, 1,071 impressions; 1 or 2 each), so Digant's rule
turns their 410s into 301s: DeVry University-Washington to DeVry's main page, University of Phoenix-Indianapolis to
University of Phoenix, and the other 11 (closed, no successor) to the hub's list for their state
(`/admissions/?search=<State>`).

**Older addresses** (the 1,248 /admission/ and /admissions/ addresses from before the cleanup):
- 6 chains of two redirects (LIU Post and LIU Brooklyn through their old campus pages to Long Island University, USF
  St. Petersburg, Texas A&M Galveston): point them straight at the last page.
- 109 dead ends: a 301 to a page that now answers 410 or 404 (mostly old rules whose target a later checkpoint
  retired, such as the DeVry Keller campuses). Without clicks they answer 410 themselves; with clicks, a 301 as above;
  for a campus of a college that has a page here, a 301 to it.
- 12 old 410s with clicks (Argosy Phoenix, Cardinal Stritch, Morrison, ITT Bradenton, system offices): 301s to the
  system's flagship or the state's list.
- 191 addresses answer 404 (Phase 2 left 112 old addresses unresolved). 30 belong to a college that has a page here
  under another name (Long Island University's campuses, Roger Williams law school, Antioch, Ottawa Kansas City,
  University of Phoenix's Phoenix campus, JWU Online, Whitworth's adult programs, Central Methodist's graduate school):
  301s to that page. Three with clicks get 301s: Kingston University (43 clicks, the most of any; no page or state on
  record) to the hub, an old duplicate University of St. Thomas address to the hub's search for "St. Thomas", and
  Brown Mackie College-Indianapolis to Indiana's list. 144 belong to colleges that closed (IPEDS, or FSA's closed-school file for 19 that IPEDS 2024 still listed) or that
  IPEDS never listed: 410. 12 stay 404: University of Minnesota-Twin Cities (1,602 impressions; it has no page here
  at all, a gap worth a page) and five colleges that may still be open (Aspen University, Ross College-Davenport,
  Ohio Business College-Sandusky, Paier College, St. Augustine College).

**42 leftover WordPress pages.** The page sitemap lists 42 pages under /admissions/ (children of the old "Admissions"
page, all last saved 2026-07-15). The colleges post type answers those addresses, so no visitor sees them, but
search engines get 41 addresses from that sitemap that answer 404, 410 or a 301. They also cause the extra hops above:
WordPress's 404 guess sends /admission/<slug>/ to the page with that slug, which overrides a Rank Math 410 (23 old
addresses go 301 to a 404 or 410 because of them). Unpublishing them (draft) fixes both; reproduced and tested on a
local copy.

**Internal links: none to fix.** 424 pages (every page in the other sitemaps, the hub and two college pages for the
header and footer) carry 513 links into /admission(s)/, all to live college pages or the hub. The college pages'
own text has no links into /admission(s)/ (pre-cleanup export).

**Backlinks.** Search Console's API (through Windsor.ai) has no link data, and GA4 shows no visits from other sites
to any retired address in 12 months. Search Console > Links > "Top linked pages" (external) exported as a file would
show linked addresses that send no visits; any retired address in it gets a 301 the same way.

**Renamed colleges.** 320 college pages keep an address with an older name. 302 are renamed colleges (IPEDS name
history, or the former name the Phase 3 title fixes found): Maharishi University of Management to
`maharishi-international-university`, Adams State College to `adams-state-university`, Houston Baptist to
`houston-christian-university`, SUNY College at Cortland to `suny-cortland`, and so on. Their addresses had 18 clicks
and 10,054 impressions in 12 months. Each moves to the new address with a 301 from both old forms, and every rule that
pointed at the old address is pointed at the new one. 18 more only add a town, state or campus word
(`palm-beach-atlantic-university-west-palm-beach`): optional, left as they are.

## Proposed for the go

1. A database backup.
2. `fixes.csv`: 330 addresses (235 to 410, 95 to 301) for 179 old slugs.
3. Unpublish the 42 leftover pages.
4. Move the 302 renamed colleges.

All three run from `scripts/admissions/cleanup_fix_live.sh apply-all` into one log; `revert <log>` undoes them. Steps:
`docs/ADMISSIONS_CLEANUP_RUNBOOK.md`.
