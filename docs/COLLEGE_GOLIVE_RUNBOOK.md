# College calculator go-live (new core v2 calculator)

Digant's go: "go College live", unification thread, 2026-10-03 08:13. For the Mac session (SSH + WP-CLI).
Follow the live-site rules: repo first, DB backup (server ~/backups + Mac ~/gpacalculator-backups), never edit server
files by hand, log in docs/LIVE_CHANGELOG.md. No Cloudflare keys, no Cloudways backups or API keys.

0. **Wait for the Design thread's deploy.** components.css + calculator-page.css (design branch 69e4381, `--only`) and the
   page 22 subtitle "Semester and cumulative GPA on a 4.0 scale." must be live. Check: the live child-theme
   components.css has gpa-ex / rx-relcard rules that also match `#root` / `.gpacalc-mount` (e3b337a). Don't deploy
   that CSS from here.
1. `git fetch origin claude/calculator-unification-0oc2fc && git checkout 9da0e5b` (the College build Digant approved).
   Not the branch head: later commits add the "Add to home screen" hint, which ships only on its own go.
2. Backup: tar the live `wp-content/plugins/gpacalculator-manager` to `~/backups/gpacalculator-manager-pre-v2-<ts>.tar.gz`
   (copy it to the Mac) and take a DB backup.
3. Dry run: `rsync -nrci` repo `plugin/gpacalculator-manager/` against the live plugin dir (no `--delete`). Expected: the
   repo is ahead (main file 0.6.0, README, includes/*, assets/calc-assets/core|engines|gpa|profiles). **Stop** and tell
   Digant if a live file is newer than, or missing from, the repo.
4. Upload: rsync the repo plugin dir to live (no `--delete`, exclude `assets/calc-assets/_starter/`), `php -l` every PHP
   file. All switches are off, so no page changes yet. Check the homepage, /college-gpa-calculator/,
   /ucla-gpa-calculator/, /high-school-gpa-calculator/ and /final-grade-calculator/ load and work as before.
5. Switch on: `wp option update gpcm_calc_v2_on '["college"]' --format=json` (same as ticking College in
   Grade + GPA > New calculators). Purge Breeze. If the page still serves the old calculator, ask Digant to Purge
   Everything in Cloudflare.
6. Live checks on https://gpacalculator.net/college-gpa-calculator/ with a real Chrome user agent:
   - Phone 390×844 and desktop 1440×900: `.calc[data-profile=college]` renders; 2 courses (phone: grade and credit
     bottom sheets) give a result labelled "Semester GPA"; Add semester gives "Cumulative GPA"; "GPA credits" label;
     a P grade isn't counted; the planner button opens the planner; no console errors.
   - `?calc=old` shows the old calculator.
   - Ads: rails at 1440, phone sticky/in-content units fill (headless gets no Freestar fill; use a real UA or ask Digant).
   - GA4: `/g/collect` requests (or `window.dataLayer`) carry calculator_used, col_result, col_step_2, col_plan; no
     calc_error.
   - Old homepage saves untouched: on the homepage make a draft and a named save (`gpa_calc_draft_v1`,
     `gpa_calc_saved_v1`), open the College page, confirm both keys are byte-identical and the homepage still restores them.
   - If anything breaks: `wp option update gpcm_calc_v2_on '[]' --format=json`, purge, tell Digant.
7. Add a LIVE_CHANGELOG row (plugin upload + College switch; revert = untick, or restore the plugin tarball and
   untick), commit to this branch, pull, push.
8. Tell Digant the result per check in the thread.
