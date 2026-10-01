# gpacalculator-manager

The one plugin for every calculator on gpacalculator.net. Calculators from the theme, Calc Plugin
and Grades & GPA Plugin merge into it as calculator types, answering to their old shortcodes.

- `includes/` the engine: `bootstrap.php` (load this), `calculators.php` (manifest),
  `calculator-registry.php`, `calculator-assets.php`, `shortcodes.php`.
- `assets/calc-assets/` calculator JS/CSS (theme calculators keep their old filenames).
  - `core/` shared core (layout, save/share, GA4, course catalog). Reads the theme's brand tokens with fallbacks.
  - `_starter/` layout template for new calculators. Not shipped.

The rest is imported from the site with `scripts/import_live.py`. See PROJECT.md. Every
shortcode on the site is recorded in `shortcodes.lock` and must keep working.
