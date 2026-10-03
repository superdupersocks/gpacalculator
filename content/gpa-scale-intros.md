# /gpa-scale/ page intros (draft, not live)

One intro per `/gpa-scale/<x-x>-gpa/` page, shown under the H1. These pages use the content-page template
(`gpa-content-page`), where `gpa_calc_hero_intro_block()` lifts the page's first paragraph block (40+ characters,
no shortcode) into the hero under the title. So each intro goes in as the first `core/paragraph` block. No new
theme code is needed.

Letter grade and percentage come from each page's own text ("A 3.x GPA is equivalent to …"), checked against the
site's scale (`calc-core.js` `STANDARD_SCALE`: A 93+, A- 90–92, B+ 87–89, B 83–86, B- 80–82, C+ 77–79, C 73–76,
C- 70–72, D+ 67–69, D 63–66). "Good" is judged against a national average of about 3.0 unweighted, the figure the
pages themselves use. Pages marked ⚠ disagree with that scale or with themselves (see **Data conflicts**).

| Page | Letter | % | Intro |
| --- | --- | --- | --- |
| /gpa-scale/4-0-gpa/ | A | 93–95% | Already has an opening paragraph block ("A 4.0 GPA usually means straight A's…"), which the hero shows today. No change. |
| /gpa-scale/3-9-gpa/ ⚠ | A | 94% | A 3.9 GPA is an A average, roughly 94% across your classes, just shy of a perfect 4.0. It is an excellent GPA, well above the national average of about 3.0, and competitive almost everywhere you apply. |
| /gpa-scale/3-8-gpa/ ⚠ | A- | 90–92% | A 3.8 GPA works out to an A- average, around 90–92%. That is an excellent record, comfortably above the typical 3.0, and it keeps highly selective colleges and merit scholarships within reach. |
| /gpa-scale/3-7-gpa/ | A- | 92% | With a 3.7 GPA you are averaging an A-, about 92%. It is a very strong GPA, well above the roughly 3.0 national average, and it fits the range many selective colleges look for. |
| /gpa-scale/3-6-gpa/ | A- | 90–92% | A 3.6 GPA sits at the low end of the A- range, around 90–92%. It is a very good GPA, clearly above the 3.0 average, though the most selective schools usually want a little higher. |
| /gpa-scale/3-5-gpa/ ⚠ | B+/A- | 89–90% | A 3.5 GPA lands right on the line between a B+ and an A-, roughly 89–90%. It is a good, above-average GPA that opens doors at many colleges and scholarship programs. |
| /gpa-scale/3-4-gpa/ | B+ | 89% | A 3.4 GPA means a B+ average, about 89%. That is a good GPA, above the national average of around 3.0, and it meets the requirements of a wide range of four-year colleges. |
| /gpa-scale/3-3-gpa/ | B+ | 87–89% | A 3.3 GPA equals a B+ average, about 87–89%. It is a solid, above-average GPA that most colleges will view favorably, especially alongside good test scores and activities. |
| /gpa-scale/3-2-gpa/ | B+ | 87% | A 3.2 GPA is a low B+, close to 87%. It is slightly above the 3.0 national average: a respectable GPA that meets the bar at many colleges, though not at the most selective ones. |
| /gpa-scale/3-1-gpa/ | B | 86% | A 3.1 GPA translates to a B average, about 86%. It is right around the national average of 3.0, a decent GPA that qualifies you for many state universities and colleges. |
| /gpa-scale/3-0-gpa/ | B | 83–86% | A 3.0 GPA is a straight B average, roughly 83–86%. It is the national average, a common minimum for scholarships and many four-year colleges, and a solid base to build on. |
| /gpa-scale/2-9-gpa/ | B | 84% | A 2.9 GPA comes to about 84%, still a B average. It is a touch below the 3.0 national average, so it meets many colleges' minimums but leaves less room at competitive schools. |
| /gpa-scale/2-8-gpa/ | B | 83% | A 2.8 GPA is about 83%, the bottom of the B range. It is a little below the roughly 3.0 average: acceptable at many colleges, and worth raising if you are aiming for selective programs. |
| /gpa-scale/2-7-gpa/ | B- | 80–82% | A 2.7 GPA equals a B- average, about 80–82%. It is below the national average of around 3.0, but still meets the admission minimum at a good number of colleges. |
| /gpa-scale/2-6-gpa/ | B- | 81% | A 2.6 GPA is roughly an 81%, or a B- average. It falls below the typical 3.0, so your options narrow, but many colleges, especially community and state schools, accept it. |
| /gpa-scale/2-5-gpa/ | B- | 80% | A 2.5 GPA works out to about 80%, a low B-. It is below average and sits at the minimum many colleges and scholarships set, so raising it even slightly helps. |
| /gpa-scale/2-4-gpa/ | C+ | 79% | A 2.4 GPA means a C+ average, about 79%. It is below the 3.0 national average and under the 2.5 cutoff some colleges use, though community colleges and some four-year schools still admit students at this level. |
| /gpa-scale/2-3-gpa/ | C+ | 77–79% | A 2.3 GPA is a C+ average, around 77–79%. It is well below the national average of about 3.0; open-admission and community colleges are your most reliable options while you work to raise it. |
| /gpa-scale/2-2-gpa/ | C+ | 77% | A 2.2 GPA comes to roughly 77%, a low C+. That is below average and under most four-year colleges' preferred range, but it can be improved, and community college is a strong path forward. |
| /gpa-scale/2-1-gpa/ | C | 76% | A 2.1 GPA is a C average, about 76%. It is well below the 3.0 national average and just above the 2.0 minimum many schools require, so steady improvement matters most right now. |
| /gpa-scale/2-0-gpa/ | C | 73–76% | A 2.0 GPA equals a C average, about 73–76%. It is the minimum many colleges require for admission or good academic standing, and well below the national average of around 3.0. |
| /gpa-scale/1-9-gpa/ | C | 74% | A 1.9 GPA is roughly a 74%, a low C average. It falls just under the 2.0 minimum most colleges set, so lifting it above 2.0 should be the first goal. |
| /gpa-scale/1-8-gpa/ ⚠ | C- | 72% | A 1.8 GPA works out to about 72%, a C- average. It is below the 2.0 minimum most colleges and many programs require; community colleges offer a route in while you rebuild your grades. |
| /gpa-scale/1-7-gpa/ | C- | 70–72% | A 1.7 GPA equals a C- average, about 70–72%. It is well below average and under most admission minimums, but a few strong semesters can change the picture quickly. |
| /gpa-scale/1-6-gpa/ | C- | 71% | A 1.6 GPA is around 71%, a C- average. That is below the 2.0 threshold most schools use, so focus on raising it; open-admission colleges remain an option in the meantime. |
| /gpa-scale/1-5-gpa/ | C- | 70% | A 1.5 GPA comes to about 70%, the bottom of the C- range. It is well under the 2.0 most colleges require, and improving your grades now will widen your options considerably. |
| /gpa-scale/1-4-gpa/ | D+ | 69% | A 1.4 GPA means a D+ average, about 69%. It is far below the national average of around 3.0 and under most admission minimums, though community colleges are open to you as you improve. |
| /gpa-scale/1-3-gpa/ | D+ | 67–69% | A 1.3 GPA is a D+ average, roughly 67–69%. It is well below what most colleges accept, so the priority is lifting your grades; open-admission and community colleges are a realistic start. |
| /gpa-scale/1-2-gpa/ | D+ | 67% | A 1.2 GPA equals about 67%, a low D+. That is far under the 2.0 most schools require, but grades can recover, and community college offers a second chance to build a strong record. |
| /gpa-scale/1-1-gpa/ | D | 66% | A 1.1 GPA works out to roughly 66%, a D average. It is well below the minimum for most colleges, so improving your coursework comes first; community colleges accept students while they rebuild. |
| /gpa-scale/1-0-gpa/ | D | 65% | A 1.0 GPA is a D average, about 65%. It is the lowest passing average on the 4.0 scale and far below the 2.0 most colleges require, but steady effort can raise it meaningfully. |

## Data conflicts (fix the body before or with the intros)

- **3.8** contradicts itself: one sentence says "an A- letter grade … equivalent to a 90-92%", another says "a 3.8 GPA is
  equivalent to 93% or A letter grade". On the site scale, A- 90–92% is right. Fix the second sentence.
- **3.5** says "90% or a B+ letter grade". On the site scale 90% is an A-. Change it to "about 89–90%, the B+/A- line"
  (as in the draft intro) or to "89% or B+".
- **1.8** says "73% or a C- letter grade". On the site scale 73% is a C. Change it to "72% or C-" (as drafted).
- **3.9** says "94% or A", while the 4.0 page puts a 4.0 at 93–95%, so a 3.9 reads as high as a 4.0. A minor issue:
  consider "about 93%". The draft keeps the page's 94% so the two don't disagree.
- Checked and fine: 1.1 ("from a possible 4.0 total GPA is equal to a 'D'") and 1.2–1.0, which state both % and letter.
- **3.8** image: the caption is fixed, but the chart image (`3.8-GPA-870x1024.png`) may also say 93%/A. Check it by eye.

## Block conversion plan (not done yet)

30 of the 31 pages are a single Classic block (4.0 is already blocks). The approach: convert on the server with
WP-CLI, not by clicking "Convert to blocks" 30 times.

1. **Parse** each page's HTML (PHP `DOMDocument` inside `wp eval-file`) after `wpautop()`, so loose text lines become
   `<p>` exactly as they render today.
2. **Map** top-level nodes to core blocks: `<p>` → `core/paragraph`, `<h1-6>` → `core/heading` (level + inline
   styles kept), `<ul>/<ol>` → `core/list` with `core/list-item` children, `<table>` → `core/table`,
   `[CollegeDB …]` → `core/shortcode`. Anything that doesn't map cleanly (the leftover chat-UI `<div>` wrappers on
   3.6, inline `<span style>`) becomes `core/html` with its markup unchanged, so nothing is lost.
3. **Prepend** the approved intro as the first `core/paragraph`, which the hero picks up automatically.
4. **Verify** per page before saving: render old and new content through `apply_filters( 'the_content' )` and diff
   the HTML (whitespace-normalised). The only allowed difference is the new intro paragraph. Any other diff stops
   that page.
5. **Save** with `wp_update_post()`, which keeps a revision (one-click rollback in the editor), after a fresh DB
   backup. Then purge Breeze and check all 31 pages: 200, the intro under the H1, and FAQ schema still present
   (the theme builds FAQ schema from headings).

The `[CollegeDB]` shortcode stays in the content as a `core/shortcode` block, still empty, until the new college
tool replaces it. Alternatively, the conversion could drop the hidden closing section and the shortcode for good
(revisions keep them) and retire the theme filter. Decide before converting.
