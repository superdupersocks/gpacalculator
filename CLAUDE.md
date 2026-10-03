# gpacalculator.net: instructions for Claude

## Design sources (Digant, 2026-10-03)

Before every task, re-read the Design System spec (https://claude.ai/code/artifact/1d396bba-2925-4cd3-95b4-7c5ce1bf894d) and the Calculator Design Standard (https://claude.ai/code/artifact/83831044-ed02-48d1-9c61-bece13bdee41). If either changed, sync the repo copies and tell the admissions and GPA scale threads.

- Repo copies: `docs/design-system-spec.md` (Design System spec) and `docs/calculator-design-standard.md` (Calculator Design Standard). Each starts with a comment naming the doc revision it was synced from.
- How to check and sync: read the doc with the Claude Docs connector (`read`, ref `{"object":"project","id":"<doc id>"}`), then `export` its tab as markdown. If the export's `rev` is higher than the revision in the repo copy's header, replace everything below the header with the export, update the revision and date, and commit "Sync <doc> (rev N)". Never edit a repo copy by hand; the doc is the source of truth.
- Telling the other threads: report the change (doc, old → new revision, what changed in a line) to the admissions thread and the GPA scale thread, via the project conversation if you can't reach them directly.
