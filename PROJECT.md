# gpacalculator.net

Feature-rich, easy-to-use calculators for students, delivered through WordPress
(GeneratePress child theme + one calculator plugin) with plain JS and CSS.

## Architecture

**One plugin owns every calculator; the theme owns site design and brand tokens.**

| Piece | Folder | Role |
| --- | --- | --- |
| **Grade + GPA** (`gpacalculator-manager`, v0.6.0) | `plugin/gpacalculator-manager/` | The one plugin. Its own calculators (`[gpcm_calculator]` university GPA, `[country_grade]` / `[country_grade_scale]` country conversion) plus the calculator engine, which serves every calculator that used to load from the theme or the Calculators plugin. |
| **Calculators** (`calcs-plugin`) | `legacy/calcs-plugin/` | Legacy. Its shortcode list (WP admin > Calculators, option `calcs_plugin_shortcodes`) is read by the engine, which answers every one of those shortcodes with identical markup and handles. Deactivate it after rollout; never shipped from this repo. |
| **CollegeDB** (`CollegeDB.disabled`, deactivated 2026-10-01) | `legacy/CollegeDB.disabled/` | Legacy, not shipped. Registered `[CollegeDB gpa="x.x"]` (on the 31 `/gpa-scale/<x-x>-gpa/` pages) and `[CollegeDB_full]` (unused), an AngularJS table over the `gpa_college_db` table (1,529 rows, kept in the database). The child theme now answers both tags with an empty placeholder. |
| **University Template** (`UniversityTemplate`, deactivated 2026-10-01) | `legacy/UniversityTemplate/` | Legacy, not shipped. Registered `[UniversityTemplate]`, which no post, meta, option or theme file used. |
| **GeneratePress Child** (v1.2) | `child-theme/generatepress-child/` | Site design, page templates, `gpa-design-tokens.css`. `calc-assets/` stays as a fallback copy until cleanup. |

The `/admissions/` college pages are the theme's `colleges` post type (`functions.php`,
`single-colleges.php`, `archive-colleges.php`, `[gpa_college_archive]`), not a plugin. Their 36 custom
fields are plain post meta read through the theme's `get_field()` fallback (ACF is inactive).

Live versions (2026-10-01): Grade + GPA 0.5.1, Calculators 3.0.1, theme as imported. The repo's plugin 0.6.0
and theme 1.2 are not uploaded yet; the live files still match the import commit byte for byte.

Note: the plugin headers say gpacalculator-manager is "Grade + GPA" and calcs-plugin is "Calculators".
The homepage, college and high school GPA calculators are Calculators-plugin shortcodes whose
JS/CSS lived in the theme's `calc-assets/`.

### Repo layout

```
child-theme/generatepress-child/       live child theme (imported)
  gpa-design-tokens.css                site tokens (--gpa-*) + calculator tokens (--gpa-calc-*)
  calc-assets/                         LEGACY copies; removed in cleanup
plugin/gpacalculator-manager/          Grade + GPA (live plugin imported + engine)
  includes/bootstrap.php               loads the engine (one require_once in the main file)
  includes/calculators.php             manifest: hand-written entries / overrides
  includes/legacy-calcs-plugin.php     turns the Calculators plugin's saved shortcodes into entries
  includes/calculator-registry.php     GPACalc_Registry: types, entries, gpacalc_calculators filter
  includes/calculator-assets.php       serves calculator JS/CSS from the plugin (old handles kept)
  includes/shortcodes.php              takes free tags, mount markup, "safe to deactivate" notice
  assets/calc-assets/                  every calculator's JS/CSS/HTML, same filenames as the theme
    core/                              shared core (calc-core.css/js, course-catalog.js)
    _starter/                          template for new calculators (not shipped)
data/gpcm-university-profiles/         the 86 university profiles exported from the site (not shipped)
data/colleges/                         the 3,586 published /admissions/ college records, one JSON each (not shipped)
data/college-db/college_db.json        the legacy CollegeDB plugin's gpa_college_db table (not shipped)
legacy/calcs-plugin/                   Calculators plugin source, for reference + tests (not shipped)
legacy/CollegeDB.disabled/             CollegeDB plugin source as live (not shipped)
legacy/UniversityTemplate/             University Template plugin source as live (not shipped)
tests/                                 QA (python3 tests/run_all.py)
scripts/import_live.py                 pulls uploaded live zips into the repo
scripts/package.sh                     builds installable zips into dist/
scripts/export_colleges.php            read-only WP-CLI export of the colleges (wp eval-file -)
scripts/split_colleges.py              splits that export into data/colleges/
shortcodes.lock                        every shortcode the site uses, with its source
```

### How a calculator reaches a page

**Before:** a page has e.g. `[gpa-calculator]`. The Calculators plugin prints `<div id="root"></div>`
and enqueues `main-js-gpa-calculator` / `main-css-gpa-calculator` from
`…/themes/generatepress-child/calc-assets/`. The theme prints `module` tags, cache-busts, and
prerenders `calc-assets/<name>.html` into the empty `#root`.

**After (Calculators plugin deactivated):** the engine reads the same saved list and, on `init`
priority 20, registers each tag that no other plugin holds. It prints the same markup
(`#root` once per page, `#gpa-converter-app`, `#gpa-conversion-app`), enqueues the same handles
in `<head>`, and points them at the plugin copy when one exists in `assets/calc-assets/`
(otherwise the original URL). Scripts print as `type="module"`, and calculator CSS depends on
`gpa-design-tokens`.

**While the Calculators plugin is active**, it keeps its tags and the engine stays out of the way,
so installing the new plugin changes nothing until you deactivate the old one. Reactivating it is
the rollback.

New calculators are manifest entries in `includes/calculators.php`:

```php
'conversion' => array(
  'title'      => 'Grade Conversion',
  'type'       => 'grade-conversion',   // gpa | university-gpa | grade | grade-conversion | other
  'source'     => 'new',                // theme | gpacalculator-manager | calcs-plugin | new
  'js'         => 'grade-conversion.js', 'css' => 'grade-conversion.css',   // in assets/calc-assets/
  'shortcodes' => array( 'grade_conversion' ),                             // never removed
  'atts'       => array( 'country' => 'us' ),                              // shortcode defaults -> JS
),
```

They render `<div class="gpacalc-mount" data-calc="slug" data-atts="{...}">`, mounted with
`mountsFor(slug)` / `readAtts(el)`. An entry with slug `calcs-<tag>` overrides the one generated
from the Calculators plugin's list.

**Safe to deactivate?** On the Plugins screen the engine lists, per plugin that registers
shortcodes, how many it already covers. Green means deactivate with no page edits.
`tests/check_shortcodes.py` reports the same from the repo.

### Tokens

`gpa-design-tokens.css` (theme) is the single source. Site tokens (`--gpa-font`, `--gpa-text-body`,
`--gpa-text-muted`, `--gpa-border`, `--gpa-bg-*`, `--gpa-radius-*` …) plus a calculator block:
`--gpa-calc-brand-1/2`, `--gpa-calc-tint`, `--gpa-calc-focus`, `--gpa-calc-ink`, `--gpa-calc-font`,
`--gpa-calc-radius-card`, `--gpa-calc-shadow`, `--gpa-calc-shadow-pop`.
`core/calc-core.css` maps each to a local `--calc-*` with a built-in fallback, so calculators
look right without the theme. Never rename a token; add new ones. Site-content rules use
`:not(#root *, .gpacalc-mount *)` so they never reach into a calculator.

### Shared core API (calc-core.js, v1.3.0)

| Area | Exports |
| --- | --- |
| DOM | `h`, `setText` |
| Parsing | `parseScore` (84, 84%, 42/50, "42 out of 50", B+), `parseNumber`, `round`, `fmtPct`, `fmtNum` |
| Grades | `STANDARD_SCALE`, `PLAIN_SCALE`, `gradeFor`, `gpaForLetter`, `bandOf`, `nextGrade` |
| Save | `createStore(key)`: debounced draft autosave, named saves, seen flag; safe when storage is blocked |
| Share | `encodeState`/`decodeState`, `readHash`, `shareUrl`, `clearHash`, `copyText`, `toCSV`, `downloadCSV` |
| Analytics | `createTracker(prefix)`: GA4 `prefix_event`, once per page view unless repeatable |
| Mounts | `mountsFor`, `readAtts` |
| Layout | `mountLayout`, `createResultHero`, `createLivePill`, `createMenu`, `createToast`, `enterToNext`, `wireSavesAndShare` |

Course catalog: `LEVELS` (Regular 0, Honors +0.5, AP/IB/Dual Enrollment +1.0), `COURSES`,
`guessLevel`, `searchCourses`, `weightedPoints`, `courseDatalist`.

### House rules

- Plain JS, no frameworks, nothing on `window`. ES modules.
- Every calculator lives in Grade + GPA. The theme gets no calculator code; no new plugins.
- CSS scoped under `:is(#root, .gpacalc-mount) .calc` plus a unique prefix per calculator.
  Brand values only through tokens with fallbacks.
- 800px max width, no hero inside the calculator, no outer bottom margin.
- Results are live; storage behind try/catch with a unique key per calculator.
- Every calculator ships a Playwright math suite with expected values computed in Python,
  100% passing before delivery.
- Never remove or rename a shortcode or page URL (`shortcodes.lock` + `check_shortcodes.py`).

## Rollout

1. Upload `gpacalculator-manager-0.6.0.zip` (Replace current). Nothing changes while Calculators is active.
2. Upload `generatepress-child-1.2.zip` (Replace current). Adds the `--gpa-calc-*` tokens.
3. Plugins screen: the Grade + GPA notice should say Calculators is fully covered.
4. Purge Cloudflare, deactivate **Calculators**, check the calculator pages. Rollback = reactivate it.
5. Later cleanup: delete theme `calc-assets/`, the Calculators plugin, and move the theme's
   prerender and `main-js` module filter into the plugin.

## Workflow

Live-site rules and the log of every live change: `docs/LIVE_CHANGELOG.md`. Theme deploys: `scripts/deploy_theme.sh <commit>`.


1. **Import live files** (whenever the site was edited outside the repo):
   `python3 scripts/import_live.py --theme <zip> --plugin <zip> --calcs-plugin <zip>`
2. **QA**: `npm install && pip install -r tests/requirements.txt && python3 tests/run_all.py`
   (shortcode guard, core suite, calculator smoke suite, PHP engine + legacy tests).
3. **Package**: `bash scripts/package.sh` builds full theme and plugin zips in `dist/`.
4. **Install**: WordPress upload ("Replace current with uploaded"), then purge Cloudflare.

## Calculator status

Calculators-plugin shortcodes are DB-defined; the live list is saved in
`tests/fixtures/calcs-plugin-export.json` (from Export Settings) and locked. All 11 files below are in
the plugin, byte-identical to live, and pass the mount smoke test.

| Calculator file | Type | Served by engine | Uses core | Math QA |
| --- | --- | --- | --- | --- |
| gpa-calculator (homepage, + prerender) | gpa | Yes, after Calculators is off | No | To port |
| college-gpa-calculator (+ prerender) | gpa | Yes | No | To write |
| high-school-gpa-calculator (v2.9) | gpa | Yes | No | To port |
| high-school-gpa-calc | gpa | Yes | No | To write |
| middle-school-gpa-calculator | gpa | Yes | No | To write |
| raise-gpa-calculator | gpa | Yes | No | To write |
| sgpa-to-cgpa-calculator | university-gpa | Yes | No | To write |
| grade-calculator | grade | Yes | No | To port |
| final-grade-calculator | grade | Yes | No | To write |
| semester-grade-calculator (`[semester-grade-calculator]` and `[semester-gpa-calculator]`) | grade | Yes | No | To write |
| weighted-grade-calculator | grade | Yes | No | To write |
| `[gpa-scale]`, `[gpa_conversion]` (external JS) | grade-conversion | Yes, original URLs | No | To write |
| `[gpcm_calculator id="…"]` on 88 `/college-gpa-calculator/` pages: 87 university profiles (`tests/fixtures/gpcm-university-profiles.tsv`) | university-gpa | Native Grade + GPA | No | Per-profile QA on the site; `gpcm_profiles_test.php` checks each id renders |
| `[country_grade]`, `[country_grade_scale]` | grade-conversion | Native Grade + GPA | No | To write |
| Starter template | — | Yes | Yes | `core_qa.py` |
| College list on the 31 `/gpa-scale/` pages (was `[CollegeDB gpa="…"]`) | other | Removed from the pages; `[CollegeDB]` placeholder still registered | No | To rebuild from Scorecard/CDS data |

## Admissions database (/admissions/)

The `colleges` post type (`/admissions/<slug>/`, hub at `/admissions/`): 3,085 published college pages. Theme files
owned by the admissions work: `single-colleges.php`, `archive-colleges.php`, `template-parts/college-db-archive.php`,
`college-data.php`, `database-ajax.js`, `admissions.css` and the `gpa_college_*`, hub, AJAX, title and description
code in `functions.php`; deploys run `scripts/admissions/deploy_phase3.sh`, which ships the commit set in it (a
commit that has merged the latest design branch, so the design's live changes stay). Data in `data/admissions/` (IPEDS backbone, Scorecard, CDS values with citations, Phase 2 and 3
audit lists, Search Console exports), scripts in `scripts/admissions/`. Data rules: GPA only with a verified
college-published CDS citation, labeled "as reported by the college" with its year and basis; primary sources only;
never estimate.

Done: Phase 1 data, Phase 2 audit and pruning (516 pages removed: 368 answer 410, 148 a 301), Phase 3 hub and
profile template, Phase 4 step 1 (modified dates). Runbooks: `docs/ADMISSIONS_PHASE2_RUNBOOK.md`,
`docs/ADMISSIONS_PHASE3_RUNBOOK.md`, `docs/ADMISSIONS_PHASE4_RUNBOOK.md` (step 2, the hub pages and structured data,
waits on Digant's deploy; the noindex for the 53 pages under review is held for tiering).

Digant's plan of 2026-10-03, each step stopping at a checkpoint for Digant's go:
1. **Cleanup QA**: checkpoint sent 2026-10-03 01:45 UTC, waits on Digant's `go cleanup` (`data/admissions/cleanup_qa/report.md`;
   the admissions doc's "Step 1: cleanup QA" tab). All 516 removed pages answer as planned;
   proposed: 330 old addresses fixed (chains, dead ends, 404s, 410s with clicks), 42 leftover WordPress pages under
   /admissions/ unpublished, 302 renamed colleges moved to addresses with their current name. Server steps:
   `docs/ADMISSIONS_CLEANUP_RUNBOOK.md`.
2. **Tiering**: A complete data, B partial, C open admission or no GPA, with 12 months of Search Console clicks and
   impressions; index or noindex per page, traffic overrides. Not started.
3. **New template** from the mockup behind a feature flag by tier; 5 sample pages, then tier A. Not started.

## Open issues

- Live theme = repo theme minus the unreleased 1.2 edits. Ship 1.2 (or drop it) via `scripts/deploy_theme.sh` so
  live and repo match; every live change is logged in `docs/LIVE_CHANGELOG.md`.

- **CollegeDB retired (2026-10-01):** deactivated after a full database backup. `[CollegeDB]` and `[CollegeDB_full]`
  now print nothing (child theme placeholders, kept as a safety net). The 31 /gpa-scale/ pages no longer contain the
  shortcode or its "admission chances" section (removed in the block conversion). The tables get rebuilt from Scorecard/CDS data after the
  admissions import, not from the old CollegeDB table. UniversityTemplate deactivated too. That closes CollegeDB's
  logged-out `cdb_change_url` rewrite and UniversityTemplate's `?update_universities` meta rewrite.
  Rollback: reactivate the plugins; the pre-change backup is `~/backups/gpacalculator-2026-10-01-pre-collegedb.sql.gz`
  on the server (copy in `~/gpacalculator-backups/` on Digant's Mac), never in the repo.
- /gpa-scale/4-0-gpa/: its Rank Math FAQ block renders nothing (the block has no saved question data), so the
  4.0 FAQ never shows and there is no FAQ schema. Re-save the FAQ in the editor or turn it into headings + paragraphs.
- Eight /gpa-scale/ pages (3.6, 2.7, 2.5, 2.2, 2.1, 1.7–1.5, 1.3) keep some pasted-in `<div>` wrappers or nested lists as
  Custom HTML blocks. On the ones whose FAQ sits inside those wrappers, the theme can't build FAQ schema.
  Unwrapping them into normal blocks would fix that.
- The 189 trashed `colleges` posts are not exported; empty the trash or restore deliberately.
- Country configs (`gpcm_international_profiles`) and any uploaded shared JS/CSS (`gpcm_shared_assets`)
  still live only in the site database.
- Two high school GPA calculators exist (`high-school-gpa-calculator`, `high-school-gpa-calc`): decide whether one retires.
- Reconcile the High School GPA v2.9 weighting rules with `course-catalog.js` before it moves onto the core.
- `engine_qa.py` needs PHP; run it in CI or a machine with PHP (the Mac used for the server pull has none).

## Changelog

### 2026-10-01: /gpa-scale/ FAQs rewritten and restyled, internal link dedupe (live)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-faq.sql.gz`), `scripts/wp/gpa_scale_pass6.php`: every page's Rank
  Math FAQ replaced with 6 questions from `content/gpa-scale-faqs.json` (`scripts/build_gpa_scale_faqs.py`: common query
  patterns per GPA, answers use the page's own figures, no stats and no links); internal links deduped to one per
  URL per page body (18 removed on 8 pages, `content/gpa-scale-link-changes.md`).
- Theme CSS: FAQ cards (blue left accent, "Q" marker), overriding the older static FAQ rules.
- Internal Link Juicer: every GPA page already has its keyword ("3.8 GPA" …) and the hub has "gpa scale".
  The free ILJ version doesn't account for manual links (its existing-link check is empty), but after the dedupe no
  page body links any URL twice. Rank Math's "Open external links in new window" adds target=_blank to the NAEP
  link site-wide (left as is, pending Digant).

### 2026-10-01: /gpa-scale/ quote, Rank Math FAQ, template, hub links (live)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-pass5.sql.gz`), `scripts/wp/gpa_scale_pass5.php` on all 31 pages:
  quick facts wrapped in a quote block (as Digant did on 3.6) followed by a lead-in sentence before the scale table;
  at most one external link per page (the NAEP source, below the first H2); each FAQ section converted to a Rank
  Math FAQ block (152 questions); template set to `page-templates/template-content.php` ("GPA – Content Page") on
  the 30 pages that had the unregistered `gpa-content-page` value.
- Theme: `gpa_heading_faq_schema()` skips pages with a Rank Math FAQ block, so each page outputs exactly one FAQPage.
- Hub /gpa-scale/: the Grade points values in its scale table link to the 4.0 … 1.0 pages (11 links,
  `scripts/wp/gpa_scale_hub_links.php`).

### 2026-10-01: /gpa-scale/ one-structure rewrite (live, 30 pages)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-rewrite.sql.gz`), the drafts in `content/gpa-scale-rewrite/`
  were saved (revisions kept; each page saved only if unchanged since the drafts were built). Structure: intro, quick facts,
  scale table, "Is a X GPA good?", "What a X GPA means for college", "How to raise a X GPA" (classes-needed table +
  Raise GPA calculator), FAQ. 4.0 left as is. Wrong statements in the 2.x / 1.x year notes replaced; 2.2 FAQ grade and
  1.1 cut-off FAQ answer fixed. All 31 pages checked live; FAQPage schema on 30.

### 2026-10-01: /gpa-scale/ content fixes, prev/next links, Updated date (live)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-content.sql.gz`), `scripts/build_content_pass.py`:
  64 sentences that pointed at the removed college search rewritten to link the GPA calculator (homepage) or the Raise
  GPA calculator (/how-to-raise-gpa/); the first "national average 3.0" mention cites the NAEP High School Transcript
  Study (3.11 for the class of 2019); weighted-GPA note after the table on 3.5-3.9 and 4.0 (links the Weighted GPA
  calculator); 4.0's empty Rank Math FAQ block turned into headings + paragraphs; 3.9 got its FAQ H2; pasted `<div>`
  FAQs and nested lists unwrapped into normal blocks (no Custom HTML blocks left). FAQPage schema now on 30 of 31 pages.
- Theme: "Updated <date>" under the hero intro and prev / next / "All GPA scale pages" links at the end of every
  /gpa-scale/<x-x>-gpa/ page (`gpa_scale_updated_date()`, `gpa_scale_page_nav()`).

### 2026-10-01: /gpa-scale/ Rank Math titles and descriptions (live)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-meta.sql.gz`), the 31 pages got the titles and descriptions
  in `content/gpa-scale-meta.md` (focus keywords unchanged). Every page's `<title>` and meta description checked live.

### 2026-10-01: /gpa-scale/ chart images replaced (live)
- The 31 featured-image charts were redrawn (`content/gpa-scale-images/`, footer "GPAcalculator.net") and written over the
  originals with the same names and pixel sizes; WordPress sizes, the legacy 960x700 / 400x300 crops and every
  `.png.webp` sibling were rebuilt (512 files). Originals: `~/backups/gpa-charts-orig-2026-10-01.tar.gz` (server + Mac).
- New alt text per image; Rank Math social image set to the same attachment (Twitter uses the Facebook image).
- Cloudflare caches `/wp-content/uploads/` for a year: purge it after any image swap.

### 2026-10-01: /gpa-scale/ scale table and heading cleanup (live)
- After a DB backup (`~/backups/gpacalculator-2026-10-01-pre-table.sql.gz`), each page's chart image in the content
  became a core Table block (class `gpa-scale-table`): the hub page's standard scale plus the page's own GPA row.
- Theme: `gpa_scale_table_mark_rows()` highlights the page's row (from the slug) and tags grade bands; table CSS
  and `--gpa-band-*` tokens. Applied live on top of the live theme files (not the unreleased 1.2 CSS).
- Inline bold / colour / `<mark>` removed from all H2–H4 headings on the 31 pages (108 headings), so the theme's
  heading style applies. Revisions kept for every page.
- The old charts are still each page's featured image; `scripts/render_gpa_scale_images.py` draws replacements
  (same file names and pixel sizes), pending approval.

### 2026-10-01: /gpa-scale/ pages converted to blocks, with intros
- After a fresh DB backup (`~/backups/gpacalculator-2026-10-01-pre-blocks.sql.gz`), `scripts/build_gpa_scale_convert.py`
  converted the 30 Classic pages to paragraph/heading/list blocks (Custom HTML for pasted wrappers and the 3.8 caption).
  Each page only saved after its new blocks rendered the same as the old content minus the removed section.
- Each page opens with an intro paragraph (`content/gpa-scale-intros.md`), which the content-page hero shows under the H1.
- The "admission chances" section and `[CollegeDB]` are removed from all 31 pages (4.0 was already blocks).
- Body fixes to match the site scale: 3.8 caption 90-92% / A-, 3.5 89–90% / B+/A-, 1.8 72% / C-.
- `wp_update_post()` from WP-CLI rejected the `gpa-content-page` template *after* writing the content, so it skipped
  revisions. Two revisions (old, new) were then added per page, so each page can be restored from the editor.
  The script now registers that template first.
- The theme's hide-section filter is removed (repo and live); the `[CollegeDB]` placeholder stays.
- Side effect: the theme's heading-based FAQ schema now finds the answers (they're real `<p>` blocks now), so most
  pages gained FAQPage schema. Checked: all 31 pages + homepage + `[gpcm_calculator]` + /admissions/ page (200, intro in
  the hero, no leftover section).

### 2026-10-01: hide the closing "admission chances" section while [CollegeDB] is empty
- Child theme `the_content` filter (priority 9), active only while `[CollegeDB]` is the empty placeholder. On the
  31 /gpa-scale/ pages it drops the headings directly above the shortcode ("Your Admission Chances With a X GPA",
  "List of Colleges accepting…", "Colleges likely to accept…") and the lead-in promising the admissions calculator,
  including the copy on 2.4 and 1.9 that sits above the FAQ. Post content is untouched; the text returns
  once the new database tool takes over `[CollegeDB]`.
- Applied live (previous files: `~/backups/functions-2026-10-01-pre-listheading.php`, `…-pre-fullsection.php`),
  caches purged; all 31 /gpa-scale/ pages, the homepage, two `[gpcm_calculator]` pages and an /admissions/ page checked.

### 2026-10-01: CollegeDB and University Template retired on the live site
- Database backed up first (20 MB gzip, outside the web root and copied off the server); live `functions.php` saved alongside.
- Live child theme `functions.php` got the `[CollegeDB]` placeholder block (linted; live = import + that block only).
- Deactivated `CollegeDB.disabled` and `UniversityTemplate`; deleted the empty `wp-content/plugins/CollegeDB/` folder.
  `formidable-pro-6.35.zip` was already gone from the theme. Breeze + Varnish purged; Cloudflare bypasses HTML, no purge needed.
- Checked after the change: all 31 `/gpa-scale/` pages return 200 with no `[CollegeDB` or `{{entry`, and all 88
  `[gpcm_calculator]` pages return 200 with `data-gpcm-profile-id`.

### 2026-10-01: theme 1.2 adds CollegeDB placeholders
- `[CollegeDB]` / `[CollegeDB_full]` print nothing when the CollegeDB plugin is off (same snippet applied to the live theme).

### 2026-10-01: server pull (read-only over SSH)
- Compared the live Grade + GPA plugin, Calculators plugin and child theme with the repo: no drift since the import;
  every difference is the repo's own unreleased 0.6.0 / 1.2 work.
- Added `legacy/UniversityTemplate/` and `legacy/CollegeDB.disabled/` (as live, no zips) and locked
  `[CollegeDB]` / `[CollegeDB_full]`; the guard reports CollegeDB must stay active until they are ported.
- Exported the 3,586 published `/admissions/` colleges with their 36 custom fields to `data/colleges/`, and the
  legacy `gpa_college_db` table to `data/college-db/`. `scripts/export_colleges.php` + `split_colleges.py` refresh them.

### 2026-10-01: live database cleanup (done on the site, not in code)
- `gpcm_university_profiles` set to autoload = no, so the 420 KB of profile rules no longer load on every request.
  Grade + GPA still reads it with `get_option()` when a `[gpcm_calculator]` renders.
- Deleted leftover options from removed plugins: Digg Digg, Thesis, Jetpack, Autoptimize.

### 2026-10-01: university profiles in the repo
- The 86 database profiles are exported into `data/gpcm-university-profiles/` (`scripts/split_profiles.py`
  refreshes them). The test runs each through the plugin's own validator and shortcode.

### 2026-10-01: university profiles locked
- Recorded the 87 live `[gpcm_calculator]` profile ids; `gpcm_profiles_test.php` runs Grade + GPA's own
  shortcode with the engine loaded and checks every id renders and the engine never takes the tag.

### 2026-10-01: Calculators shortcodes locked
- Locked the 12 shortcodes from the Calculators plugin's Export Settings (20 locked in total).
  `check_shortcodes.py` and `legacy_calcs_test.php` check every exported shortcode has its plugin copy.

### 2026-10-01: merge (plugin 0.6.0, theme 1.2, core 1.3.0)
- Imported the live theme, Grade + GPA (gpacalculator-manager) and Calculators (calcs-plugin) as-is.
- Grade + GPA loads the engine. `legacy-calcs-plugin.php` serves every Calculators-plugin shortcode
  from its saved settings with identical markup and handles, so Calculators can be deactivated with no page edits.
- All 11 theme calculators copied into `assets/calc-assets/`; served from there once the engine owns the tag.
- Tokens: dropped the separate `brand-tokens.css`; calculator tokens now live in `gpa-design-tokens.css`
  (`--gpa-calc-*`), and core maps site neutrals and the font from the existing `--gpa-*` tokens.
- Packaging builds full plugin and theme zips. QA adds `calculators_qa.py` and `legacy_calcs_test.php`
  (runs the real Calculators plugin side by side with the engine).

### 2026-10-01: core v1.2.0
- Calculator engine (`includes/`): manifest, registry with types, asset loader, shortcodes that take
  over old plugin tags once those plugins are deactivated; Plugins-screen coverage notice.
- Core mounts into shortcode output (`mountsFor`, `readAtts`; CSS scoped to `:is(#root, .gpacalc-mount)`).

### 2026-10-01: core v1.1.0
- Core and starter moved into the plugin; theme keeps design and tokens only. Plugin asset loader.

### 2026-10-01: core v1.0.0
- Repo set up with `plugin/` and `child-theme/`; shared core, starter template, Playwright harness,
  shortcode guard, import and packaging scripts, CI workflow.
