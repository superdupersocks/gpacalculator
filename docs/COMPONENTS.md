# gpacalculator.net component library

Use these for every new page, refresh or rewrite. In the WordPress editor: **+ → Patterns → GPA components**.
The private page **Component library** on the site shows every component rendered, with the same notes.
Colors come from the design tokens (`gpa-design-tokens.css`, layers 1–4); never paste hex colors into content.

| Component | Pattern (editor) | Use it for | Rules |
|---|---|---|---|
| Section | Section (numbered heading + intro) | Every H2 section of a page | The H2 is numbered automatically. One intro line, then body. No pill above it. |
| Calculator | Calculator | A calculator inside page content | `[gpcm_calculator id="profile-id"]` (GPA Calculator Manager profile). Calculator-template pages get theirs from the template. Put the Rank Math TOC block right under it. |
| Calculation example: GPA | Calculation example: GPA | Any "here's how it's calculated" GPA example | `[gpa_example type="gpa" rows="Course|Grade|Credits; …"]`. Grade points use the 4.0 scale (A+ and A = 4.0 … F = 0.0); add a 4th field to override. Totals and GPA are computed. |
| Calculation example: weighted GPA | Calculation example: weighted GPA | Honors/AP/IB examples | `[gpa_example type="weighted" rows="Course|Level|Grade|Credits; …"]`. Honors +0.5, AP/IB/Dual +1.0 (`boosts="honors:0.5,ap:1"`). Shows weighted and unweighted GPA. |
| Calculation example: course grade | Calculation example: weighted course grade | Grade-weight examples | `[gpa_example type="grade" rows="Category|Score %|Weight %; …"]`. Course grade = Σ score × weight ÷ total weight. |
| Formula box | Formula box | The one formula a page teaches | One formula line, then up to three definition cards. |
| Steps | Steps (how it works) | "How to use / how it works" | Exactly three cards, a short title and one or two sentences each. |
| Callout: tip / note / heads-up | Callout: tip, note, heads-up | Practical tip; neutral caveat; warning | First bold word is the title. One callout per section at most. |
| Key takeaways | Key takeaways | Summing up a guide | Three to five one-line points. |
| Data table | Data table | Any comparison or reference table | Header row, short caption. No inline colors. |
| Grade scale table | Grade scale table | GPA ↔ percentage ↔ letter tables | Use class `gpa-scale-table`; rows color by grade band automatically. |
| FAQ | FAQ (Rank Math, with schema) | Every page's questions | Rank Math FAQ block only (gives FAQPage schema). Two or three sentence answers. |
| Related tools | Related tools | End-of-page internal links | Two or three cards, three to five links each; one link per target page. |
| Sources | Sources | Primary-source citations | 1–3 primary sources, only for facts. |

## Page order (calculator pages)
Hero → calculator → TOC → sections (steps, example, formula, scale, …) → FAQ → related tools → sources.

## Notes
- Worked examples are one real `<table>` (caption, header row, row headers) with the result as the table footer, so
  search engines and screen readers read them as data. Phones get a two-line layout from the same markup.
- Grade badges use the grade-band tokens and do not follow the theme color.
- Converting an old hand-built example: `scripts/wp/example_convert.php` (dry run by default; checks the numbers match).

## Content rules (Digant, apply to every page)

- **Internal links:** don't hand-insert them in body text; Internal Link Juicer adds them, so give every page its ILJ keyword set. The Related tools cards are the one deliberate exception (Digant 2026-10-03). Wherever manual links exist, link each target page at most once per page.
- **Sources:** cite only factual claims, primary sources only (NAEP/NCES, College Board, a college's own admissions page or Common Data Set), 1–3 per page, not near the top, followed links.
- **FAQ:** one Rank Math FAQ block per page (one FAQPage schema), built from highly searched questions.
- **Top of page:** no data dump. Put quick facts in the quote callout and add a lead-in line before every table.
