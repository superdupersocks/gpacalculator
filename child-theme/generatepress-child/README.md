# generatepress-child

The live GeneratePress child theme for gpacalculator.net. The theme owns site design and the
design tokens only; calculator JS/CSS lives in the Grade + GPA plugin (gpacalculator-manager).

- `gpa-design-tokens.css` site tokens (`--gpa-*`) and calculator tokens (`--gpa-calc-*`).
  Calculators read them with built-in fallbacks.
- `calc-assets/` is the legacy calculator location. The plugin now carries identical copies and
  serves them once the Calculators plugin is deactivated; these copies are removed in cleanup.

Imported from the live site with `scripts/import_live.py`. See PROJECT.md.
