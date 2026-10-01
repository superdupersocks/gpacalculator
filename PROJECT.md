# gpacalculator.net

Feature-rich, easy-to-use calculators for students, delivered through WordPress
(GeneratePress child theme + the gpacalculator-manager plugin) with plain JS and CSS.

## Architecture

**One plugin owns every calculator; the theme owns site design and brand tokens.**
gpacalculator-manager is the base. Calculators from the theme, Calc Plugin (homepage, college
and high school GPA calculators) and Grades & GPA Plugin (country-level grade conversion,
university-level GPA calculators, etc.) become calculator types on its engine, answering to
their old shortcodes, so the old plugins can be deactivated with no page edits.
Calculators read the theme's brand tokens with built-in fallbacks, so they still look right
if the theme's token file isn't loaded, and a brand change in the theme restyles all of them.

### Repo layout

```
child-theme/generatepress-child/       the live child theme (imported from the site)
  brand-tokens.css                     site-wide --gpa-* tokens (brand, neutrals, Inter, radius, shadow)
  inc/brand-tokens.php                 enqueues them as 'gpa-brand-tokens' (required from functions.php)
  calc-assets/                         LEGACY calculator location; files stay as fallbacks until moved
plugin/gpacalculator-manager/          the one plugin (live plugin imported from the site + the engine)
  includes/bootstrap.php               loads the engine (one require_once in the main plugin file)
  includes/calculators.php             manifest: every calculator, its type, files, handles, shortcodes
  includes/calculator-registry.php     GPACalc_Registry: types, normalized entries, gpacalc_calculators filter
  includes/calculator-assets.php       serves calculator JS/CSS from the plugin (theme handles kept)
  includes/shortcodes.php              old + new shortcodes, mount markup, "safe to deactivate" notice
  assets/calc-assets/                  every calculator's JS + CSS, same filenames as before
    core/                              shared core, owned by this repo
      calc-core.css                    layout + components under #root .calc; reads --gpa-* with fallbacks
      calc-core.js                     ES module: parsing, grade scale, storage, share, GA4, layout
      course-catalog.js                course levels, weighting bonuses, course list
    _starter/                          layout template for new calculators (not shipped)
legacy/calc-plugin/                    Calc Plugin source, to port from (never shipped)
legacy/grades-gpa-plugin/              Grades & GPA Plugin source, to port from (never shipped)
tests/                                 QA (python3 tests/run_all.py)
scripts/import_live.py                 pulls uploaded live theme/plugin zips into the repo
scripts/package.sh                     builds installable zips into dist/
shortcodes.lock                        every shortcode the site uses, with its source plugin/theme
```

### Calculator engine

Every calculator is one entry in `includes/calculators.php`:

```php
'conversion' => array(
  'title'      => 'Grade Conversion',
  'type'       => 'grade-conversion',   // gpa | university-gpa | grade | grade-conversion | other
  'source'     => 'grades-gpa-plugin',  // theme | gpacalculator-manager | calc-plugin | grades-gpa-plugin | new
  'js'         => 'grade-conversion.js', 'css' => 'grade-conversion.css',   // in assets/calc-assets/
  'shortcodes' => array( 'old_tag', 'grade_conversion' ),                  // old names kept forever
  'atts'       => array( 'country' => 'us' ),                              // shortcode defaults -> JS
),
```

How it reaches a page:

1. **Shortcode calculators** (old plugin tags and new ones). On `init` (priority 20, after
   other plugins) the engine registers each tag *only if no other plugin has it*. While an old
   plugin is active, it keeps serving its own tags. After it's deactivated, the engine answers the
   same tags on the next page load. The output is
   `<div class="gpacalc-mount" data-calc="slug" data-atts="{...}">`, and the calculator JS mounts into
   it (`mountsFor(slug)`, `readAtts(el)`). An entry can set `render` for server-rendered HTML.
2. **Theme calculators** (`#root` page templates). The theme keeps enqueueing its handle. At
   `wp_enqueue_scripts` 999 the loader repoints that same handle's `src` at the plugin file.
   Deps, footer placement, localized data and inline scripts stay. Theme entries reuse the theme's
   handles; entries ported from old plugins use `gpacalc-<slug>`, so new JS never loads onto old
   plugin markup.
3. If a calculator's files aren't in the plugin yet, nothing changes and the old file keeps loading.
4. Assets go in `<head>` when the page content has a tag the engine owns, or the page is in
   `page_ids`. A shortcode in a widget or elsewhere enqueues itself when it renders.
5. Scripts print as `type="module"`. Calculator CSS starts with `@import url('core/calc-core.css');`,
   which is scoped to `:is(#root, .gpacalc-mount) .calc` and maps each theme token to a local one
   with a fallback (`--calc-brand-1: var(--gpa-brand-1, #7c3aed)`). `mountLayout()` builds the shell once.

**Safe to deactivate?** On the Plugins screen the engine shows, for each plugin that registers
shortcodes, how many of them it already covers (manifest entry + files present). A green notice
means that plugin can be deactivated with no page edits. `tests/check_shortcodes.py` reports the
same from the repo, and fails if a theme or gpacalculator-manager shortcode stops being served.

### Merge plan (after the live files are imported)

1. Import everything:
   `python3 scripts/import_live.py --theme ... --plugin ... --calc-plugin ... --grades-plugin ...`
   Every shortcode is locked with its source. Add `require_once __DIR__ . '/includes/bootstrap.php';`
   to the main plugin file and `require_once get_stylesheet_directory() . '/inc/brand-tokens.php';`
   to functions.php.
2. Per calculator, one at a time:
   a. Copy or port its JS/CSS into `assets/calc-assets/` (theme calculators keep their filenames).
      Old-plugin calculators get rewritten onto the core when they rely on PHP output, with every
      prefix moved to `gpacalc_`/`GPACalc_` so nothing collides while the old plugin is still active.
   b. Add the manifest entry with all of its old shortcodes and attributes.
   c. Switch colors and fonts to brand tokens with fallbacks; port or write its QA suite.
3. Ship the plugin, purge Cloudflare, check each page. Theme calculators switch to plugin files at once.
4. When the Plugins screen says an old plugin is fully covered, deactivate it, then re-check its pages.
5. Later cleanup: remove the theme's calculator enqueues and `calc-assets/` copies.

No page URL or shortcode changes at any step. Only asset file URLs move into the plugin
folder, and the old files stay reachable until step 5.

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
- Every calculator lives in gpacalculator-manager as a manifest entry. The theme gets no
  calculator code, and no new plugins.
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
   `python3 scripts/import_live.py --theme <zip> --plugin <zip> --calc-plugin <zip> --grades-plugin <zip>`
   (see Merge plan for the two `require_once` lines).
2. **QA**: `npm install && pip install -r tests/requirements.txt && python3 tests/run_all.py`
   (CI runs the same on every PR): shortcode guard, core suite, PHP engine tests.
3. **Package**: `bash scripts/package.sh` builds `dist/`. Full theme and plugin zips are only
   built from imported live source and only when no locked shortcode is missing.
4. **Install**: plugin/theme zip via WordPress upload ("Replace current with uploaded"), or
   upload the additive zips' files over FTP/file manager. Then purge the Cloudflare cache.

## Calculator status

| Calculator | Comes from | In repo | Served by engine | Uses core | QA suite |
| --- | --- | --- | --- | --- | --- |
| High School GPA v2.9 | Theme | Waiting on live files | Not yet | Not yet | To port |
| Grade Calculator v3 (reference build) | Theme | Waiting on live files | Not yet | Not yet | To port (12 cases + validation) |
| Homepage GPA calculator | Calc Plugin | Waiting on upload | Not yet | Not yet | To write |
| College GPA calculator | Calc Plugin | Waiting on upload | Not yet | Not yet | To write |
| High school GPA calculator | Calc Plugin | Waiting on upload | Not yet | Not yet | To write |
| Country grade conversion | Grades & GPA Plugin | Waiting on upload | Not yet | Not yet | To write |
| University GPA calculators | Grades & GPA Plugin | Waiting on upload | Not yet | Not yet | To write |
| Starter template | New (not shipped) | Yes | Yes | Yes | `core_qa.py`, 121 checks |

The full list (and each one's shortcodes) gets filled in from the imported source.
The theme and Calc Plugin both have a high school GPA calculator: decide whether they merge into
one calculator type with both shortcodes, once both sources are in.

## Open items

- Import the live child theme, gpacalculator-manager, Calc Plugin and Grades & GPA Plugin (the build
  environment can't reach gpacalculator.net, and PHP isn't served over HTTP). Then follow the merge plan.
- Reconcile the live theme's existing tokens file with `brand-tokens.css` (same values, `--gpa-*` names).
- Reconcile the High School GPA v2.9 weighting rules with `course-catalog.js` before it moves
  onto the core, so live results don't change.

## Changelog

### 2026-10-01: core v1.2.0
- One plugin: gpacalculator-manager gets a calculator engine (`includes/`): manifest, registry with
  calculator types, asset loader, and shortcodes that take over Calc Plugin / Grades & GPA Plugin
  tags once those plugins are deactivated. The Plugins screen shows which plugins are safe to deactivate.
- Core mounts into shortcode output too (`mountsFor`, `readAtts`; CSS scoped to `:is(#root, .gpacalc-mount)`).
- `shortcodes.lock` records each shortcode's source; the guard reports per-plugin port progress.
- `import_live.py` takes `--calc-plugin` / `--grades-plugin` into `legacy/`.
- QA: core 121/121 (shortcode mounts added), PHP engine 32/32.

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
