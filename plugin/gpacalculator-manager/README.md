# gpacalculator-manager

The live gpacalculator-manager plugin, and the home of every calculator.

- `assets/calc-assets/` calculator JS/CSS, same filenames as the theme's old `calc-assets/`.
  - `core/` shared core (layout, save/share, GA4, course catalog). Reads the theme's brand tokens with fallbacks.
  - `_starter/` layout template for new calculators. Not shipped.
- `includes/calculator-assets.php` serves calculators from the plugin under the theme's existing
  script/style handles, so pages and shortcodes don't change.

The rest is imported from the site with `scripts/import_live.py`; see PROJECT.md. Every
shortcode it registers is recorded in `shortcodes.lock` and must keep working.
