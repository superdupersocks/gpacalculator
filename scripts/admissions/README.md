# Admissions data

Builds one clean row per college for `/admissions/` from the colleges' own Common Data Sets and Department of
Education sources. Plan and field list: the "Admissions Data Phase 1" doc in the project. Nothing here touches
the live site; Phase 2 imports the output.

| Step | Script | Output in `data/admissions/` |
| --- | --- | --- |
| 1. Download | `fetch.py` | `raw/` (not committed), `manifest.json` (URLs, SHA-256, year of every file) |
| 2. Clean and join | `build.py` | `institutions.csv`, `field_sources.json`, `qa_report.md` |
| 3. Link existing posts | `match.py` | `match.csv`, `match_review.csv` |
| 4. Find each college's CDS | `cds_sources.py` | `cds_sources.csv` |
| 5. Read the CDS files | `cds.py` | `cds_values.csv`, `cds_provenance.csv`, `cds_review.csv` |

The cloud sessions can't reach ed.gov or the colleges' sites, so these run in GitHub Actions: steps 1-3 in
`.github/workflows/admissions-data.yml`, steps 4-5 in `admissions-cds.yml`. Both run by hand from the Actions
tab, or on a push that changes their scripts on a `claude/admissions-*` branch, and commit their outputs back
to the branch. Locally: `pip install openpyxl pypdf cryptography`, install poppler-utils, then run the scripts
in order. Tests: `python3 tests/admissions_qa.py`.

## Sources

- **IPEDS** (required): every core field. `fetch.py` takes each file from its newest complete release, then
  replaces it with the newer provisional release when NCES has one (read from the Access database, since the
  data generator doesn't serve those tables); sources from it say "provisional release". Variables are found
  by their dictionary titles, because IPEDS renames them between years.
- **College Scorecard** (extras: accreditor, earnings, debt, Pell and loan shares; the Department's DAPIP
  accreditation download refuses scripted requests, so the accreditor comes from here): its host refuses GitHub's
  runners, so `fetch.py` falls back to `data/admissions/scorecard/institutions.csv`, a slimmed copy of a file
  downloaded by hand (`slim_scorecard.py <zip>`; `source.json` records the file and its SHA-256). The same
  script slims the Field of Study file to `programs.csv` (bachelor's programs: graduates, earnings, debt).
- **Common Data Set** (GPA, admission factors, early decision/action, wait list, newer test scores and counts):
  `cds_sources.py` uses collegedata.fyi's public index (MIT-licensed) only to find each college's newest CDS on
  the college's own site. `cds.py` reads the college's file: its form fields when the PDF is fillable, and its
  text laid out as on the page (`pdftotext -layout` from poppler-utils; spreadsheet and Word cells keep their
  columns). A value is published when it comes from the form fields or when two readings agree (our text,
  collegedata.fyi's extraction, IPEDS for the same fall); a GPA printed on its label's own line may stand alone
  if it passes its checks (including an average its own GPA bands can produce). collegedata.fyi alone is never
  enough: its readings of some files are off by a row or a column. A C1 total left blank is its lines by sex
  added up; C1 counts for another fall than IPEDS's must stay within half to twice IPEDS's with a similar admit
  rate. Before reading, each file is checked against its college: a file whose first-year class is far from
  IPEDS's (another campus's CDS) or that is listed for several colleges is used only where it matches. In
  spreadsheets, the question index the 2025-26 template keeps beside the form is cut off. Everything else goes
  to `cds_review.csv`. Values are cited to the college's own file.

Values are never estimated, and suppressed or missing values stay blank, never 0.
