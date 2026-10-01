# gpacalculator.net

Feature-rich, easy-to-use calculators for students, delivered through WordPress
(GeneratePress child theme + the gpacalculator-manager plugin) with plain JS and CSS.

## Architecture

### Repo layout

```
child-theme/generatepress-child/   the live child theme (imported from the site)
  calc-assets/                     one JS + CSS pair per calculator, mounted into #root
    core/                          shared core, owned by this repo
      tokens.css                   design tokens (brand, grade colors, radius, sizes, Inter)
      calc-core.css                layout + components, all under #root .calc
      calc-core.js                 ES module: parsing, grade scale, storage, share, GA4, layout
      course-catalog.js            course levels, weighting bonuses, course list
    _starter/                      layout template for new calculators (not deployed)
plugin/gpacalculator-manager/      the live plugin (imported from the site)
tests/                             Playwright QA (python3 tests/run_all.py)
scripts/import_live.py             pulls uploaded live theme/plugin zips into the repo
scripts/package.sh                 builds installable zips into dist/
shortcodes.lock                    every shortcode the site registers; CI fails if one disappears
```

### How a calculator loads on a page

1. The page template outputs `<div id="root">`. The theme enqueues the page's calculator
   JS and CSS from `calc-assets/` and tags scripts `type="module"` (a `script_loader_tag`
   filter in functions.php).
2. The calculator CSS starts with `@import url('core/calc-core.css');`, which imports
   `tokens.css` and Inter. The calculator JS imports the core:
   `import { mountLayout, ... } from './core/calc-core.js';`
   Relative imports resolve against the calculator's own URL, so no PHP change is needed
   to adopt the core.
3. `mountLayout()` builds the shell once (toolbar, step bar, banner, inputs, result,
   live pill) and adds `calc-mounted` to `#root`, which drops the theme's 720px min-height.
   The calculator then only updates output nodes while the user types.

Later optimization, once functions.php is in the repo: enqueue `calc-core.css` directly
(saves one CSS @import round trip) and add `modulepreload` for `calc-core.js`.

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

### House rules

- Plain JS, no frameworks, nothing on `window`. ES modules (the theme loads `type="module"`).
- CSS scoped under `#root .calc` plus a unique prefix per calculator (`.gcx`, `.hsg`, ...).
- 800px max width, Inter, no hero inside the calculator, no outer bottom margin.
- Results are live; storage behind try/catch with a unique key per calculator.
- Every calculator ships a Playwright math suite in `tests/<name>_qa.py` with expected
  values computed in Python. 100% passing before delivery.
- Never remove or rename a shortcode. `shortcodes.lock` + `tests/check_shortcodes.py` enforce it.

## Workflow

1. **Import live files** (first time, and whenever the site was edited outside the repo):
   `python3 scripts/import_live.py generatepress-child.zip gpacalculator-manager.zip`
2. **QA**: `npm install && pip install -r tests/requirements.txt && python3 tests/run_all.py`
   (CI runs the same on every PR). Use `qa_lib.live_route` to test against the live page
   with repo assets swapped in.
3. **Package**: `bash scripts/package.sh` builds `dist/`. Full theme and plugin zips are only
   built from imported live source and only when no locked shortcode is missing.
4. **Install**: theme or plugin zip via WordPress upload ("Replace current with uploaded"),
   or upload single files over the same names in `calc-assets/`. Then purge the Cloudflare cache.

## Calculator status

| Calculator | Live version | In repo | Uses core | QA suite |
| --- | --- | --- | --- | --- |
| High School GPA | v2.9 | Waiting on live files | Not yet | To port |
| Grade Calculator (reference build) | v3 | Waiting on live files | Not yet | To port (12 cases + validation) |
| Starter template | n/a (not deployed) | Yes | Yes | `core_qa.py`, 110 checks |

Other calculators on the site get added here when the live theme is imported.

## Open items

- Import the live child theme and plugin (the build environment can't reach gpacalculator.net,
  and PHP isn't served over HTTP). Then fill `shortcodes.lock`, port the existing QA suite,
  and build the full theme/plugin zips.
- Reconcile the High School GPA v2.9 weighting rules with `course-catalog.js` before it moves
  onto the core, so live results don't change.

## Changelog

### 2026-10-01: core v1.0.0
- Repo set up with `plugin/` and `child-theme/`.
- Shared core: tokens, layout template, save/share, GA4 tracker, course catalog.
- Starter calculator template wired to the core.
- Playwright QA harness (local server, local Inter, live-page routing, page-error capture)
  and core suite: 110/110 passing.
- Shortcode guard, import script, packaging script, CI workflow.
