<!-- Repo copy of the Claude Doc "gpacalculator.net — Design System & CSS Overhaul Spec (for Claude Code)"
     https://claude.ai/code/artifact/1d396bba-2925-4cd3-95b4-7c5ce1bf894d
     Synced 2026-10-03 from doc revision 33. The doc is the source of truth: when it changes, re-export it
     (Claude Docs export, markdown) over this file and update the revision here. Don't edit this copy by hand.
     Check: everything below this comment is byte-identical to the export. -->
# gpacalculator.net — Design System & CSS Overhaul Spec (for Claude Code)

Oct 2, 2026 · @Digant

## Goal and ground rules

Rebuild the visual layer of gpacalculator.net so every color, font size and spacing value lives in one file, then apply the new design agreed in chat on Oct 2, 2026. Changing one token later must re-color the whole site, every template and every calculator.

Visual reference: the design canvas [College GPA Calculator page — redesign mockup](https://claude.ai/artifact/1uy7KqodSXbJTHH6xgYPXm) (desktop page, mobile page, color system, content components, logo/footer, link and breadcrumb boards). When this spec and the mockup disagree, this spec wins.

How to work:

- Back up first: copy every file you touch to a timestamped backup folder on the server, and export the database before any content edit. Every change must be revertible with one command.
- Work in the GeneratePress child theme only (`wp-content/themes/generatepress-child/`). Never edit the parent theme.
- Preview before live: take before/after screenshots (sizes and pages in Rollout) and send them for approval before pushing each phase live.
- After each live push, purge Cloudflare.
- No new hard-coded hex values anywhere. Every color is a `var(--gpa-*)` token.
- Don't change content, URLs, headings text, schema or ad slot IDs unless a section below says so.

## Current state: where styles live today

Styles are set in nine places, several of which override each other with `!important`. Findings below are from the live site on Oct 2, 2026.

| Source | Loads on | What it sets today | Problem |
| --- | --- | --- | --- |
| GeneratePress Customizer (inline `generate-style-inline-css`) | Every page | H1 38px/700, H2 28px/600, H3 22px/600, body line-height 1.7 | Mostly overridden by the child theme; dead weight that confuses edits |
| WP global styles (inline `global-styles-inline-css`) | Every page | Default WordPress palette and gradients | Unused; leave alone |
| `gpa-design-tokens.css` | Every page | Fonts, type scale (H1 48/36, H2 28/24, H3 20/18 at 600, H4 17 at 700, body 16px), colors (`--gpa-primary #2563EB`, `--gpa-purple #9333EA`), spacing, radii | Intended source of truth, but other files bypass it; H4 is bolder than H3; `.wp-block-rank-math-toc-block > h2` centered |
| `style.css` | Every page | Body text 20px/34px (18px/30px mobile) on calculator and content templates only; 800px text width; H2 margins 64/16; rail CSS (`.gpa-rail`, slot `min-height: 600px`); focus outline `#2563eb`; Kargo rule; `.content-formula`, `.content-result` | 20px override conflicts with tokens; duplicates rules in `content-styles.css`; Kargo is disabled at Freestar |
| `content-styles.css` | Calculator and content templates | Re-applies tokens with `!important`; hero band `::after` gradient `#7c3aed → #9333ea → #4338ca` with a wave SVG; `#2563EB` hard-coded 14 times; `.content-rel-icon-purple #7C3AED` | Hard-coded colors; second hero design |
| `gpa-homepage.css` | Homepage | Hero gradient `#2563eb → #9333ea → #1e40af`; `.gpa-bg-purple #9333EA`; `#2563EB` hard-coded 10 times | Hard-coded colors |
| `calculator-page.css` | Calculator template | `--rx-*` variables, section H2 (`.rx-h2`) at 800 weight, `#1E3A8A`, -0.02em; pill (`.rx-eyebrow`); `.rx-about h2` icon layout | Own variable set; hard-coded navy |
| `database-page.css` | College pages (`single-colleges`) | \~2,900 lines of component styles | Not yet audited for hex values; body stays 16px here |
| Simple CSS plugin (`uploads/so-css/so-css-generatepress.css`) | Every page | Section header spacing system (64px above, 8px pill gap, 12px H2 → subtitle, 32px header → content), mobile hero padding, `.university-gpa-intro` | Real layout rules living outside the theme and repo |
| `calc-assets/*.css` (Bolt/Tailwind builds, e.g. `gpa-calculator.css`, `college-gpa-calculator.css`, `raise-gpa-calculator.css`) | Each calculator | Tailwind utilities scoped to `:is(#root, .gpa-calc-portal)`; `#2563eb`, purple-50 planner box | Colors compiled into the bundles |

Also found:

- Fonts load via `gpa_fonts()` in `functions.php` (Lexend as a `<link>`, metric-matched "Lexend Fallback" face in tokens). Calculators use Inter.
- The side-rail script is printed inline in the page (marker `GPA_RAILS_V5`): `GAP = 20`, `EDGE = 8`, enabled at `min-width: 1024px`, and each rail is centered in the side gutter via `(c.left - 300) / 2`.
- Live hero today: "Updated for 2026" badge, 2-line title, wave bottom; the calculator already overlaps the hero.
- Body classes per template: `page-template-template-calculator` (+ `gpa-calc-tool`), `page-template-template-content` / `gpa-template-content` (+ `gpa-hero-band`), `single-post`, `single-colleges`, `home`.

## Target architecture: one central token file

`gpa-design-tokens.css` becomes the only place where colors, fonts, the type scale, spacing, radii and shadows are defined. Every other stylesheet, including the calculator layer, only references `var(--gpa-*)`.

Token layers, top to bottom:

1. **Brand palette:** raw hex values (`--gpa-blue-600` etc.). The only block anyone edits to re-color the site.
2. **Site roles:** what templates use (`--gpa-primary`, `--gpa-heading`, `--gpa-link`, `--gpa-page-bg`, `--gpa-hero-bg`).
3. **Calculator roles:** what calculators use (`--gpa-calc-border`, `--gpa-calc-result-bg`, `--gpa-calc-cta-bg`).
4. **Type, spacing, radius, shadow:** existing `--gpa-*` size tokens, updated to the values in this spec.

File plan (all in the child theme):

| File | Role after the change |
| --- | --- |
| `gpa-design-tokens.css` | All tokens + base element styles (body, h1–h4, links, focus ring). Loads first, on every page. |
| `layout.css` (new) | Page background, content column, hero, nav, footer, section header spacing (moved from Simple CSS), breadcrumb, TOC. Every page. |
| `components.css` (new) | Quote, table, callouts, formula, takeaways, lists, FAQ, related-tool cards, step cards. Every page that has `.entry-content`. |
| `content-styles.css`, `calculator-page.css`, `gpa-homepage.css`, `database-page.css` | Template-specific layout only; every color and size switched to tokens; duplicates removed. |
| `calc-theme.css` (new) | Maps the calculator bundles' Tailwind color utilities to calculator tokens (see Calculators). Loads after `calc-assets/*.css`. |
| `style.css` | Theme header + rails CSS + anything truly global that isn't a token; trimmed. |

Load order: tokens → layout → components → template file → calculator bundle → `calc-theme.css`. Enqueue with `filemtime()` versions as today. Aim to cut `!important` wherever the new order makes it unnecessary; keep it only where it must beat GeneratePress or block-library inline styles.

After this, the GeneratePress Customizer typography/colors and the Simple CSS box are empty, so nothing outside the child theme sets visual styles.

## Color tokens

The palette is blue (`#2563EB`) with an indigo accent (`#4338CA`), navy headings, and a cool-gray page. Purple (`#9333EA`, `#7C3AED`, `#6D28D9`) is retired everywhere. Paste this block at the top of `gpa-design-tokens.css`, replacing the existing color variables, and keep old variable names as aliases (e.g. `--gpa-purple: var(--gpa-accent)`) until every file is migrated.

```css
:root {
  /* 1. BRAND PALETTE — edit here to re-color everything */
  --gpa-blue-50:   #EFF6FF;
  --gpa-blue-100:  #DBEAFE;
  --gpa-blue-300:  #93C5FD;
  --gpa-blue-600:  #2563EB;
  --gpa-blue-700:  #1D4ED8;
  --gpa-blue-900:  #1E3A8A;
  --gpa-blue-950:  #172554;
  --gpa-indigo-50:  #EEF2FF;
  --gpa-indigo-200: #C7D2FE;
  --gpa-indigo-700: #4338CA;
  --gpa-gray-50:   #F7F7FB;
  --gpa-gray-100:  #F3F4F6;
  --gpa-gray-200:  #E5E7EB;
  --gpa-gray-500:  #6B7280;
  --gpa-gray-600:  #475569;
  --gpa-gray-700:  #374151;
  --gpa-gray-900:  #111827;
  --gpa-green-700: #15803D;
  --gpa-amber-50:  #FFFBEB;
  --gpa-amber-200: #FDE68A;
  --gpa-amber-700: #B45309;
  --gpa-red-700:   #B91C1C;

  /* 2. SITE ROLES */
  --gpa-primary:        var(--gpa-blue-600);
  --gpa-primary-hover:  var(--gpa-blue-700);
  --gpa-accent:         var(--gpa-indigo-700);
  --gpa-heading:        var(--gpa-blue-900);
  --gpa-text-strong:    var(--gpa-gray-900);
  --gpa-text:           var(--gpa-gray-700);
  --gpa-text-muted:     var(--gpa-gray-500);
  --gpa-border:         var(--gpa-gray-200);
  --gpa-divider:        var(--gpa-gray-100);
  --gpa-page-bg:        var(--gpa-gray-50);
  --gpa-surface:        #FFFFFF;
  --gpa-tint-blue:      var(--gpa-blue-50);
  --gpa-tint-indigo:    var(--gpa-indigo-50);
  --gpa-link:           var(--gpa-blue-700);
  --gpa-link-underline: var(--gpa-blue-300);
  --gpa-gradient-brand: linear-gradient(135deg, var(--gpa-blue-600) 0%, var(--gpa-indigo-700) 100%);
  --gpa-hero-bg:
    radial-gradient(900px 260px at 30% 0%, rgba(255,255,255,0.75), rgba(255,255,255,0) 70%),
    radial-gradient(700px 300px at 85% 110%, rgba(59,130,246,0.20), rgba(59,130,246,0) 70%),
    linear-gradient(160deg, #D6E6FF 0%, #DCE6FF 60%, #E0E5FF 100%);
  --gpa-hero-title:     var(--gpa-heading);
  --gpa-hero-text:      #334155;
  --gpa-footer-bg:      var(--gpa-blue-950);
  --gpa-footer-text:    #FFFFFF;
  --gpa-footer-link:    #BFDBFE;
  --gpa-footer-accent:  var(--gpa-blue-300);
  --gpa-focus-ring:     var(--gpa-primary);

  /* 3. CALCULATOR ROLES */
  --gpa-calc-bg:           var(--gpa-surface);
  --gpa-calc-border:       var(--gpa-indigo-200);
  --gpa-calc-shadow:       0 2px 4px rgba(30,58,138,0.06), 0 16px 40px rgba(30,58,138,0.14);
  --gpa-calc-input-border: var(--gpa-border);
  --gpa-calc-focus:        var(--gpa-primary);
  --gpa-calc-add-bg:       var(--gpa-tint-blue);
  --gpa-calc-add-border:   var(--gpa-blue-300);
  --gpa-calc-add-text:     var(--gpa-blue-700);
  --gpa-calc-result-bg:    linear-gradient(135deg, var(--gpa-blue-50) 0%, var(--gpa-indigo-50) 100%);
  --gpa-calc-result-line:  var(--gpa-indigo-200);
  --gpa-calc-result-label: var(--gpa-accent);
  --gpa-calc-result-value: var(--gpa-heading);
  --gpa-calc-cta-bg:       var(--gpa-gradient-brand);

  /* Status: calculator results and callouts only, never decoration */
  --gpa-success: var(--gpa-green-700);
  --gpa-warning: var(--gpa-amber-700);
  --gpa-danger:  var(--gpa-red-700);
}
```

Rules:

- Grade band colors (`--gpa-band-a` … `--gpa-band-f` and their `-bg`) stay as they are; point them at palette tokens.
- Any color a file needs that isn't here gets added to the palette and a role first, then used. No one-off hex.
- Green appears only for status (e.g. the "B+ to A− range" text in results), not as a calculator theme color.

## Typography

Lexend is the only font on the site, including all calculators; Inter is removed. One type scale applies to every template (homepage, calculator, content, blog post, college page), set once in `gpa-design-tokens.css`.

| Element | Desktop | Mobile (≤768px) | Weight | Line height | Color | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| Body text (p, li) | 18px | 17px | 400 | 1.7 | `--gpa-text` | Replaces the 20px/18px override and the 16px base |
| H1 (hero title) | 44px | 32px (28px ≤480px) | 700 | 1.15 | `--gpa-hero-title` | letter-spacing -0.01em |
| H2 (section) | 30px | 25px | 800 | 1.3 | `--gpa-heading` | letter-spacing -0.02em; color stays navy |
| H3 | 22px | 20px | 700 | 1.35 | `--gpa-text-strong` | Was 600; now matches H2/H4 weight family |
| H4 | 18px | 18px | 700 | 1.4 | `--gpa-text-strong` |  |
| Small / captions | 14px | 13px | 400 | 1.5 | `--gpa-text-muted` | Breadcrumb, table notes, sources meta |
| Calculator UI (inputs, labels, buttons) | 15–16px | 15–16px | 400–600 | 1.4 | per calculator tokens | Keep inputs at 16px on mobile to stop iOS zoom |
| GPA result number | 40px | 36px | 700 | 1.1 | `--gpa-calc-result-value` |  |

Spacing between text blocks: paragraphs 20px bottom; list items 10px apart; H3 32px above, 12px below; H4 24px above, 8px below. H2 spacing is in Layout.

Font loading (`gpa_fonts()` in `functions.php`):

- Load Lexend weights 400, 500, 600, 700, 800 only, with `display=swap`; drop Inter from every calculator and template.
- Keep the metric-matched "Lexend Fallback" face so the swap causes no layout shift.
- Preload the 400 and 700 files. Optional: self-host the woff2 files in the child theme to remove the Google Fonts round trip.
- Check on a 375px phone that a long course name ("Introduction to Organic Chemistry") stays readable in the calculator inputs; Lexend is wide.

Remove: the GeneratePress Customizer typography values (reset to defaults), the 20px/34px and 18px/30px body rules in `style.css`, and duplicate size values in `content-styles.css`.

## Layout, spacing and page background

The page is a cool-gray background with one continuous white content column; sections flow inside it with no gray gaps between them.

- **Page background:** `body { background: var(--gpa-page-bg) }` (`#F7F7FB`) on all templates.
- **Content column:** white (`--gpa-surface`), max 900px wide, centered, bottom corners 16px. Text blocks (p, lists, headings, quote, callouts) max 800px, centered inside it, as today. Tables, calculator, images and charts may use the full 900px.
- **Laptop widths:** keep the existing rule that narrows the column so side rails fit (`calc(100vw - 680px)` at ≥1440px), and coordinate any change with the rails plan in Ads.
- **Calculator position:** the calculator card overlaps the hero by about 88px on desktop and 60px on mobile (negative top margin, `position: relative; z-index: 2`).

Section spacing (move the Simple CSS header system into `layout.css`, same values, then empty Simple CSS):

| Gap | Desktop | Mobile |
| --- | --- | --- |
| Above a section header (number + H2) | 64px | 48px |
| H2 → subtitle (`.rx-sub`) | 12px | 12px |
| Header → first content | 32px | 24px |
| Between major blocks (`.rx-sec`, groups) | 80px | 56px |
| A header that is first in its card/section | 0 | 0 |

Keep the existing rules that make spacer blocks next to a header do nothing, and that zero the gap after the calculator. Headers are left-aligned on all content templates (override the `has-text-align-center` class on `.rx-h2` and `.rx-sub` in CSS; no content edits needed). The hero stays centered.

## Header, logo and footer

The nav is white, the logo is a "4.0" badge, and the footer is deep navy.

**Top nav**

- White background, 1px bottom border `--gpa-border`, height 72px desktop / 60px mobile.
- Menu links `--gpa-text` 15px 500; active and hover `--gpa-primary` (today the active item is purple).
- Keep the current menu items and dropdowns (GPA Calculators, Grade Calculators, GPA Scale, Grade Conversion) and the mobile hamburger.

**Logo (replaces the current ± icon)**

- Badge: rounded square, 36px desktop / 30px mobile, radius 25% of its size, background `--gpa-gradient-brand`, text "4.0" in white Lexend 800, letter-spacing -0.03em.
- Wordmark beside it: "GPA" in `--gpa-primary` + " Calculator" in `--gpa-text-strong`, Lexend 700 20px (17px mobile), letter-spacing -0.01em.
- Build it as inline SVG (badge) + live text, so it stays sharp and the text stays crawlable. Export the badge as SVG + PNG for the favicon and the Rank Math/Organization logo.
- Favicon: the "4.0" badge. Check it at 16px and 32px; if "4.0" is cramped at 16px, use a rising-bars mark (three bars, ascending) in the same gradient for the 16px favicon only.

**Footer**

- Keep the current footer structure: the five GeneratePress footer widget columns (Calculators, How GPA Works, Popular GPA Scales, International GPA, About), the layout and the bottom line "Helping students since 2012 · Made with care · © 2026". Colors change as below; links change only as listed in Footer link changes.
- `.site-footer`, `.footer-widgets`, `.site-info`: background `var(--gpa-footer-bg)` (`#172554`, was `#1f2937`).
- Column headings: white (unchanged). Links: `var(--gpa-footer-link)` (`#BFDBFE`, was `#D1D5DB`); hover white.
- Bottom bar: top border `rgba(191,219,254,0.2)` (was `#374151`); text and links `var(--gpa-footer-accent)` (`#93C5FD`, was `#9CA3AF`).
- Today the footer colors are defined three times: `style.css` (lines \~460–515), `gpa-homepage.css` (lines \~173–210, a copy), and `database-page.css` (`.db-footer .site-info` and `body.single-colleges .site-info` at `#111827`). Replace all three with one token-based footer block in `layout.css` so every template, college pages included, gets the same footer.

**Footer link changes (Appearance → Widgets / footer menus)**

| Column | Change | Link |
| --- | --- | --- |
| Calculators | Add "Weighted GPA Calculator" | `/weighted-gpa-calculator/` |
| Calculators | Add "Target GPA Calculator" | `/target-gpa-calculator/` |
| Calculators | If the column passes 8 links, remove the lowest-traffic of Middle School GPA Calculator and Semester Grade Calculator (check GA4 pageviews, last 90 days) | — |
| How GPA Works | Add "How to Calculate GPA" as the first link | `/how-to-calculate-gpa/` |
| How GPA Works | Rename "Raise GPA" → "How to Raise Your GPA" | `/how-to-raise-gpa/` |
| How GPA Works | Rename "CGPA to %" → "CGPA to Percentage" | `/cgpa-to-percentage-calculator/` |
| How GPA Works | Rename "GPA Requirements" → "College GPA Requirements" | `/admissions/` |
| International GPA | Rename "France GPA" → "French Grades" | `/grade-conversion/france/` |
| About | Rename "About" → "About Us" | `/about-us/` |

No changes to Popular GPA Scales or the bottom line. Don't add the college-specific calculators to the footer.

## Hero (all templates)

One hero design on every template: a light, glossy blue background, flat bottom edge, breadcrumb, navy title and one subtitle line. It replaces both today's homepage hero (`.gpa-hero`) and the content-page hero band (`body.gpa-hero-band … ::after`).

- **Background:** `var(--gpa-hero-bg)`. No wave SVG; remove the `::after` wave and `--rx-hero-tail` logic.
- **Content, centered:** breadcrumb (see Section headers), then H1 in `--gpa-hero-title`, then one subtitle line in `--gpa-hero-text` 18px (15px mobile), max-width 640px.
- **Remove:** the "Updated for 2026" badge and any benefit chips. Keep the headline area quiet.
- **Padding (desktop):** 56px top; bottom padding = space for the calculator overlap (\~128px), so the card rises about 88px into the hero.
- **Padding (mobile):** 18px top, 20px sides, \~76px bottom; the card rises 60px.
- **Homepage:** same hero styling; its own full-bleed section bands below stay, re-colored with tokens.
- **College pages (`.db-hero`, `.db-archive-hero`):** same background and title colors so the site reads as one design.
- Keep the hero's text contrast ≥ 4.5:1 (navy on the light gradient passes).

## Section headers, TOC, breadcrumb and links

Sections are numbered (01, 02, 03) with CSS counters, the TOC uses the same numbers, and links are clearly styled to drive more page views.

**Section numbers (replace the pill above H2s)**

- Generate the number with a CSS counter on content H2s (`.entry-content h2.rx-h2` and plain content H2s on content templates), e.g. `counter-increment: gpa-sec; h2::before { content: counter(gpa-sec, decimal-leading-zero) }`.
- Style: `--gpa-accent`, 18px (16px mobile), weight 700, 14px gap before the heading text, baseline-aligned.
- Don't number: the hero H1, the FAQ heading, the "Keep planning" heading, Sources, widget/footer H2s, or H2s inside the calculator.
- The number is never part of the heading text in the HTML, so headings, snippets and "Jump to" links stay clean.
- Remove the pill paragraphs (`p.rx-eyebrow`, \~9 per calculator page) from post content with a scripted search-and-replace after a DB export, then delete their CSS. Don't hide them with `display: none`.

**In-page navigation: "On this page" list (all templates)**

- One rule: **each link's text is the H2's text, word for word.** Google can take jump-link labels from either the H2 or the contents link, so they must agree; no shortened chip labels.
- **All screen sizes: collapsed by default, one column.** A `<details>` row, 52px tall: summary "On this page · 8 sections" (16px 600 `--gpa-text-strong`, count in `--gpa-text-muted`) with a chevron in `--gpa-link`; 1px `--gpa-divider` above and below, no box, aligned to the text column. Opening shows the links in one column, 16px `--gpa-link`, a section number before each in `--gpa-accent`, 44px tap rows on mobile.
- The links are in the HTML while collapsed, so Google reads them exactly as if the list were open.
- Placement: under the calculator (calculator pages) or the key-facts box (college and GPA scale pages), above the first content section.
- Server-rendered `<a href="#section-id">` links, never built by JavaScript. Rank Math TOC block stays the source on content pages (title tag `div`/`p`); generated templates output the same markup.
- Exclude FAQ, "Keep planning", Similar colleges and Sources headings so the list matches the numbered H2s. Show it on pages with 3+ numbered sections (site-wide, no exceptions); 24px space below the closed row before the first section.
- Section headings get `scroll-margin-top: 80px` so the sticky header never covers them.

**Headings and IDs (rule for every page)**

- **H2 = the search query.** Entity pages lead with the short name plus the searched term ("Harvard SAT and ACT scores", "Is a 3.7 GPA good?"); calculator pages phrase vague headings as the question or term people search ("What is a good college GPA?"). The H1 keeps the full name.
- **ID = a stable descriptive slug**, the same across pages of one template (`#average-gpa`), never numbers or entity names. Never change an ID on a live page; lock it in the block's HTML anchor field before editing any heading text (Rank Math can regenerate it). If a rename is unavoidable, keep the old ID as a hidden second anchor.
- On live pages an H2 may change only to add the searched term or subject name, never to remove keywords. On pages that already rank, change one page first and compare 28 days of Search Console clicks, CTR and positions before rolling out.
- QA: view-source shows each contents link matching an H2 ID with identical text, and every previously live ID still resolves.

**Breadcrumb (Rank Math breadcrumbs, with schema)**

- Once per page, above the H1 in the hero. Never above section H2s.
- 14px (13px mobile). Links `--gpa-link`, no underline; separators "/" in `#94A3B8` with 8px side margin; current page `#64748B`, not linked.
- On screens ≤480px, hide the current-page item to keep it on one line.

**In-text links (all content templates)**

- `color: var(--gpa-link); font-weight: 500; text-decoration: underline; text-decoration-color: var(--gpa-link-underline); text-decoration-thickness: 2px; text-underline-offset: 4px;` Hover: underline color `--gpa-primary`.
- Always underlined (phones have no hover). 3–5 contextual links per page with descriptive anchor text.

* Scope the link style to plain links in text only (`.entry-content p a`, `.entry-content li a`), and exclude buttons, cards and components: `.wp-block-button a`, `.rx-relcard`, TOC links, breadcrumb, FAQ questions, footer and nav.

**Next-step links and related tools**

- Calculator result panel: primary CTA button ("Plan my target GPA →", `--gpa-calc-cta-bg`, white, 44px tall) plus two text links in the in-text link style (e.g. "Which colleges fit a 3.63?" → admissions, "Convert to percentage").
- "Keep planning" section near the end: 2-column grid (1 column mobile) of 4 cards. Card: white, 1px `--gpa-border`, radius 12px, padding 20px; 40px icon square (`--gpa-tint-indigo`, 20px stroke icon in `--gpa-accent`); title 17px 700 `--gpa-heading` with "→"; one-line description 15px `#4B5563`. Restyle the existing `.rx-relcard` blocks to this.

## Content components

Every content block uses the same tokens, Lexend, 12px corners and 1px borders; style the existing WordPress/Rank Math blocks rather than adding new markup where possible. Reference: the "Content components — design guide" board in the mockup.

| Component | Applies to | Spec |
| --- | --- | --- |
| Quote / expert note | `blockquote`, `.wp-block-quote` | No box or side border. Large “ mark 56px in `--gpa-calc-border`; quote text 21px 500 `--gpa-heading`, line-height 1.55; attribution 14px `--gpa-text-muted`. Real expert quotes only. |
| Table | `.wp-block-table`, `.rx-table`, `.content-table` | Wrapper 1px `--gpa-border`, radius 12px, white. Header row `#F9FAFB`, 14px 600 `--gpa-text-strong`. Rows 16px, 11px×18px padding, 1px `--gpa-divider` lines, `font-variant-numeric: tabular-nums`. Optional highlighted row: `--gpa-tint-blue` bg, `--gpa-heading` 600. Wide tables scroll inside the wrapper (`overflow-x: auto`). |
| Tip callout | `.gpa-callout--tip` | `--gpa-tint-blue` bg, 1px `--gpa-blue-100` border, radius 12px, padding 16px 18px; lightbulb icon `--gpa-primary`; title 16px 600 `--gpa-heading`; text 16px. |
| Note callout | `.gpa-callout--note` | `#F9FAFB` bg, `--gpa-border` border; info icon `#4B5563`; title `--gpa-text-strong`. |
| Heads-up callout | `.gpa-callout--warn` | `--gpa-amber-50` bg, `--gpa-amber-200` border; warning icon `--gpa-warning`; title `#92400E`. Real risks only (e.g. probation). |
| Formula / example | `.content-formula`, `.content-result` | `--gpa-calc-result-bg`, 1px `--gpa-calc-result-line`, radius 12px, padding 20px 22px; label 13px 600 `--gpa-accent`; formula 22px 700 `--gpa-heading`; example line 15px `--gpa-gray-600` with the answer in `--gpa-heading` 600. |
| Key takeaways | `.gpa-takeaways` | White card, `--gpa-border`, radius 12px; title 17px 700; items 16px with indigo check icons. |
| Bulleted list | content `ul` | 6px `--gpa-primary` dots, 12px gap, items 10px apart. |
| Numbered steps | content `ol` | 24px circles, `--gpa-tint-indigo` bg, number 13px 700 `--gpa-accent`. Step cards (`.rx-steps`) use the same 40px icon squares as the related-tool cards. |
| FAQ | Rank Math FAQ block (`.rank-math-block`, `.rank-math-list-item`) | White card, `--gpa-border`, radius 12px; each item a 56px-min row with a top divider, question 17px 600 `--gpa-text-strong`, chevron in `--gpa-accent`; answer 17px body text. Accordion behavior with the first item open; keep Rank Math's FAQ schema output untouched. |

Usage rules: at most one or two callouts per section, never a callout or quote directly next to an ad, and only the three callout types above. Create the `.gpa-callout--*` and `.gpa-takeaways` styles as block styles (register them so editors can pick them on a Group/Paragraph block).

**Collapsible content (all templates)**

Anything that collapses (FAQ answers, mobile footer columns, "How it's calculated", long tables) stays fully in the server HTML and opens on its own when searched for, so it's indexed and reachable from Google's text-fragment links.

- Build it as `<details>`/`<summary>`, or a button controlling content with `hidden="until-found"` that opens on the `beforematch` event. Never `display:none` content that's injected or fetched on open.
- FAQ: first item open, the rest collapsed; FAQPage schema text matches the visible text exactly.
- Find-in-page and text-fragment links (`#:~:text=`) open the matching item automatically; browsers without `until-found` simply show the closed item.
- QA: "view source" shows every answer and every contents link; Rich Results Test passes; tapping a contents link or a fragment link lands on the right section below the sticky header.

**Sources block (end of page, above the footer)**

- 1px top divider `--gpa-border`, 24px padding-top, full 800px text width.
- Title "Sources" 18px 600 `--gpa-text-strong`; under it one line "Reviewed by the GPA Calculator team · Updated \[Month Year\]" 14px `--gpa-text-muted` (the date comes from the post's modified date).
- Numbered list: 24px circles (`--gpa-tint-blue` bg, `--gpa-primary-hover` 12px 600 number), source title 16px `--gpa-text-strong` as the link (no underline, hover `--gpa-primary`), publisher on a second line 13px `--gpa-text-muted`. Prefer .gov, .edu and College Board sources.
- Build it as a reusable block pattern so every page uses the same markup.

## Calculators

All calculators switch to Lexend and the calculator tokens now, through a new `calc-theme.css` override layer; the colors move into the Tailwind config later, during the plugin merge.

**Look (every calculator)**

- Card: `--gpa-calc-bg`, 1px `--gpa-calc-border`, radius 16px, `box-shadow: var(--gpa-calc-shadow)`. The calculator is the only element on the page with a shadow.
- Inputs/selects: 44px tall, 1px `--gpa-calc-input-border`, radius 8px, 16px text; focus ring 2px `--gpa-calc-focus`.
- "Add class/course" button: `--gpa-calc-add-bg`, 1px dashed `--gpa-calc-add-border`, text `--gpa-calc-add-text` 600. Secondary buttons: white, `--gpa-border`, `--gpa-text`.
- Result panel: `--gpa-calc-result-bg`, 1px top border `--gpa-calc-result-line`; label 13px 600 `--gpa-calc-result-label`; value 40px/36px 700 `--gpa-calc-result-value`; meta 14px `--gpa-gray-600`; status words (grade range, standing) in `--gpa-success` / `--gpa-warning` / `--gpa-danger` only.
- Primary CTA (e.g. "Open planner", "Plan my target GPA"): `--gpa-calc-cta-bg`, white 15px 600, 44px tall, radius 8px.
- Target GPA Planner box: today purple-tinted (`#faf5ff`, purple button); switch to `--gpa-tint-indigo` bg, `--gpa-calc-result-line` border, CTA as above.
- Mobile: course rows stack (name full width, grade + credits side by side), 12px side margin on the card.

**How (calc-theme.css)**

- The bundles in `calc-assets/` are compiled Tailwind scoped to `:is(#root, .gpa-calc-portal)`, with colors baked in (`#2563eb`, purple-50 etc.).
- List every color utility actually used across all bundles (`bg-*`, `text-*`, `border-*`, `ring-*`, `from-*`/`to-*`, hover/focus variants), and remap each in `calc-theme.css` to a calculator token, same selector scope, loaded after the bundle.
- Set `font-family: var(--gpa-font)` on `#root, .gpa-calc-portal` and remove Inter from the bundles' font stacks.
- Result: re-coloring a calculator = editing tokens only. When the plugins merge, move these mappings into the Tailwind config (`colors: { primary: 'var(--gpa-primary)', … }`) and delete `calc-theme.css`.
- Update the saved calculator build rule: Lexend (not Inter); colors only from calculator tokens.

## Ads and side rails

Ads stay close to the calculator to protect revenue, but sit flat on the gray page so the calculator keeps the focus. Ad slot IDs and counts don't change.

**Rail position: adaptive gap (rails script, `GPA_RAILS_V5`)**

- Replace the gutter-centering math (`(c.left - railW) / 2`) with: rail edge = content edge ± `gap`, where `gap = clamp(20px, freeSpace, 56px)` and `freeSpace = sideMargin - railW - EDGE`.
- Result: 56px gap on large monitors, shrinking to today's 20px on laptops. The gap must never be the reason a rail hides.
- Keep everything else in the script: measurement on load/resize via `requestAnimationFrame`, read/write phases, rails only requested when shown, nothing below 1024px.

**Rail widths by screen width (only after Freestar confirms the corrected size mapping)**

| Screen width | Rail width | Allowed ad sizes (Freestar) |
| --- | --- | --- |
| ≥1440px | 336px | All up to 336px wide, plus 160x600, 120x600 |
| 1350–1439px | 300px | All up to 300px wide, plus 160x600, 120x600 |
| 1260–1349px | 160px | 160x600, 120x600 only |
| <1260px | Hidden | None |

The correction email (tiers 1440 / 1350 / 1260 / 0, remove the 1000 tier and 320x250 on `siderail_right_1`) was drafted on Oct 2. Build on the rails change set from the Sep 30 "Ad placement adjustment" chat (four rail units incl. `siderail_left_1`/`left_2`, content width `calc(100vw - 744px)` at ≥1440px); don't ship rail width changes until Freestar confirms.

**Slots and labels**

- Remove the 600px `min-height` reservation on rail slots; rails are absolutely positioned, so this causes no layout shift. Pin the ad to the top of its slot (existing flex rule).
- "Advertisement" label directly above each filled ad: 11px, uppercase, letter-spacing 0.06em, `--gpa-text-muted`, 6px gap. Hide the label when the slot is unfilled (`:has(iframe)` or Freestar's render callback), and collapse unfilled slots completely.
- Ads get no border, shadow or background.

**In-content ads**

- `incontent_midarticle`: after the first full content section, never directly below the calculator, TOC or a callout.
- `incontent_bottom`: after the last content section, before Sources.
- Same label rule; centered; 56px space above and below.

## Mobile

On a 390×844 phone, the first screen must show at least three course rows and the GPA result; today the hero alone takes about 430px and only one row is visible.

- Compact hero: 18px top padding, breadcrumb 13px (current page hidden ≤480px), H1 28px, subtitle one line at 15px (shorten the copy per page if it wraps), no badge.
- Calculator card overlaps the hero by 60px, 12px side margins, stacked course rows.
- Body 17px, H2 25px, section numbers 16px, H2 48px above.
- Nav 60px tall, logo badge 30px.
- In-content ad 300×250, centered with label. No rails below 1024px (unchanged).
- Tap targets ≥44px everywhere (FAQ rows, TOC items, buttons, breadcrumb links).
- Test widths: 375px and 390px, with a long course name in the calculator.

## Other templates and edge cases

Every template gets the same tokens, type scale and components; these need explicit attention because they load different stylesheets today.

- **Blog posts (`single-post`):** load only tokens + `style.css` today, so they miss content styles. Give them `layout.css` and `components.css`, the white content column on the gray page, the same title area styling as the hero (light hero background, breadcrumb, navy H1), and section numbers only when the post has a TOC.
- **College pages and archive (`single-colleges`, `post-type-archive-colleges`):** shared hero colors, type scale, tables and FAQ; keep their own layout from `database-page.css`.
- **Archive, category, search and 404 pages:** inherit tokens; check each in the screenshot pass and fix anything still purple or hard-coded.
- **Formidable Forms (contact forms):** map its form colors, borders, button and focus styles to tokens so forms match the calculator inputs.
- **Mobile menu:** white panel, menu links `--gpa-text`, active `--gpa-primary`, tap targets ≥44px.
- **Focus and contrast:** visible focus ring (2px `--gpa-focus-ring`, 2px offset) on every link, button and input; all text pairs ≥4.5:1 (check muted grays on the gray page).
- **Images and captions:** radius 12px, captions 14px `--gpa-text-muted`, centered.

## Cleanup checklist by file

Each file ends with zero hard-coded colors (except inside the brand palette), no duplicate rules, and no `!important` that the new load order makes unnecessary.

- [ ] `gpa-design-tokens.css`: add the color block; update type scale, weights and line heights; retire `--gpa-purple` (alias to `--gpa-accent`, then remove once unused); remove the centered `.wp-block-rank-math-toc-block > h2` rule; base `a`, focus ring and body styles from tokens.
- [ ] `style.css`: remove the 20px/34px and 18px/30px body rules and their template selectors; remove `.content-formula` / `.content-result` (they live in `components.css`); focus outline → `var(--gpa-focus-ring)`; remove the Kargo `min-height` rule (Kargo is disabled); rail slot `min-height: 600px` removed; prune dated comment blocks and dead code.
- [ ] `content-styles.css`: replace all 14 `#2563EB` and other hex values with tokens; hero band `::after` → new hero (no wave, no `--rx-hero-tail`); `.content-rel-icon-purple` → `--gpa-accent`; drop size values now owned by tokens.
- [ ] `gpa-homepage.css`: hero gradient → `var(--gpa-hero-bg)` + hero text tokens; `.gpa-bg-purple` and its CTA → `--gpa-accent`; 10 × `#2563EB` → tokens; keep the full-bleed band layout.
- [ ] `calculator-page.css`: point every `--rx-*` variable at a `--gpa-*` token; `.rx-h2` / `.rx-about h2` color → `--gpa-heading`; left-align header group; delete `.rx-eyebrow` styles after the content cleanup; restyle `.rx-relcard` and step cards per spec.
- [ ] `database-page.css`: audit all \~2,900 lines; replace every hex with tokens; adopt the shared hero, type scale and table/FAQ components; remove anything duplicated by `components.css`.
- [ ] `layout.css` (new) and `components.css` (new): create per this spec.
- [ ] `calc-theme.css` (new): create per Calculators.
- [ ] Simple CSS: move its rules into `layout.css` (header spacing, `.university-gpa-intro`, any hero rules still needed), verify, then empty the box.
- [ ] GeneratePress Customizer: reset Typography (body, H1–H3) and Colors to defaults; confirm Additional CSS is empty (move anything found into the child theme).
- [ ] `functions.php`: font loading per Typography; enqueue the new files in the load order; find where the rails script is printed and update it there.
- [ ] Post content: remove `p.rx-eyebrow` blocks (DB export first, scripted, logged per post).
- [ ] Rank Math: TOC title tag → `div`/`p`; breadcrumbs enabled with "/" separator; Organization logo → new badge.
- [ ] Favicon / site icon: new "4.0" badge (or bars at 16px).

## Rollout, QA and rollback

Ship in seven phases, each previewed with screenshots and approved before going live; content edits and rail widths go last.

1. **Backup:** timestamped copies of all child-theme files, Simple CSS output, Customizer settings export, full DB export.
2. **Tokens + architecture:** color block, type scale, new files, load order; old variable names aliased so nothing breaks. Visual output should barely change.
3. **Migrate colors file by file** to tokens (checklist), then remove purple.
4. **Typography + layout:** one scale, page background, content column, section spacing, Simple CSS and Customizer emptied.
5. **Hero, nav, logo, footer, TOC, breadcrumb, section numbers, links, components.**
6. **Calculators:** `calc-theme.css`, Lexend, result panel, planner box.
7. **Rails + content:** adaptive gap now; rail widths only after Freestar confirms; then the `rx-eyebrow` content cleanup.

QA for every phase (Playwright screenshots, before and after):

- Pages: homepage, College GPA calculator, High school GPA calculator, a GPA scale content page, a blog post, a college page.
- Sizes: 390×844, 768×1024, 1280×800, 1366×768, 1440×900, 1920×1080.
- Checks: no hard-coded hex left (`grep` the child theme); calculator top visible without scrolling at 1366×768; mobile first screen shows 3 rows + result; no horizontal scroll; ads never overlap content; rails present at ≥1440 (and 1260+ after Freestar); calculator inputs, save/restore and GA events (`calculator_used` etc.) still work; Rank Math TOC, breadcrumb and FAQ schema still valid (Rich Results Test); PageSpeed LCP/CLS no worse than before.
- Re-color test: change `--gpa-blue-600` to a test color on a preview, confirm buttons, links, TOC numbers and calculators all follow, then revert.

Rollback: one script restores the backup folder for any phase; DB export restores content. Purge Cloudflare after every push and every rollback.

## Out of scope (later)

These came up but are not part of this build:

- Calculator plugin merge (gpacalculator-manager + Grades & GPA); move the `calc-theme.css` mappings into the Tailwind config then.
- Loading rail ads only after the user scrolls past the calculator.
- Asking Freestar to block animated/auto-expanding rail creatives and limit refresh to in-view, ≥30s.
- GA4 link-click tracking for in-text, result-panel and "Keep planning" links (measure pages per session before/after).
- Self-hosting Lexend.

Open questions for Digant:

- Footer link changes are confirmed (see Header, logo and footer).
- The "Updated for 2026" hero badge is confirmed removed on every template.
