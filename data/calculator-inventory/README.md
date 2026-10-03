# Calculator page inventory

Read-only export of every published page/post on gpacalculator.net that contains a calculator,
taken 2026-10-03 (UTC). Nothing on the server or in WordPress was changed.

`pages.csv` has one row per page: `post_id, url, title, post_type, build, shortcode_or_form,
formula_fields, script_summary, last_modified`.

## Counts

153 calculator pages, all `post_type = page` (no posts, reusable blocks or Templatera items carry one).
No `colleges` posts were scanned.

| build | pages | what it is |
|---|---|---|
| shortcode | 104 | 88 `[gpcm_calculator id="…"]` university pages, 7 `[gpa-calculator]`, 1 each of `college-gpa-calculator`, `high-school-gpa-calc`, `raise-gpa-calculator`, `grade-calculator`, `final-grade-calculator`, `semester-grade-calculator`, `weighted-grade-calculator`, `sgpa-to-cgpa-calculator`, and Germany (`country_grade` + `country_grade_scale`) |
| formidable | 38 | one `[formidable id=…]` per page, 38 different forms |
| broken-shortcode | 6 | the calculator shortcode isn't registered by anything, so the live page shows the raw `[tag]` text and no calculator |
| inline-script | 4 | Custom HTML block with its own `<script>` |
| bundle-other | 1 | iframe to an external site |
| mixed | 0 | |

Non-shortcode pages (formidable + broken + inline + iframe): 49.

Shortcodes in the coordinator's list that no published page uses: `high-school-gpa-calculator`,
`middle-school-gpa-calculator`, `semester-gpa-calculator`, `gpa-scale`, `gpa_conversion`.
(`/semester-gpa-calculator/` and `/middle-school-gpa-calculator/` exist, but as a Formidable form and an inline script.)
Formidable is only used through the `[formidable]` shortcode; there are no Formidable blocks.

## Things worth knowing

- **6 pages show raw shortcode text on the live site** (checked with curl, no inputs on the page):
  `/ez-grader/` `[ez_grader]`, `/time-management-calculator/` `[wg-time-calc]`,
  `/class-schedule-maker/` `[class_schedule_maker]`, `/course-repeat-gpa-calculator/`
  `[course_repeat_gpa_calculator]`, `/grading-roster/` `[grading_roster]`, `/test-page/`
  `[college_gpa_calculator]`. None of these tags is in the registered shortcode list, the repo or
  `shortcodes.lock`; whatever used to provide them is gone.
- **Formidable forms all compute on submit.** Every form has an `on_submit` action that shows the
  result in the success message, `no_save=1` and AJAX submit. 21 of the 38 have a repeater child form
  (course rows). Stored entries are tiny (form 72 GPA Predictor: 26; 69: 4; 68: 4; 70: 2; 33: 2; the rest 0).
- **Formula issues spotted while exporting** (not fixed, read-only):
  - form 83 Unweighted Grade Calculator: `Percentage = [454]+[455]+[459]+[456]+[458]/5`, so only the last
    score is divided by 5.
  - form 4 CGPA to Percentage: `[14]*9.5` (CBSE rule only).
  - form 68 GPA and Letter Grade Converter has no calculated field at all.
- **Weighted, target, medical school and pharmacy school GPA calculator pages** (36303, 26511, 36305,
  36311) are identical builds: plain `[gpa-calculator]` with no attributes, page template
  `gpa-calculator-tool`, no page-specific scripts or calculator meta (26511 also has an unused
  `_rawhtml_settings` meta). Live they load `child-theme/generatepress-child/calc-assets/gpa-calculator.js` + `.css`
  (theme copy, byte-identical to the repo). The bundle doesn't read the URL except for the
  "Calculated with gpacalculator.net/…" share line, so all four pages show the same calculator.
  The same shortcode/bundle also runs Home, Brown and NC State.
- `/cgpa-calculator/` (22939) uses the `gpa-calculator-tool` template but has no calculator
  in its content or on the live page. It's left out of the CSV.
- `/grade-conversion/` (27067) has an inline script, but it's a country search box, not a
  calculator. Left out of the CSV.
- `/grade-conversion/india/` embeds `https://international-grades-a1sh.bolt.host/` in an iframe.
- Inline-script calculators use inline styles and `onclick`/`onchange` handlers, 70–99 lines of JS each.

## How it was made

All reads went through wp-cli `eval-file -` (PHP sent over stdin, nothing written on the server),
from `~/Documents/Claude/gpacalculator`:

```bash
ssh -i ~/.ssh/gpacalculator_cloudways master_rfzfmbbwze@67.205.161.226 \
  'cd /home/836951.cloudwaysapps.com/xwnzegvpyy/public_html && wp eval-file -' < dump.php
```

1. `all.php`: every published `page`, `post`, `wp_block`, `templatera`: ID, type, title, slug,
   `post_modified`, `_wp_page_template`, `post_content`, permalink, as JSON.
2. `dump.php`: list of registered shortcodes (`$shortcode_tags`), to tell registered tags from dead ones.
3. `frm3.php`: for each form id used on a page, `gpa_frm_forms` options, its repeater child forms,
   `gpa_frm_fields` (input count, every non-empty `field_options['calc']`), form actions, entry count.
4. Locally: regex over `post_content` for calculator shortcodes, `[formidable id=…]`,
   `wp:formidable` blocks (none), `<script>` tags (5 found), `<iframe>`, form inputs and `on*=` handlers;
   then ran `curl` on the live pages for the unregistered shortcodes and the four GPA pages above to
   confirm what loads.

Also checked: postmeta with `<script` / `[formidable` / script-ish keys (only Rank Math, Genesis
script positions and stale WPBakery flags, nothing calculator-related).
