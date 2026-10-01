# gpacalculator.net

Feature-rich, easy-to-use calculators for students, delivered through WordPress
(GeneratePress child theme + the gpacalculator-manager plugin) with plain JS and CSS.

## Architecture

**The plugin owns calculators; the theme owns site design and brand tokens.**
Calculators read the theme's brand tokens with built-in fallbacks, so they still look right
if the theme's token file isn't loaded, and a brand change in the theme restyles all of them.

### Repo layout

```
child-theme/generatepress-child/       the live child theme (imported from the site)
  brand-tokens.css                     site-wide --gpa-* tokens (brand, neutrals, Inter, radius, shadow)
  inc/brand-tokens.php                 enqueues them as 'gpa-brand-tokens' (required from functions.php)
  calc-assets/                         LEGACY calculator location; files stay as fallbacks until moved
plugin/gpacalculator-manager/          the live plugin (imported from the site)
  includes/calculator-assets.php       serves calculators from the plugin under the theme's handles
  assets/calc-assets/                  every calculator's JS + CSS, same filenames as before
    core/                              shared core, owned by this repo
      calc-core.css                    layout + components under #root .calc; reads --gpa-* with fallbacks
      calc-core.js                     ES module: parsing, grade scale, storage, share, GA4, layout
      course-catalog.js                course levels, weighting bonuses, course list
    _starter/                          layout template for new calculators (not shipped)
tests/                                 QA (python3 tests/run_all.py)
scripts/import_live.py                 pulls uploaded live theme/plugin zips into the repo
scripts/package.sh                     builds installable zips into dist/
shortcodes.lock                        every shortcode the site registers; CI fails if one disappears
```

### How a calculator loads on a page

1. The page (URL unchanged) outputs `<div id="root">`, via its template or shortcode.
2. The theme still enqueues the calculator's handle as it does today. The plugin's loader
   (`includes/calculator-assets.php`, `wp_enqueue_scripts` priority 999) finds that handle and
   repoints its `src` at `plugins/gpacalculator-manager/assets/calc-assets/<same file>`.
   Deps, footer placement, localized data and inline scripts stay on the handle. If the plugin
   copy doesn't exist yet, nothing changes and the theme file keeps loading. A calculator can
   also be loaded purely by the plugin (by shortcode or page ID) once the theme enqueue is removed.
3. Scripts print as `type="module"` (the loader's own filter, idempotent with the theme's).
   The calculator imports the core with a relative path: `import { ... } from './core/calc-core.js';`
4. The calculator CSS starts with `@import url('core/calc-core.css');` (Inter included). The core
   maps each theme token to a local one with a fallback, e.g.
   `--calc-brand-1: var(--gpa-brand-1, #7c3aed);`, and components only use the local names.
   When the theme registers `gpa-brand-tokens`, calculator styles depend on it so it prints first.
5. `mountLayout()` builds the shell once and adds `calc-mounted` to `#root` (drops the theme's
   720px min-height). The calculator then only updates output nodes while the user types.

### Moving a calculator from the theme to the plugin

Done one calculator at a time, so every page keeps working throughout:

1. Copy its JS/CSS from the theme's `calc-assets/` to the plugin's `assets/calc-assets/` (same names).
2. Add it to `calculators()` in `includes/calculator-assets.php` with the theme's handles
   (and its shortcodes/page IDs).
3. Point its CSS at theme tokens with fallbacks; run its QA suite through `qa_lib.live_route`.
4. Ship the plugin, purge Cloudflare, check the live page.
5. In a later release: remove the theme's enqueue and its `calc-assets/` copy.

No page URL or shortcode changes at any step. Only the asset file URL moves from the theme
folder to the plugin folder, and the theme copy stays reachable until step 5.

### Shared core API (calc-core.js)

| Area | Exports |
| --- | --- |
| DOM | `h`, `setText` |
| Parsing | `parseScore` (84, 84%, 42/50, "42 out of 50", B+), `parseNumber`, `round`, `fmtPct`, `fmtNum` |
| Grades | `STANDARD_SCALE`, `PLAIN_SCALE`, `gradeFor`, `gpaForLetter`, `bandOf`, `nextGrade` |
| Save | `createStore(key)`: debounced draft autosave flushed on pagehide, named saves, seen flag; safe when storage is blocked |
| Share | `encodeState`/`decodeState` (UTF-8 base64url), `readHash`, `shareUrl`, `clearHash`, `copyText`, `toCSV`, `downloadCSV` |
| Analytics | `createTracker(prefix)`: GA4 `prefix_event`, once per page view unless repeatable |
| Layout | `mountLayout`, `createResultHero` (count-up score, ring, F to A scale), `createLivePill`, `createMenu`, `createToast`, `enterToNext`, `wireSavesAndShare` |

Course catalog: `LEVELS` (Regular 0, Honors +0.5, AP/IB/Dual Enrollment +1.0),
`COURSES` (core subjects with Honors variants, 40 AP and 18 IB courses), `guessLevel`,
`searchCourses`, `weightedPoints` (no bonus on an F by default; bonuses overridable),
`courseDatalist`.

### Tokens

Theme (`brand-tokens.css`, on `:root`): `--gpa-brand-1/2`, `--gpa-tint`, `--gpa-focus`,
`--gpa-ink`, `--gpa-text`, `--gpa-muted`, `--gpa-faint`, `--gpa-line`, `--gpa-line-strong`,
`--gpa-surface`, `--gpa-surface-2`, `--gpa-font`, `--gpa-radius-card`, `--gpa-radius`,
`--gpa-radius-pill`, `--gpa-shadow`, `--gpa-shadow-pop`. Never rename one; add new ones.

Calculator-only (in `calc-core.css`): letter grade colors, control sizes, motion.

### House rules

- Plain JS, no frameworks, nothing on `window`. ES modules.
- Calculator files live in the plugin. The theme gets no calculator code.
- CSS scoped under `#root .calc` plus a unique prefix per calculator (`.gcx`, `.hsg`, ...).
  Brand values only through `--gpa-*` tokens with fallbacks.
- 800px max width, Inter, no hero inside the calculator, no outer bottom margin.
- Results are live; storage behind try/catch with a unique key per calculator.
- Every calculator ships a Playwright math suite in `tests/<name>_qa.py` with expected
  values computed in Python. 100% passing before delivery.
- Never remove or rename a shortcode or page URL. `shortcodes.lock` + `tests/check_shortcodes.py`
  enforce shortcodes.

## Workflow

1. **Import live files** (first time, and whenever the site was edited outside the repo):
   `python3 scripts/import_live.py generatepress-child.zip gpacalculator-manager.zip`
   On first import, functions.php gets `require_once get_stylesheet_directory() . '/inc/brand-tokens.php';`
   and the main plugin file gets `require_once __DIR__ . '/includes/calculator-assets.php';`.
2. **QA**: `npm install && pip install -r tests/requirements.txt && python3 tests/run_all.py`
   (CI runs the same on every PR): shortcode guard, core suite, PHP loader tests.
3. **Package**: `bash scripts/package.sh` builds `dist/`. Full theme and plugin zips are only
   built from imported live source and only when no locked shortcode is missing.
4. **Install**: plugin/theme zip via WordPress upload ("Replace current with uploaded"), or
   upload the additive zips' files over FTP/file manager. Then purge the Cloudflare cache.

## Calculator status

| Calculator | Live version | In repo | Served from | Uses core | QA suite |
| --- | --- | --- | --- | --- | --- |
| High School GPA | v2.9 | Waiting on live files | Theme (to move) | Not yet | To port |
| Grade Calculator (reference build) | v3 | Waiting on live files | Theme (to move) | Not yet | To port (12 cases + validation) |
| Starter template | n/a (not shipped) | Yes | Plugin | Yes | `core_qa.py`, 115 checks |

Other calculators on the site get added here when the live theme is imported.

## Open items

- Import the live child theme and plugin (the build environment can't reach gpacalculator.net,
  and PHP isn't served over HTTP). Then fill `shortcodes.lock`, add the two `require_once`
  lines, move each calculator into the plugin, port the existing QA suite, and build the full zips.
- Reconcile the live theme's existing tokens file with `brand-tokens.css` (same values, `--gpa-*` names).
- Reconcile the High School GPA v2.9 weighting rules with `course-catalog.js` before it moves
  onto the core, so live results don't change.

## Changelog

### 2026-10-01: core v1.1.0
- Calculators moved to the plugin: core and starter now live in
  `plugin/gpacalculator-manager/assets/calc-assets/`.
- Theme keeps site design and brand tokens only: `brand-tokens.css` (`--gpa-*`) plus
  `inc/brand-tokens.php`. Core reads every brand token with a built-in fallback.
- Plugin asset loader (`includes/calculator-assets.php`): serves calculators under the theme's
  existing handles, falls back to the theme file when not moved, loads by shortcode/page ID,
  prints module tags. PHP tests: 16/16.
- QA: token tests (with theme tokens, without them, brand override): core suite 115/115.
- Packaging: additive plugin-core and theme-tokens zips; full zips warn until the
  `require_once` lines are in.

### 2026-10-01: core v1.0.0
- Repo set up with `plugin/` and `child-theme/`.
- Shared core: tokens, layout template, save/share, GA4 tracker, course catalog.
- Starter calculator template wired to the core.
- Playwright QA harness (local server, local Inter, live-page routing, page-error capture)
  and core suite: 110/110 passing.
- Shortcode guard, import script, packaging script, CI workflow.
