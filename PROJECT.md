# gpacalculator.net

Feature-rich, easy-to-use calculators for students, delivered through WordPress
(GeneratePress child theme + one calculator plugin) with plain JS and CSS.

## Architecture

**One plugin owns every calculator; the theme owns site design and brand tokens.**

| Piece | Folder | Role |
| --- | --- | --- |
| **Grade + GPA** (`gpacalculator-manager`, v0.6.0) | `plugin/gpacalculator-manager/` | The one plugin. Its own calculators (`[gpcm_calculator]` university GPA, `[country_grade]` / `[country_grade_scale]` country conversion) plus the calculator engine, which serves every calculator that used to load from the theme or the Calculators plugin. |
| **Calculators** (`calcs-plugin`) | `legacy/calcs-plugin/` | Legacy. Its shortcode list (WP admin > Calculators, option `calcs_plugin_shortcodes`) is read by the engine, which answers every one of those shortcodes with identical markup and handles. Deactivate it after rollout; never shipped from this repo. |
| **GeneratePress Child** (v1.2) | `child-theme/generatepress-child/` | Site design, page templates, `gpa-design-tokens.css`. `calc-assets/` stays as a fallback copy until cleanup. |

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
legacy/calcs-plugin/                   Calculators plugin source, for reference + tests (not shipped)
tests/                                 QA (python3 tests/run_all.py)
scripts/import_live.py                 pulls uploaded live zips into the repo
scripts/package.sh                     builds installable zips into dist/
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

1. **Import live files** (whenever the site was edited outside the repo):
   `python3 scripts/import_live.py --theme <zip> --plugin <zip> --calcs-plugin <zip>`
2. **QA**: `npm install && pip install -r tests/requirements.txt && python3 tests/run_all.py`
   (shortcode guard, core suite, calculator smoke suite, PHP engine + legacy tests).
3. **Package**: `bash scripts/package.sh` builds full theme and plugin zips in `dist/`.
4. **Install**: WordPress upload ("Replace current with uploaded"), then purge Cloudflare.

## Calculator status

Calculators-plugin shortcodes are DB-defined, so the exact tag per calculator is confirmed from its
Export Settings JSON. All 11 below are in the plugin, byte-identical to live, and pass the mount smoke test.

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
| semester-grade-calculator | grade | Yes | No | To write |
| weighted-grade-calculator | grade | Yes | No | To write |
| `[gpa-scale]`, `[gpa_conversion]` (external JS) | grade-conversion | Yes, original URLs | No | To write |
| `[gpcm_calculator]`, `[country_grade]`, `[country_grade_scale]` | university-gpa / grade-conversion | Native Grade + GPA | No | To write |
| Starter template | — | Yes | Yes | `core_qa.py` |

## Open items

- Calculators plugin Export Settings JSON, to lock its exact shortcode list in `shortcodes.lock`.
- Two high school GPA calculators exist (`high-school-gpa-calculator`, `high-school-gpa-calc`): decide whether one retires.
- `calc-assets/formidable-pro-6.35.zip` sits in the public theme folder on the live server (licensed plugin); delete it there. It is excluded from the repo.
- Reconcile the High School GPA v2.9 weighting rules with `course-catalog.js` before it moves onto the core.

## Changelog

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
