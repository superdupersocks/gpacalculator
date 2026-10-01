# Grade + GPA (WordPress plugin 0.5.1)

This replaces the existing `gpacalculator-manager` plugin in-place. It preserves the existing university calculator system and adds a separate country-grade conversion system.

## WordPress sidebar

- **Grade + GPA**
  - **University**
  - **Country**

The two systems have separate admin screens, shortcodes, profiles/options, and frontend engines.

## University calculators

Existing shortcode remains unchanged:

```text
[gpcm_calculator id="stanford"]
```

Existing university profiles, legacy calculators, uploaded assets, and options are preserved.

## Country converters

Country page:

```text
[country_grade id="germany"]
```

Universal published-country picker:

```text
[country_grade]
```

Optional server-rendered article scale from the same imported country JSON:

```text
[country_grade_scale id="germany" system="system-id"]
```

Country JSON is managed under **Grade + GPA → Country**. Importing the same `countrySlug` replaces that country's configuration without changing the shared engine.

Allowed lifecycle statuses are `draft`, `testing`, `published`, and `review_required`. Public visitors can use only valid `published` country configurations; the JSON status controls whether a country is publicly available.

## Shared international engine

The bundled engine is the frozen vanilla JS/CSS build from the approved Bolt template:

- `assets/international-grade-converter.js`
- `assets/international-grade-converter.css`
- engine version `1.1.0`

Country academic rules are not hard-coded into the engine. They come from imported JSON.

## Staging install

1. Back up the current site/plugin.
2. In WordPress staging, go to **Plugins → Add New → Upload Plugin**.
3. Upload this ZIP.
4. WordPress should identify the existing `gpacalculator-manager` plugin and offer **Replace current**.
5. Replace it; do not activate a second copy.
6. Confirm existing university calculator pages still render using `[gpcm_calculator ...]`.
7. Open **Grade + GPA → Country**.
8. Import a verified Pass 4 country JSON (Germany should be first).
9. Put `[country_grade id="germany"]` on a staging page and run calculator QA.
10. Publish by importing the verified JSON with status `published`.

Do not import the Bolt demo country JSON files as production academic data. Production country configs should come from the verified country Pass 4 workflow.
## Calculator engine (0.6.0)

Grade + GPA also hosts every calculator that used to load from the theme or the Calculators
plugin (`includes/`, loaded from the main file through `includes/bootstrap.php`):

- It reads the Calculators plugin's saved shortcode list and answers each shortcode with the same
  markup and script/style handles, loading the file from `assets/calc-assets/` when it is there.
- While the Calculators plugin is active it keeps its shortcodes and nothing changes. The Plugins
  screen shows when it is fully covered; deactivate it then. Reactivating it is the rollback.
- New calculators are entries in `includes/calculators.php`.

See PROJECT.md in the repo for the architecture and rollout.
