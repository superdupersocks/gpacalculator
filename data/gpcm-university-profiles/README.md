# Grade + GPA university profiles

The rules behind `[gpcm_calculator id="..."]`, one JSON per university, exported from the live
site's `gpcm_university_profiles` option on 2026-10-01 (86 profiles; Stanford ships with the plugin
in `plugin/gpacalculator-manager/profiles/stanford.json`).

The site reads these from its database, not from this folder. This is the reviewed copy: to change a
profile, edit it here, then upload it under **Grade + GPA > University** (same ID replaces it).
`tests/php/gpcm_profiles_test.php` checks every file passes the plugin's own validator and renders.

Refresh from the site: `wp option get gpcm_university_profiles --format=json > profiles.json`, then
`python3 scripts/split_profiles.py profiles.json`.
