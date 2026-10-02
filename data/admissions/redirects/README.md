# Old /admission/ URLs

`old_admission_urls.tsv`: the 625 `/admission/<slug>/` URLs that Google Search Console showed between June 2025 and
September 2026 and that have no college post under the same slug today, most-shown first. Since the September 20,
2026 move to `/admissions/`, many big colleges' posts use shorter slugs (`harvard-university` is now `harvard`), so
WordPress's 404 guess, which matches by slug prefix, can't find them. `same_college_today` is the current post whose
title gives that old slug (126 of them); the rest need a match by name before they get a redirect.
