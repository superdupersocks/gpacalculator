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

## Theme deploys: don't drop live code

The live theme's files drift from every branch. `scripts/deploy_theme.sh` therefore stops a deploy that ships a functions.php missing any hook or require that is live now. One example is the `rank_math/schema/nested_blocks` filter, which keeps the collapsed "On this page" list in Rank Math's schema. Merge the live functions.php into your branch first; never use `--allow-dropped-hooks` unless removing that code is the point. After each deploy the script runs `scripts/qa/check_toc_schema.py`, which checks the pages in `scripts/qa/toc-pages.txt`: TOC links must match the H2s and Rank Math's SiteNavigationElement names. Add each page there when it moves to the collapsed list.
