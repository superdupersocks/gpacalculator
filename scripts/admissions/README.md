# Admissions data

Builds one clean row per college for `/admissions/` from Department of Education sources. Plan and field list:
the "Admissions Data Phase 1" doc in the project. Nothing here touches the live site; Phase 2 imports the output.

| Step | Script | Output in `data/admissions/` |
| --- | --- | --- |
| 1. Download | `fetch.py` | `raw/` (not committed), `manifest.json` (URLs, SHA-256, IPEDS year) |
| 2. Clean and join | `build.py` | `institutions.csv`, `field_sources.json`, `qa_report.md` |
| 3. Link existing posts | `match.py` | `match.csv`, `match_review.csv` |

The cloud sessions can't reach ed.gov, so these run in GitHub Actions (`.github/workflows/admissions-data.yml`):
by hand from the Actions tab, or on any push that changes these scripts on a `claude/admissions-*` branch. The
workflow commits the outputs back to the branch. Locally: `pip install openpyxl`, then run the three scripts in
order. Tests: `python3 tests/admissions_qa.py`.

GPA is not in IPEDS or College Scorecard; it comes from each college's Common Data Set (separate step).
