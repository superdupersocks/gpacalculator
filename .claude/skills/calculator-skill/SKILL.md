---
name: "calculator-skill"
description: "Use when building, rebuilding, restyling or repurposing any calculator for gpacalculator.net (GPA, grade, conversion, planner, university-specific): design tokens and colors, Lexend, 800px width, standard feature set, guided follow-up flow, save/return, share, sample, reset, code, QA and delivery standards."
---

# Building calculators for gpacalculator.net

Every calculator must look and behave like one product. Build on the shared calculator core in the merged calculator plugin (base: gpacalculator-manager). Each calculator supplies only its own fields, math, follow-up steps and copy. Shared look and behavior live in the core, never copied per calculator.

## Sources of truth (read before building)

1. **Design spec:** Claude Doc "gpacalculator.net — Design System & CSS Overhaul Spec (for Claude Code)" (https://claude.ai/code/artifact/1d396bba-2925-4cd3-95b4-7c5ce1bf894d). Sections "Color tokens", "Typography" and "Calculators". If this skill and the spec disagree on a visual value, the spec wins, except width (800px, below).
2. **Tokens file:** `generatepress-child/gpa-design-tokens.css`. The only place colors, fonts, sizes, radii and shadows are defined.
3. **Reference builds (plain JS):** `calc-assets/grade-calculator.js/.css` (Grade Calculator v3) and `calc-assets/high-school-gpa-calc.js` (High School GPA Calculator v3.2). Read both in full and reuse their structure.
4. **Legacy (don't copy):** `calc-assets/gpa-calculator.js` (homepage) and `college-gpa-calculator.js` are Bolt/React/Tailwind bundles (~230–290 KB, Inter, baked-in colors). They are being rebuilt on the core. Use them only as a feature inventory.

## How to work (Digant's standing instructions)

- Act as technical co-founder: propose the best features, research competitors and People Also Ask, and push back on ideas that aren't best practice. Explain why in one line.
- Think about the searcher's worry ("am I passing?", "is my GPA good?", "what do I need?"), answer it first, then predict the next calculation they'll want.
- Easy beats complete: a feature must never make the basic path harder.
- Check the live site and data before recommending anything.
- Accuracy is non-negotiable and never needs to be asked for: every calculator ships with a passing math QA suite.
- Keep replies to Digant short. He says "save this" / "ship it"; you run all Git steps.

## Look and feel

### Colors: tokens only, synced with the site theme

- Never write a hex value in calculator CSS or JS. Use `var(--gpa-*)` tokens only. Changing a palette token must re-color every calculator with the rest of the site.
- Palette: blue `--gpa-primary` (#2563EB) with indigo accent `--gpa-accent` (#4338CA), navy headings. Purple is retired everywhere.
- Calculator role tokens to use:

| Element | Token |
|---|---|
| Card background / border / shadow | `--gpa-calc-bg`, `--gpa-calc-border`, `--gpa-calc-shadow` |
| Input border / focus ring | `--gpa-calc-input-border`, `--gpa-calc-focus` |
| "Add course" button | `--gpa-calc-add-bg`, `--gpa-calc-add-border` (dashed), `--gpa-calc-add-text` |
| Result panel | `--gpa-calc-result-bg`, `--gpa-calc-result-line`, `--gpa-calc-result-label`, `--gpa-calc-result-value` |
| Primary CTA | `--gpa-calc-cta-bg` (brand gradient), white text |
| Planner box | `--gpa-tint-indigo` bg, `--gpa-calc-result-line` border |
| Secondary buttons | white, `--gpa-border`, `--gpa-text` |
| Status words only (grade range, standing, at risk) | `--gpa-success`, `--gpa-warning`, `--gpa-danger` |
| Grade bands (badges, ring, scale track) | `--gpa-band-a` … `--gpa-band-f` (+ `-bg`) |

- If you need a color that doesn't exist, add it to the palette and a role token in `gpa-design-tokens.css` first, then use the role. No one-off hex.
- Green is a status color only, never a theme color.
- Contrast ≥4.5:1 for all text (check muted grays on tinted panels).

### Type

- **Lexend only** (`font-family: var(--gpa-font)`), including big numbers. Never Inter. Fonts are loaded site-wide by `gpa_fonts()`; calculators must not load fonts themselves.
- UI text 15–16px, 400–600. Inputs 16px on mobile (stops iOS zoom).
- Result number 40px desktop / 36px mobile, 700, `--gpa-calc-result-value`. Result label 13px 600 `--gpa-calc-result-label`. Meta 14px `--gpa-gray-600`.
- Numbers right-aligned, `font-variant-numeric: tabular-nums`.

### Size and shape

- **Width: 800px max** (`max-width: 800px; margin: 0 auto`), exactly as wide as the white content column (800px).
- Card: radius 16px, 1px border, the only element on the page with a shadow.
- Inputs/selects: 44px tall, radius 8px, 2px focus ring. Buttons and tap targets ≥44px.
- Mobile (≤640px): rows stack (name full width, grade + credits side by side), 14px side margin on the card. On a 390×844 phone the first screen shows at least three course rows plus the result.
- Check that a long course name ("Introduction to Organic Chemistry") stays readable at 375px. Lexend is wide.

### Page fit

- No hero, H1 or intro inside the calculator; the page hero has them. The card overlaps the hero (~88px desktop, ~60px mobile); page CSS handles that, not the calculator.
- No white space around the card, no empty gutters inside rows.
- Don't reserve space for results; they appear when there's enough data. Override the theme's `#root { min-height }` for the page.
- Spacing below the calculator is the theme's job: no outer bottom margin, never ask for spacer blocks.
- Placeholders must not look like data: light "e.g. AP Biology" hints with realistic values.

## Standard feature set

Every calculator gets the **core** features. GPA calculators also get the **GPA** set. Pick extras per calculator from research and justify them.

**Core (all calculators)**
- Live result: updates as they type, never needs a Calculate button. Opens with a plain verdict ("You have a 3.42 — a solid B+ average").
- Sticky "live result" pill while the result is below the fold (zero layout height, above the ad footer, tap scrolls to the result).
- "Show how it's calculated": collapsible step-by-step math using their numbers.
- Try a sample: opt-in, never pre-filled.
- Start over (reset), save and return, share: see Engagement.
- Inline help: accepted formats, instant conversion feedback ("A- = 3.7"), plain-language errors, smart defaults.

**GPA calculators (homepage, college, high school, university-specific)**
- Course rows: optional name (with course suggestions where useful, e.g. the AP/IB list), grade (letter, or percent where the scale supports it), credits, plus course level (Regular / Honors / AP-IB) where weighting applies.
- Multiple semesters/years, each collapsible and renamable, with semester and cumulative GPA.
- Previous cumulative GPA + credits (optional) to combine with new courses.
- Grading scale selector (4.0, plus/minus, A+ = 4.33, school-specific where known). Show unweighted and weighted side by side when levels are used.
- Planner: target GPA → average needed in upcoming credits, plus "highest possible GPA" when the target is out of reach.
- What-if: change a grade or the upcoming average and see the effect live.
- Insight cards: "Is my GPA good?", biggest lever (which course moves the GPA most), next letter or band.

**Per calculator (current inventory to carry forward)**
- **Homepage:** general GPA with weighting, semesters, previous GPA, AP/IB course list, planner, sample, CSV, print. It should also route students to the right detailed calculator (high school vs college).
- **High school:** unweighted + weighted, course levels, semesters, previous GPA, planner, what-if, "Is my GPA good?", saves, copy link, copy summary, CSV, print/PDF, sample.
- **College:** semesters, cumulative, grading scale, planner (average needed, highest possible), named saves, CSV, print. **Missing today:** sample and share link; add both.
- **Grade calculator:** percent / points (45/50) / letter inputs, weights, "what do I need on the final?", pass verdict, what-if, saves, share, CSV.

## Engagement: follow-up flow and keeping users on the site

- **Step flow.** Step 1 "Your result" (inputs + live result) → Step 2 the most likely next calculation, shown as a step bar with checkmarks and one clear primary CTA under the result.
- **Pain point first.** If the user is at risk (failing, below 2.0, within 5% of a pass line or a target), the CTA changes to the rescue path ("What do I need to pass?", "How do I get back above 2.0?") and presets the target.
- **Follow-up map.** Every calculator defines its next steps in config. Minimum:

| Calculator | Step 2 (in-card) | "Keep going" links |
|---|---|---|
| Grade | What do I need on the final? | GPA calculator, semester grade |
| High school GPA | Target GPA planner | "Colleges where your GPA fits" (admissions), weighted vs unweighted, raise GPA |
| College GPA | Target GPA planner (grades needed next semester) | Raise GPA, cumulative, Latin honors / probation where relevant |
| Homepage GPA | Target GPA planner | High school or college calculator, GPA to percentage |
| Conversions | Convert back / to another scale | GPA calculator, country scale pages |

- **Result panel links.** One primary CTA + two text links in the in-text link style (e.g. "Which colleges fit a 3.63?" → /admissions/ with the GPA passed in the URL, "Convert to percentage"). Pass the user's numbers forward so the next calculator opens pre-filled.
- **Keep going.** 2–4 related calculator links below the result, chosen by the follow-up map, not generic.

## Save, return, share, sample, reset

- **Auto-save draft.** Save state on every change (debounced) and on `pagehide`/`visibilitychange`. Refreshing loses nothing.
- **Come back to it.** On return, restore the last draft automatically with a small banner: "Welcome back, we restored your last calculation · Start fresh". Never show the onboarding box to returning users; give them a small "Show an example" link instead.
- **Named saves.** "My saves" menu: save as (named), open, rename, delete, new. Opening a save while the draft has unsaved changes asks first. Sample data can't be saved.
- **Share.** Copy link (full state compressed in the URL hash), copy text summary ("Calculated with gpacalculator.net/…"), download CSV, print / save as PDF. Opening a shared link shows "Viewing a shared calculation · Save a copy" and never overwrites the visitor's own draft.
- **Example.** "Try a sample" loads realistic data with a banner and a Clear button; clearing returns the user's previous draft if one existed.
- **Reset.** "Start over" clears the current draft (not named saves), with an "Undo" toast for ~6 seconds.
- **Storage.** `localStorage` behind try/catch; key `gpac:<calculator-id>:v<schema>`; include a schema version and migrate old drafts (including from the legacy bundles' keys) rather than discarding them. If storage is unavailable, everything still works; only saving is off.

## Code standards (house rules)

- Plain JavaScript on the shared core. No React or Tailwind in new builds. Wrap everything in an IIFE; nothing leaks onto `window`. Old bundles leaked globals that an ad script overwrote, breaking typing site-wide.
- Keep existing shortcodes and the `#root` mount working so pages and old plugins can switch over without edits.
- Scope every CSS rule under `#root .<prefix>` with a unique prefix per calculator. No global resets. `!important` only to beat theme table/list/heading rules.
- Scripts are enqueued as `type="module"`; stay compatible.
- Build the DOM once and update output nodes only, so focus and caret are never lost while typing.
- GA4 (`gtag`, G-N5MNQX3DEZ): keep `calculator_used` and other existing event names working. Add prefixed events per calculator (`<prefix>_result`, `_sample`, `_step_2`, `_plan`, `_save`, `_restore`, `_share`, `_reset`), once per page view unless repeatable.
- Accessibility: label or aria-label on every input, `aria-live="polite"` on results, visible focus, Enter moves to the next row, Escape closes menus, full keyboard use.
- Size: shared core ≤25 KB gzipped (loaded once); each calculator ≤10 KB gzipped.
- Respect `prefers-reduced-motion` (count-up animation off).

## Verify before delivering

- **Math QA suite (mandatory).** `tests/<calculator>_math_qa.py` (Playwright) types into the real UI and checks every displayed number against values computed independently in Python, not by reusing the calculator's code. Cover every method, scale and input format, weighting, semesters + previous GPA, planner outputs (including impossible targets), and edge cases (blank/zero rows, 0 and max values, decimals, invalid input). Deliver only at 100% passing, and report the pass count.
- **Flow test.** sample → live result → step 2 → save → reload (draft restored) → open save → share link in a fresh browser → reset + undo. Zero console errors.
- **Visual test.** Screenshots at 390×844, 768×1024, 1366×768 and 1440×900, mid-typing (pill visible) and with the full result.
- **Re-color test.** Change `--gpa-blue-600` on a preview and confirm the calculator follows, then revert. `grep` the calculator files for hex values; there must be none.

## Delivery

- Work on a branch in the repo (superdupersocks/gpacalculator). Back up anything you replace on the server so it's revertible with one command.
- Send Digant a preview first: an interactive preview HTML plus the full CSS and JS files (whole files, not snippets), screenshots, and the QA pass count.
- On "ship it": merge, deploy, purge Cloudflare, re-run the flow test on the live page, and update PROJECT.md.
