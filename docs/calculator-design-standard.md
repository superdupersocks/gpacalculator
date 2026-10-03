<!-- Repo copy of the Claude Doc "gpacalculator.net — Calculator Design Standard"
     https://claude.ai/code/artifact/83831044-ed02-48d1-9c61-bece13bdee41
     Synced 2026-10-03 from doc revision 52. The doc is the source of truth: when it changes, re-export it
     (Claude Docs export, markdown) over this file and update the revision here. Don't edit this copy by hand. -->

# gpacalculator.net — Calculator Design Standard

Oct 2, 2026 · Digant

## Purpose and scope

Every calculator on gpacalculator.net is designed from this standard, so they all look and behave like one product. It covers page fit, layout, spacing, controls, results and the sticky result pill.

- **This doc wins on calculator layout and spacing.** The site-wide [Design System & CSS Overhaul Spec](https://claude.ai/code/artifact/1d396bba-2925-4cd3-95b4-7c5ce1bf894d) still owns colors, type scale and non-calculator templates.
- **The calculator build skill** owns features, engagement flow, code standards and math QA. It should point here for anything visual.
- **Values below replace** the hero padding in the site spec (56px desktop / 18px mobile) so every template shares one hero.
- Built once in the shared calculator core. Each calculator supplies only its fields, math, follow-up steps and copy.

## Above-the-fold goals

A visitor can start typing immediately and see their result without scrolling. All spacing below is budgeted backward from these targets.

| Screen | Calculator starts at | Must be visible on first screen |
| --- | --- | --- |
| Desktop, 1366×768 | ≤ 280px (live today: 305px) | 4 course rows + the live result once data is entered |
| Mobile, 390×844 | ≤ 180px (live today: 268px) | The first course field, directly under the card header (no banner on a first visit, no options, no semester header) |

When courses or semesters push the result off-screen, the sticky result pill takes over (see Sticky result pill).

## Page hero (theme template, not the calculator)

The hero (breadcrumb, H1, subtitle) is rendered by the WordPress page template and styled in the theme's `layout.css`; the calculator plugin renders only the card below it. The values here are listed because they decide where the card lands on screen. The overlap is page CSS on the card's wrapper, not calculator code, and the calculator never repeats a title or intro.

| Element | Desktop | Mobile (≤768px) |
| --- | --- | --- |
| Top padding | 40px | 16px |
| Breadcrumb | 14px; current page shown | 13px; current page hidden ≤480px |
| Breadcrumb → H1 | 8px | 6px |
| H1 | 44px, 700, line-height 1.15 | 28px, 700; wrap to 2 lines only if the title can't fit |
| H1 → subtitle | 12px | 8px |
| Subtitle | 18px, one line, max 640px | 15px, one line (shorten copy per page if it wraps) |
| Bottom padding | 128px | 76px |
| Card overlap into hero | 88px | 60px |
| Side padding | as theme | 20px |

No badges, chips or wave. Background, colors and the centered layout follow the site spec. College subtitle: "Semester and cumulative GPA on a 4.0 scale." (one line at 390px).

## Calculator card

One white card, 800px max, the only element on the page with a shadow.

| Property | Desktop | Mobile |
| --- | --- | --- |
| Width | max 800px, centered | full width minus 14px side margins (14px from the screen edge) |
| Padding | 32px | 16px |
| Radius | 16px | 16px |
| Border / shadow | 1px `--gpa-calc-border`, `--gpa-calc-shadow` | same |
| Space reserved for results | none | none |
| Outer bottom margin | none (theme owns spacing below) | none |

No hero, H1 or intro inside the card, no empty gutters inside rows, and no `#root` min-height.

## Course rows

Rows are a tight, scannable list: every row stays open on desktop (phones collapse finished rows, below), and column labels appear once, not per row.

| Property | Desktop | Mobile (≤640px) |
| --- | --- | --- |
| Layout | One line: name · grade · credits (· level where weighting applies) · remove | Line 1: course name + × remove. Line 2: grade (\~110px) and credits (\~80px) side by side, left-aligned, not full width (level, where used, after them) |
| Column labels | Once, above the first row: "Course (optional)", "Grade", "Credits", the same words on every calculator | Placeholders only ("e.g. Calculus I"), no per-row labels |
| Gap inside a row | 12px between fields | 8px between the two lines |
| Gap between rows | 12px | 16px, with a 1px `--gpa-divider` line |
| Collapsed rows | Never | A finished row the student isn't editing collapses to one line, "MATH 121 · B+ · 4 cr", with "Edit" on the right; tapping it reopens the row. Earlier semesters collapse to "Fall · 4 classes · GPA 3.41"; the latest stays open. Never collapsed "Add Class" rows |
| Starting rows | 1 blank row per semester; choosing a grade in the last row adds the next blank row. No "Add class" button | Same |
| Semester header | None with one semester (the first course field sits under the card header); with several, renamable title + semester GPA on one line, 16px above its rows | Same |

- Rows read as one list because each field has its own border; a wider gap only spreads the list out.
- Long course names ("Introduction to Organic Chemistry") must stay readable at 375px. Lexend is wide, so truncate with an ellipsis, never wrap the input.
- Enter moves to the next row; a new row is added when Enter is pressed on the last one.
- Phones: a collapsed row shows the course name on the left and "grade · credits" at the right, then "Edit". Only the course name truncates (ellipsis); the grade and credits are never cut and always sit at the right edge. A not-counted grade collapses as "P · not counted" in place of the credits.

## Options

- Grading scale, "Add previous GPA" and "Major GPA" live behind one "Options" link in the card header. It is closed by default and shows nothing until opened. The default scale is 4.0.
- When any option differs from the default, the link reads "Options · N on".
- The Major column, its column label and the per-row Major tick box appear only while Major GPA is on.

## Phone entry controls

- Grade opens a bottom sheet with a grid of letter buttons, four per row, 48px tall, plus Cancel. Escape or a tap on the backdrop closes it.
- Credits open a bottom sheet of quick buttons, 1 2 3 4 5 and "Other". Other shows the number field with the decimal keypad. The default is 3.
- The row layout stays as in Course rows: name + × on line 1; grade (\~110px) and credits (\~80px) on line 2, left-aligned.

## First screen

At 390×844 the first course field is on the first screen, with nothing between it and the card header: no banner on a first visit, no options, no semester header.

## Keep going

On phones, Keep going lines up with the article text, 20px from the screen edge, not with the calculator card's 14px edge. It has 24px above it.

## Semesters

Removing a semester lives in a ⋯ menu on the semester header ("Remove Fall"), never as a bare button beside the name. Removing shows a toast with Undo for 8 seconds.

## Not-counted grades

- P, NP and W are on every college grade list, last, under a "Not counted in GPA" heading: a labelled group in the phone grade sheet, an optgroup in the desktop select.
- They are left out of the GPA math and the credit total and need no credits. The row shows "Not counted: P (pass) isn’t counted in GPA." (likewise NP (no pass), W (withdrawn)), and the result note counts them ("3 courses not counted").
- QA covers each one on every scale: GPA and credits unchanged, note shown, no error.

## Controls

Every control is at least 44px tall, uses Lexend and takes colors only from calculator tokens.

| Control | Spec |
| --- | --- |
| Inputs / selects | 44px tall, radius 8px, 1px `--gpa-calc-input-border`, 16px text (stops iOS zoom), 2px `--gpa-calc-focus` ring |
| Grade select | Empty option reads "Grade", never "Select"; selected grade centered |
| Credits field | Number field, text centered, decimal keypad on phones (inputmode="decimal"), default 3 |
| Card header | One line: an "Options" link on the left, the My saves (folder) and Save icons on the right. No step bar on any calculator; the planner sits after the result and opens only from the result's planner button |
| Add class (planned-course sections only) | `--gpa-calc-add-bg`, 1px dashed `--gpa-calc-add-border`, text `--gpa-calc-add-text` 600 |
| Secondary (Add semester, Start over, Save, Share) | white, 1px `--gpa-border`, `--gpa-text`; text-style buttons allowed in the action row |
| Primary CTA ("Plan next semester’s grades") | `--gpa-calc-cta-bg`, white 15px 600, 44px tall, radius 8px |
| Action row | Below the last row, 16px above; one line on desktop, wraps on mobile. "Start over" is hidden until something is entered (a course name, grade or previous GPA) and hides again when everything is cleared |

UI text 15–16px at 400–600. Grade and credits text is centered in its field; other numbers are right-aligned. All numbers use `tabular-nums`.

## Result panel and planner box

The result appears under the action row as soon as there's enough data, opens with a plain verdict, and leads to one next step.

| Part | Spec |
| --- | --- |
| Panel | `--gpa-calc-result-bg`, 1px top border `--gpa-calc-result-line`, radius 12px, 20px padding, 16px below the action row |
| Label | 13px 600 `--gpa-calc-result-label` ("Your GPA", "Cumulative GPA") |
| Number | 40px desktop / 36px mobile, 700, `--gpa-calc-result-value`, tabular-nums |
| Verdict | One sentence: "You have a 3.42 — a solid B+ average" |
| Status words | Only `--gpa-success` / `--gpa-warning` / `--gpa-danger` |
| Multiple semesters | Cumulative is the big number; each semester GPA sits in its own header |
| Next step | One primary CTA + two in-text links, numbers passed forward in the URL |
| Planner box | `--gpa-tint-indigo` bg, 1px `--gpa-calc-result-line`, primary CTA; directly under the result |
| Empty state | Nothing shown and no space reserved until there's data |

At-risk results (below 2.0, failing, near a target) switch the CTA to the rescue path with the target preset.

**Result label.** "Semester GPA" while there is one semester with grades and no previous GPA; "Cumulative GPA" once there are 2+ semesters with grades or a previous GPA is entered.

**Planner button.** "Plan next semester’s grades" (below good standing: "Plan getting back above 2.0"). It fits on one line at 375px.

**"Is my GPA good?"** One tier per range, each saying what the number means for something students use it for:

| GPA | Wording |
| --- | --- |
| 3.7 and up | "X is in the A range: high enough for Latin honors at many colleges and for competitive grad programs." |
| 3.5 to 3.69 | "X is where Dean’s List and cum laude usually start, and above the 3.0 most grad schools ask for." |
| 3.0 to 3.49 | "X is above 3.0, the usual minimum for grad school and many scholarships." |
| 2.5 to 2.99 | "X is above good standing but under the 3.0 many grad schools and scholarships ask for." |
| 2.0 to 2.49 | "X keeps you in good standing (2.0), but many scholarships and grad programs want 3.0." |
| Below 2.0 | "X is below 2.0, the usual line for good academic standing. The planner shows the way back." |
| Scales above 4.5 (e.g. 7-point) | "X out of N. Compare it with the cutoffs your school publishes." |

## Sticky result pill

Whenever the result panel is off-screen, a floating pill shows the live result so students never scroll to check it. The high school calculator's "GPA 3.67 · Details →" pill is the base pattern; every calculator gets it from the shared core, on mobile and desktop.

**Behavior**

- Shows only while the result is still below the screen (IntersectionObserver). Hides as soon as any part of the result is visible, once the student has scrolled past the result, while a text field has focus and while a bottom sheet is open, so it never sits over the result, Keep going or the article.
- The whole pill is one button; tapping scrolls to the result panel.
- Zero layout height: it never pushes content.
- Hides while a text input (course name) has focus, because iOS moves fixed elements above the keyboard. Grade and credit selects don't hide it, so it updates live as grades are picked.
- Sits above the mobile sticky ad (offset = measured ad height + 12px, bottom safe area included); never covers the ad.
- `html { scroll-padding-bottom: 80px }` so a focused field is never scrolled under the pill.
- Fades in/out over 150ms; no animation with `prefers-reduced-motion`.
- Not `aria-live` (the result panel already announces); `aria-label` reads "Your GPA 3.42, go to result".

**Content by calculator**

| Calculator | Pill text |
| --- | --- |
| GPA, one semester | GPA **3.42** · Details → |
| GPA, several semesters | Cumulative **3.42** · This term **3.60** |
| GPA, weighted levels used | Weighted **4.12** · Unweighted **3.70** |
| Grade calculators | Grade **87.4% B+** · Details → |
| Conversions | the converted value + scale |

"This term" is the semester the student last edited. If two values don't fit at 360px, drop "Details →" first.

**Look**

| Property | Spec |
| --- | --- |
| Size | 48px tall, padding 0 8px 0 18px, radius 999px, max width calc(100% − 32px), centered |
| Background / shadow | `--gpa-calc-pill-bg`, `--gpa-calc-pill-shadow` |
| Label | 13px 500 `--gpa-calc-pill-label` |
| Value | 18px 700 `--gpa-calc-pill-text`, tabular-nums |
| Details chip | 36px tall, radius 999px, `--gpa-calc-pill-chip-bg`, white 14px 600 |

## Standard features and flow

Every calculator ships the same core set; full detail lives in the calculator build skill.

- **Core (all):** live result with verdict, sticky result pill, "Show how it's calculated", Try a sample (opt-in), Start over with Undo, auto-save and restore, named saves, share link, CSV, print/PDF, inline help.
- **GPA calculators:** multiple renamable semesters, previous GPA + credits, grading scale selector, course levels where weighting applies, target GPA planner, what-if, insight cards.
- **Flow:** the result → the most likely next calculation, opened from a button under the result, never a step bar (planner for GPA, "What do I need on the final?" for grades), then 2–4 "Keep going" links chosen from the follow-up map.
- **Easy beats complete:** no feature may make the basic path (pick grades, see GPA) slower.

## Save, return and sample

A returning student finds their courses already filled in; nobody ever retypes a calculation on the same device.

| Feature | Behavior | Where it shows |
| --- | --- | --- |
| Auto-save | Saves on every change (debounced \~500ms) and on `pagehide` / `visibilitychange`; refresh loses nothing | "Saved on this device" in 13px `--gpa-text-muted` at the right of the action row, after the first change |
| Come back | On return, the last draft restores automatically; no onboarding box | Banner at the top of the card: "Welcome back — we restored your last calculation · Start fresh" (`--gpa-tint-blue`, 40px min, dismissible) |
| My saves | Save as (named), open, rename, delete, new; asks before replacing unsaved changes | Folder + save icon buttons, 44px, top-right of the card |
| Try a sample | Opt-in, never pre-filled; realistic data; can't be saved; Clear brings back the student's own draft | Text link in the action row for first-time visitors; returning visitors see a smaller "Show an example" link instead. Banner: "Viewing a sample · Clear" (`--gpa-tint-indigo`) |
| Share | Copy link (full state in the URL hash), copy summary, CSV, print/PDF; a shared link never overwrites the visitor's own draft | In My saves menu; shared view banner: "Viewing a shared calculation · Save a copy" |
| Start over | Clears the current draft only (not named saves) | Action row; "Undo" toast for 6 seconds, placed above the result pill |

- Storage: `localStorage` behind try/catch, key `gpac:<calculator-id>:v<schema>`; migrate drafts from the legacy Bolt keys instead of discarding them. Without storage, everything works except saving.
- Saves live on one device and browser. The share link is how a student moves a calculation to another device; no accounts or login for now.
- QA: reload restores the draft; sample → Clear returns the previous draft; a shared link opened in a fresh browser doesn't touch an existing draft; Start over → Undo restores everything.

## GPA history chart

Not used on GPA calculators (Digant, Oct 3, 2026): no history chart. Goals stay as text status lines under the result, and the what-if slider stays in the planner.

## Goal line

The planner's target GPA is the student's goal; there is no "+ Add a goal" button or goal menu. Once a target is set, one goal line shows under the result while the planner is closed. Tapping it opens the planner; × clears the goal.

**Goal line wording** (one line under the result):

- Met: "Your goal: 3.00. You’re there, 0.34 above it." in `--gpa-success`.
- Reachable: "Your goal: 3.50. You need a 3.83 over your next 15 credits." in `--gpa-text-strong`; uses the planner math and the planner's credits value (default 15 college, 1 year high school).
- Out of reach next term: "Your goal: 3.90. The highest possible next term is 3.81; reachable in 3 terms at 4.0." in `--gpa-warning`.
- Below good standing: rescue wording in `--gpa-danger`, and the planner button reads "Plan getting back above 2.0".
- The goal saves with the draft and named saves; no goal is set by default.

## Home screen app (PWA)

The site installs as an app with the 4.0 icon, opens straight to the student's saved calculation and works offline. No push notifications for now.

| Part | Spec |
| --- | --- |
| Manifest | name "GPA Calculator", short\_name "GPA Calc", 4.0 badge icons 192/512 + maskable, display `standalone`, scope `/`, start\_url `/?source=pwa` (GA4 can tell app opens apart) |
| App shortcuts | College GPA, High school GPA, Grade calculator, Final grade calculator |
| Service worker | `/sw.js` at the site root; network-first for pages, cache-first for calculator CSS/JS; calculator pages a student has used work offline; never caches ad or analytics scripts; Cloudflare serves `sw.js` with `no-cache` |
| Install button, Android/desktop | Our own button using the browser's install event, never the browser's automatic prompt |
| Install button, iPhone | Opens a 3-step visual guide: Share → Add to Home Screen → Add |
| When the button shows | After the student has a result, on their second visit or later; as a text link "Add GPA Calculator to your home screen" under the result links |
| Never | On page load, as a popup, or again for 90 days after dismissal; hidden once installed |
| Tracking | GA4 `pwa_install_click`, `pwa_installed`, and `pwa_open` (standalone display mode) |

Before launch, confirm with Freestar that ads serve normally in standalone mode.

## Features by calculator

The per-class and finals ideas live in the grade calculators, not inside the GPA calculator, and the calculators hand results to each other so students move between them.

| Calculator | Extra features | Hands off to |
| --- | --- | --- |
| College / High school / Homepage GPA | Goal line from the planner target; no history chart | Grade calculator per class ("Track this class" on a course row) |
| Grade calculator | Assignment tracker per class (categories, weights, points or %), current letter grade, what-if for upcoming work; one save per class so students come back after each graded assignment | "Send to my GPA": puts the class's current letter into the GPA calculator's matching course row |
| Final grade calculator | Finals planner: every class at once (current grade, final weight, target) → score needed on each final, plus the GPA that results | GPA calculator with the resulting grades filled in |
| Semester grade calculator | Pulls the class's quarter or term grades from the grade calculator when saved | GPA calculator |

- Handoffs use the shared storage namespace (`gpac:`) plus URL parameters, so they work with no accounts.
- A handoff never overwrites existing rows silently: it fills a matching course name, or adds a new row and says so.

## Course features (opt-in)

High School v3.2's course features move into the shared core as an opt-in that each calculator's profile turns on. They arrive when High School and the Homepage move onto the core, after College has been live and stable for about a week.

| Feature | Behavior |
| --- | --- |
| Course catalog | Suggests course names as the student types |
| Nicknames | Common short names resolve to the full course ("APUSH" → AP U.S. History) |
| Auto level | The level (Regular, Honors, AP, IB…) sets itself from the course name, but never overrides a level the student changed by hand |
| Boost label | The row shows the level and its boost, e.g. "AP · +1.0" |

| Calculator | Course features |
| --- | --- |
| High School, Homepage (high school mode), Weighted GPA | On |
| Middle School | On, with its own course list and no AP or IB |
| College (and university calculators) | Off: course names vary by school |

## Grade categories (grade calculators)

- Category names are suggested as the student types: Homework, Quizzes, Tests, Labs, Projects, Participation, Midterm, Final Exam.
- An "Add typical categories" button adds that set in one click.
- Weights start blank with "e.g. 20%" placeholders; the calculator never guesses a weight.
- A warning shows under the list while the weights don't add up to 100%, with the current total.

## New tokens

Add these to `gpa-design-tokens.css` under Calculator roles; every value above that isn't already a token comes from here.

```css
:root {
  /* Calculator spacing */
  --gpa-calc-pad:          32px;
  --gpa-calc-row-gap:      12px;
  --gpa-calc-field-gap:    12px;
  --gpa-calc-section-gap:  16px;

  /* Sticky result pill */
  --gpa-calc-pill-bg:      var(--gpa-blue-950);
  --gpa-calc-pill-text:    var(--gpa-surface);
  --gpa-calc-pill-label:   var(--gpa-blue-300);
  --gpa-calc-pill-chip-bg: rgba(255,255,255,0.14);
  --gpa-calc-pill-shadow:  0 8px 24px rgba(23,37,84,0.28);
}

@media (max-width: 640px) {
  :root {
    --gpa-calc-pad:        16px;
    --gpa-calc-row-gap:    16px;
    --gpa-calc-field-gap:  8px;
  }
}
```

Hero spacing values (Page hero) go in `layout.css` as tokens too, replacing the site spec's 56px / 18px.

## Gaps in live calculators

Measured on the live site on Oct 2, 2026 at 1440×1000 and 390×844. Other calculators get the same audit in their preview.

| Calculator | Gaps against this standard |
| --- | --- |
| College GPA | No sticky result pill; mobile rows are collapsed accordions all labeled "Add Class"; 27px gap between rows; result off the first screen on mobile; purple planner box; Inter font; no sample or share link (legacy Bolt build) |
| High school GPA | Pill uses a hard-coded dark color and covers the 4th row's grade field; purple accents on "Your GPA" label and Add class |
| Page hero (all templates) | 56px desktop top padding; mobile H1 and subtitle each wrap to 2 lines |

## QA checklist before shipping

A calculator ships only when every box passes in the preview screenshots.

- [ ] Desktop 1366×768: card starts ≤ 280px; 4 rows + result visible with data entered
- [ ] Mobile 390×844: card starts ≤ 180px; 3 rows + result visible with data entered
- [ ] Row gaps match tokens (12px desktop; 8px inside / 16px between on mobile); no accordions
- [ ] Pill appears when the result is off-screen, hides when it's in view, never covers an input or the sticky ad, hides while typing a course name
- [ ] Long course name readable at 375px
- [ ] No hex values in calculator CSS/JS (`grep`); re-color test with `--gpa-blue-600` passes
- [ ] Lexend only; no purple
- [ ] Screenshots at 390×844, 768×1024, 1366×768, 1440×900: empty, mid-typing (pill visible), full result
- [ ] Math QA suite at 100% and flow test with zero console errors (per the build skill)
- [ ] Labels read "Course (optional)", "Grade", "Credits"; grade placeholder "Grade"; grade and credits centered; phone row is name + ×, then grade \~110px and credits \~80px, left-aligned
- [ ] 390×844: first course field on the first screen under the card header; one blank row that auto-adds; finished rows and earlier semesters collapse to one line; grade sheet and credit quick buttons work; no step bar or chart; Options closed by default; Keep going 20px from the edge; pill hidden whenever the result is on screen or above it
- [ ] P, NP and W on every scale: GPA and credits unchanged, note shown, no error; collapsed rows cut only the name; "Start over" hidden until there is input; planner button on one line at 375px
