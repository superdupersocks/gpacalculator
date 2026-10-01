# generatepress-child

The live GeneratePress child theme for gpacalculator.net. The theme owns site design and the
brand tokens only; calculator JS/CSS lives in the gpacalculator-manager plugin.

- `brand-tokens.css` site-wide `--gpa-*` tokens (brand colors, neutrals, Inter, radius, shadow).
  Calculators read them with built-in fallbacks.
- `inc/brand-tokens.php` enqueues them as `gpa-brand-tokens`; functions.php requires it.
- `calc-assets/` (imported from the live site) is the legacy calculator location. Each file
  stays here as a fallback until its plugin copy is verified live, then it's removed.

The rest of the theme is imported from the live site with `scripts/import_live.py`. See PROJECT.md.
