# generatepress-child

The live GeneratePress child theme for gpacalculator.net. Calculators live in `calc-assets/`.

- `calc-assets/core/` shared core (tokens, layout, save/share, course catalog). Owned by this repo.
- `calc-assets/_starter/` layout template for new calculators. Not deployed.

The rest of the theme (functions.php, templates, calculator files) is imported from the live
site with `scripts/import_live.py`. See PROJECT.md.
