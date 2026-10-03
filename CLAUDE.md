# CLAUDE.md

## Design sources: check before every task

Before every task, re-read the Design System spec (https://claude.ai/code/artifact/1d396bba-2925-4cd3-95b4-7c5ce1bf894d) and the Calculator Design Standard (https://claude.ai/code/artifact/83831044-ed02-48d1-9c61-bece13bdee41). If either changed, sync the repo copies and tell the admissions and GPA scale threads. (Digant, 2026-10-03.)

| Doc | Repo copy | Branch it lives on |
| --- | --- | --- |
| Design System & CSS Overhaul Spec | `docs/design-system-spec.md` | `claude/design-system-overhaul-xkzbf0` |
| Calculator Design Standard | `docs/calculator-design-standard.md` | `claude/calculator-unification-0oc2fc` |

How to check and sync:

- Each repo copy's header comment names the doc revision it was synced from. Read the doc (Claude Docs `read` with `{"kind":"view","sinceRev":<that revision>}`); if nothing changed since, there is nothing to do.
- If it changed: export the doc as markdown, write it below the header comment, update the revision in the header, and confirm the body is byte-identical to the export. Commit on the branch in the table, then push.
- Then tell the admissions thread ("College admissions overhaul, continued") and the GPA scale thread ("Set up repo and shared core") what changed, through the project coordinator. Name the commit and the sections that changed.
- The Design thread owns edits to both docs. Other threads send rule text to it via the coordinator instead of editing the docs themselves.
