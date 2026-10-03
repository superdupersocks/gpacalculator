# Legacy redirects: first pass (Mac session, 2026-10-02)

Before `legacy_redirect_map.csv` reached the Mac, `live.sh` added 19 rules for `admission/<old slug>` only, from
`actions-20261002-0631.csv` (log `admissions-legacy-20261002-063105-log.tsv`). 17 of them were then deleted in favour of
the fuller map (`scripts/admissions/legacy_redirects_live.sh`, which also covers `admissions/<old slug>`). Two stay,
because the map has no row for them although the college has a page here:

- `admission/union-institute-and-university` → `/admissions/union-institute-university/`
- `admission/texas-a-and-m-university-commerce` → `/admissions/texas-a-m-university-commerce/` (East Texas A&M today)

`actions.csv` then added the `admission/` rules for three map rows that `legacy_redirects_live.sh` skipped because an
older rule already answers their `admissions/` address (log `admissions-legacy-20261002-064643-log.tsv`).
`held.csv` is this pass's own list of unresolved slugs; `data/admissions/redirects/legacy_unresolved.csv` supersedes it.

Undo: `bash scripts/admissions/legacy_first_pass/live.sh revert <log>`.
