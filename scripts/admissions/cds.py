"""Admissions details from each college's own Common Data Set (CDS), which IPEDS and College Scorecard don't carry.

    python3 scripts/admissions/cds.py [--sources CSV] [--out DIR] [--workers 8]

Reads data/admissions/cds_sources.csv (from cds_sources.py: the newest CDS file on each college's own site)
and, for each college, downloads the file (the archived copy if the college's link is gone) and reads:
- C1  first-year applicants, admits and enrollees (fresher than IPEDS: a 2025-26 CDS describes fall 2025)
- C2  wait list: policy, offered, accepted a place, admitted from it
- C7  how much each admission factor counts (Very Important / Important / Considered / Not Considered)
- C9  % submitting SAT / ACT; SAT composite, EBRW and Math and ACT composite 25th/50th/75th percentiles
- C11 % of first-year students in each high school GPA band (the "all enrolled students" column)
- C12 average high school GPA of those who submitted one, and the % who submitted one
- C21/C22 early decision offered (with applicants and admits), early action offered

Each value can have up to four readings:
- the PDF's fillable form fields (exact, when the college didn't flatten the PDF)
- the file's text, laid out as on the page (pdftotext -layout; spreadsheet and Word table cells keep their
  columns), so a label and its value sit on the same line
- collegedata.fyi's extraction of the same file (raw/cds/collegedata_values.json)
- IPEDS for the same fall, where IPEDS reports the same measure
A value is published when it comes from the form fields, or when two of the other readings agree. A high
school GPA value read from the text with its label on the same line, and with no other reading, is published
when it passes its own checks (average between 1 and 5, bands adding up to 100, an average its bands can
produce). Everything else goes to review with every reading. Values are never estimated, a blank stays blank,
and an applicant count of 0 (an unfilled total on the form) counts as blank; the total is then its lines by sex
added up, on the form or in the text. C1 counts must be within 15% of IPEDS for the same fall; for another fall,
within half to twice IPEDS's, with an admit rate within 25 points.

Each file is matched to its college first: a file whose first-year class is nowhere near IPEDS's for that
college (another campus's CDS, say) isn't used, and a file listed for several colleges is used only for the one
whose numbers it matches. collegedata.fyi's counts take part in that check only when nothing of ours does and
they hang together.

Writes data/admissions/cds_values.csv (published values, one row per college, with the CDS year and the source
URL on the college's site for citations), cds_provenance.csv (every reading of every value) and cds_review.csv
(files not used or not readable, and values whose readings disagree). Needs pypdf, openpyxl and pdftotext
(poppler-utils; without it PDFs are read with pypdf, which often separates labels from their values).
"""
import argparse
import csv
import html
import io
import json
import math
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from collections import defaultdict
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from urllib.parse import urlparse

sys.path.insert(0, os.path.dirname(__file__))
from common import OUT, RAW, write_csv  # noqa: E402

LEVELS = {"VI": "Very Important", "I": "Important", "C": "Considered", "NC": "Not Considered"}
# C7 factors: our column, the form field name, and the row label.
FACTORS = [("rigor", "Q111_1", r"Rigor\s+of\s+secondary\s+school\s+record"), ("class_rank", "Q111_2", r"Class\s+rank"),
           ("gpa", "Q111_3", r"Academic\s+GPA"), ("test_scores", "Q111_4", r"Standardized\s+test\s+scores"),
           ("essay", "Q111_5", r"Application\s+essay"), ("recommendations", "Q111_6", r"Recommendations?"),
           ("interview", "Q112_1", r"Interview"), ("extracurriculars", "Q112_2", r"Extracurricular\s+activities"),
           ("talent", "Q112_3", r"Talent\s*/\s*ability"),
           ("character", "Q112_4", r"Character\s*/\s*personal\s+qualities"),
           ("first_generation", "Q112_5", r"First\s+generation"), ("legacy", "Q112_6", r"Alumni\s*/\s*ae\s+relation"),
           ("geography", "Q112_7", r"Geographical\s+residence"), ("state_residency", "Q112_8", r"State\s+residency"),
           ("religion", "Q112_9", r"Religious\s+affiliation\s*/\s*commitment"),
           ("volunteer_work", "Q112_11", r"Volunteer\s+work"), ("work_experience", "Q112_12", r"Work\s+experience"),
           ("demonstrated_interest", "Q112_13", r"Level\s+of\s+applicant.s\s+interest")]
# C11 bands: column and the band's wording after "Percent who had GPA".
BANDS = [("gpa_4_0", r"4\.00?(?!\d)(?:\s+(?:and|or)\s+(?:higher|above))?"),
         ("gpa_375_399", r"3\.75\s*(?:and|to|-|–)\s*3\.99"), ("gpa_350_374", r"3\.50?\s*(?:and|to|-|–)\s*3\.74"),
         ("gpa_325_349", r"3\.25\s*(?:and|to|-|–)\s*3\.49"), ("gpa_300_324", r"3\.00?\s*(?:and|to|-|–)\s*3\.24"),
         ("gpa_250_299", r"2\.50?\s*(?:and|to|-|–)\s*2\.99"), ("gpa_200_249", r"2\.00?\s*(?:and|to|-|–)\s*2\.49"),
         ("gpa_100_199", r"1\.00?\s*(?:and|to|-|–)\s*1\.99"), ("gpa_below_100", r"below\s+1\.00?(?!\d)")]
BAND_COLS = [c for c, _ in BANDS]
# C9: column prefix, row label, form field prefix, lowest and highest possible score.
TESTS = [("sat_comp", r"SAT\s+Composite", "SAT1_COMP", 400, 1600),
         ("sat_erw", r"SAT\s+Evidence-Based(?:\s+Reading\s+and\s+Writing)?", "SAT1_VERB", 200, 800),
         ("sat_math", r"SAT\s+Math\b", "SAT1_MATH", 200, 800), ("act_comp", r"ACT\s+Composite", "ACT_COMP", 1, 36)]

# column -> (kind, PDF form field name, {template year or "*": CDS question number}). The form field names are the
# same in every template year (they're listed in collegedata.fyi's schemas, MIT license); the question numbers,
# which collegedata.fyi keys its values by, moved in 2025-26. Kinds: count, pct, gpa, score, yn, level.
FIELDS = {
    "applicants": ("count", "AP_RECD_1ST_N", {"2025-26": "C.116", "*": "C.117"}),
    "admits": ("count", "AP_ADMT_1ST_N", {"2025-26": "C.117", "*": "C.118"}),
    "enrolled": ("count", "EN_TOT_1ST_N", {"2025-26": "C.118", "*": "C.119"}),
    "waitlist_policy": ("yn", "AD_WAIT", {"*": "C.201"}),
    "waitlist_offered": ("count", "AP_RECD_WAIT_N", {"*": "C.202"}),
    "waitlist_accepted": ("count", "AP_ACPT_WAIT_N", {"*": "C.203"}),
    "waitlist_admitted": ("count", "AP_ADMT_WAIT_N", {"*": "C.204"}),
    **{f"factor_{f}": ("level", tag, {"*": f"C.7{i + 1:02d}"}) for i, (f, tag, _) in enumerate(FACTORS)},
    "sat_submit_pct": ("pct", "SUBMIT_SAT1_P", {"*": "C.901"}),
    "act_submit_pct": ("pct", "SUBMIT_ACT_P", {"*": "C.902"}),
    **{f"{name}_p{q}": ("score", f"{tag}_{q}TH_P", {"*": f"C.9{5 + 3 * t + i:02d}"})
       for t, (name, _, tag, _, _) in enumerate(TESTS) for i, q in enumerate((25, 50, 75))},
    **{col: ("pct", f"EN_FRSH_GPA_{i + 1}_P", {"*": f"C.11{21 + i}"}) for i, col in enumerate(BAND_COLS)},
    "gpa_avg": ("gpa", "FRSH_GPA", {"*": "C.1201"}), "gpa_submit_pct": ("pct", "FRSH_GPA_SUBMIT_P", {"*": "C.1202"}),
    "ed_offered": ("yn", "AD_EDEC", {"*": "C.2101"}),
    "ed_applicants": ("count", "AP_RECD_EDEC_N", {"2025-26": "C.2110", "*": "C.2106"}),
    "ed_admits": ("count", "AP_ADMT_EDEC_N", {"2025-26": "C.2111", "*": "C.2107"}),
    "ea_offered": ("yn", "AD_EACT", {"*": "C.2201"}),
}
# C1 totals on the form are calculated fields; when a college's PDF tool didn't run the calculation they read 0.
# The total is then the lines it adds up (by sex; enrollees by sex and full-/part-time), in the order tried.
SEXES = ("MEN", "WMN", "UNK", "NON_BINARY")
PARTS = {"applicants": [[f"AP_RECD_1ST_{s}_N" for s in SEXES]],
         "admits": [[f"AP_ADMT_1ST_{s}_N" for s in SEXES]],
         "enrolled": [[f"EN_TOT_1ST_{t}_{s}_N" for s in SEXES for t in ("FT", "PT")],
                      [f"EN_TOT_1ST_{s}_N" for s in SEXES]]}
POSITIVE = {"applicants", "admits", "enrolled", "ed_applicants", "ed_admits"}  # 0 here means "not filled in"
# Our columns that IPEDS ADM reports for the same fall (CDS 2024-25 = fall 2024 = ADM2024), under the same names.
IPEDS_SAME = {"applicants", "admits", "enrolled", "sat_submit_pct", "act_submit_pct",
              *(f"{t}_p{q}" for t in ("sat_erw", "sat_math", "act_comp") for q in (25, 50, 75))}
META = ["unitid", "name", "cds_year", "source_url"]
COLUMNS = META + list(FIELDS)


def question(col, year):
    m = FIELDS[col][2]
    return m.get(year, m["*"])


def num(v):
    if v is None:
        return None
    s = str(v).strip().replace(",", "").replace("$", "").rstrip("%").strip()
    try:
        return float(s)
    except ValueError:
        return None


def clean(kind, v):
    """A reading in our units, or None: percents 0-100, GPA, counts and scores as numbers, labels as words."""
    if v is None or str(v).strip() in ("", "N/A", "n/a", "NA", "-", "--"):
        return None
    s = str(v).strip()
    if kind == "level":
        code = s.lstrip("/").upper()
        if code in LEVELS:
            return LEVELS[code]
        low = s.lower()
        for label in ("Very Important", "Not Considered", "Important", "Considered"):
            if label.lower() in low:
                return label
        return None
    if kind == "yn":
        low = s.lstrip("/").lower()
        return "Yes" if low in ("y", "yes", "x", "on") else "No" if low in ("n", "no") else None
    n = num(s)
    if n is None:
        return None
    if kind == "pct" and 0 < n <= 1 and "." in s and "%" not in s:
        n *= 100  # Excel stores 25% as 0.25
    return round(n, 2) if kind in ("pct", "gpa") else int(round(n))


def scale_bands(raw):
    """{band column: percent} from {band column: value as written}. Spreadsheets store percents as fractions,
    which shows only in the whole set: the bands add up to 1 instead of 100."""
    vals = {c: num(v) for c, v in raw.items() if num(v) is not None}
    if vals and 0.97 <= sum(vals.values()) <= 1.03 and not any("%" in str(v) for v in raw.values()):
        vals = {c: v * 100 for c, v in vals.items()}
    return {c: round(v, 2) for c, v in vals.items()}


def agree(kind, a, b):
    if a is None or b is None:
        return False
    if kind in ("level", "yn"):
        return a == b
    tol = {"pct": 0.51, "gpa": 0.005, "count": 0, "score": 0}[kind]
    return abs(a - b) <= tol


# ---- text of the file, laid out as on the page ----------------------------------------------------------

W = 40  # characters per spreadsheet or table column


def grid(rows):
    """Table rows (lists of cells) as text lines, each cell starting at its column's position, so check marks in
    the C7 table line up under their headings as they do in a PDF's layout text."""
    lines = []
    for cells in rows:
        line = ""
        for i, c in enumerate(cells):
            c = " ".join(str(c).split()) if c is not None else ""
            if c:
                line = line.ljust(max(i * W, len(line) + 2 if line else 0)) + c
        if line.strip():
            lines.append(line)
    return "\n".join(lines)


def cell(c):
    """A spreadsheet cell as its reader sees it: percent-formatted cells as "43.9%" (Excel stores 0.439)."""
    v = getattr(c, "value", None)
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        return "" if v is None else str(v)
    if "%" in (getattr(c, "number_format", "") or ""):
        return f"{round(v * 100, 4):f}".rstrip("0").rstrip(".") + "%"
    return str(int(v)) if float(v).is_integer() else repr(round(v, 6))


def pdf_text(data):
    exe = shutil.which("pdftotext")
    if exe:
        with tempfile.NamedTemporaryFile(suffix=".pdf") as f:
            f.write(data)
            f.flush()
            r = subprocess.run([exe, "-layout", "-enc", "UTF-8", f.name, "-"], capture_output=True, timeout=180)
        if r.returncode == 0 and r.stdout.strip():
            return r.stdout.decode("utf-8", "ignore")
    from pypdf import PdfReader
    return "\n".join(page.extract_text() or "" for page in PdfReader(io.BytesIO(data)).pages)


def docx_text(data):
    x = zipfile.ZipFile(io.BytesIO(data)).read("word/document.xml").decode("utf-8", "ignore")

    def text(s):
        s = re.sub(r"</w:p>|<w:tab/>|<w:br/>", " ", s)
        return html.unescape(re.sub(r"<[^>]+>", "", s))
    rows = []
    for block in re.findall(r"<w:tbl[ >].*?</w:tbl>|<w:p[ >].*?</w:p>", x, re.S):
        if not block.startswith("<w:tbl"):
            rows.append([text(block)])
            continue
        for tr in re.findall(r"<w:tr[ >].*?</w:tr>", block, re.S):
            cells = []
            for tc in re.findall(r"<w:tc[ >].*?</w:tc>", tr, re.S):
                span = re.search(r'<w:gridSpan w:val="(\d+)"', tc)
                cells += [text(tc)] + [""] * (int(span.group(1)) - 1 if span else 0)
            rows.append(cells)
    return grid(rows)


QUESTION_NO = re.compile(r"[A-J]\.\d{3,5}")


def xlsx_text(data):
    """The workbook's sheets as layout text. The 2025-26 template keeps an index of every question (its number,
    wording and answer) in columns to the right of the form, on the same rows as unrelated form lines: the C11
    band rows carry the application closing month and day there. That index is cut off, from the first column
    holding question numbers like "C.1402"."""
    import openpyxl
    wb = openpyxl.load_workbook(io.BytesIO(data), read_only=True, data_only=True)
    sheets = []
    for ws in wb.worksheets:
        try:
            ws.reset_dimensions()  # some files declare a wrong sheet size, which would cut rows short
        except AttributeError:
            pass
        rows = [[cell(c) for c in row] for row in ws.iter_rows()]
        hits = defaultdict(int)
        for r in rows:
            for i, v in enumerate(r):
                if i >= 3 and QUESTION_NO.fullmatch(v.strip()):
                    hits[i] += 1
        cut = min((i for i, n in hits.items() if n >= 10), default=None)
        sheets.append(grid([r[:cut] for r in rows] if cut else rows))
    return "\n".join(sheets)


def html_text(data):
    t = re.sub(r"(?is)<(script|style).*?</\1>", " ", data.decode("utf-8", "ignore"))

    def plain(s):
        return html.unescape(re.sub(r"<[^>]+>", " ", re.sub(r"(?i)<br\s*/?>|</(p|div|li|h\d)>", "\n", s)))
    rows, pos = [], 0
    for m in re.finditer(r"(?is)<tr[ >].*?</tr>", t):
        rows += [[line] for line in plain(t[pos:m.start()]).split("\n")]
        cells = []
        for c in re.finditer(r"(?is)<t([dh])([^>]*)>(.*?)</t\1>", m.group()):
            span = re.search(r"colspan\s*=\s*\"?(\d+)", c.group(2), re.I)
            cells += [plain(c.group(3))] + [""] * (int(span.group(1)) - 1 if span else 0)
        rows.append(cells)
        pos = m.end()
    rows += [[line] for line in plain(t[pos:]).split("\n")]
    return grid(rows)


def text_of(data, fmt=""):
    if data[:4] == b"%PDF":
        t = pdf_text(data)
    elif data[:2] == b"PK" and b"word/document.xml" in data:
        t = docx_text(data)
    elif fmt == "xlsx" or data[:2] == b"PK":
        t = xlsx_text(data)
    else:
        t = html_text(data)
    return t.replace("\xa0", " ").replace("\f", "\n").replace("\r", "")


# ---- reading values from the text --------------------------------------------------------------------------

NUMBER = re.compile(r"(?<![\w.,/-])(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?(\s*%)?(?![\w/-])")
NOT_VALUES = re.compile(r"(?i)\b(?:fall|spring|summer|winter)\s+\d{4}\b|\b(?:19|20)\d{2}\s*[-–/]\s*(?:19|20)?\d{2}\b"
                        r"|\bpage\s+\d+(?:\s+of\s+\d+)?\b")
GPA = r"(?<![\d.,])([1-5]\.\d{1,3})(?![\d.,%])(?!\s*%)"
PCT = r"(\d{1,3}(?:\.\d+)?)(?![\d.,])\s*(%?)"
MARKS = "Xx✓✔✗✘☒☑■●⚫◼√"  # a box ticked by hand, or as a flattened check box draws it


def numbers(s):
    """The numbers in s as written ("12141", "43.9%"), skipping years, dates and question numbers like C12."""
    return [m.group(1).replace(",", "") + (m.group(2) or "") + ("%" if m.group(3) else "")
            for m in NUMBER.finditer(NOT_VALUES.sub(" ", s))]


def line_end(text, pos):
    end = text.find("\n", pos)
    return len(text) if end < 0 else end


def after(text, m):
    """Numbers after a label: on the rest of its line, else on the next line when that line holds only numbers."""
    end = line_end(text, m.end())
    got = numbers(text[m.end():end])
    if not got and end < len(text):
        nxt = text[end + 1:line_end(text, end + 1)]
        if re.fullmatch(r"[\s\d.,%$]*", nxt):
            got = numbers(nxt)
    return got


def first_after(text, label, pick=-1):
    """The number after the first occurrence of the label that has one (the last number on its line by default,
    the "Total" column)."""
    for m in re.finditer(label, text, re.I):
        got = after(text, m)
        if got:
            return got[pick]
    return None


# C1 totals: the "(degree-seeking)" row ends with its Total column. A "students who applied" row is often split
# by sex (Men, Women, Another gender), so it counts only when it holds a single number.
C1_DEGREE = r"Total\s+first-time,?\s+first-year\s+\((?:degree[-\s]+seeking|freshman)\)\s+(?:students\s+)?(?:who\s+)?"
C1_STUDENTS = r"Total\s+first-time,?\s+first-year\s+(?:degree-seeking\s+)?students\s+(?:who\s+)?"
C1 = {"applicants": r"applied\b", "admits": r"(?:were\s+)?admitted\b", "enrolled": r"enrolled\b"}
# The C1 lines by sex that the total adds up: men, women, another gender, unknown gender up to 2024-25; males,
# females, students of unknown sex from 2025-26. Enrollees come by sex, and by sex and full- or part-time.
C1_SEX = re.compile(r"Total\s+(?:(full|part)-time,?\s+)?first-time,?\s+first-year\s+(?:students\s+of\s+)?"
                    r"(men|women|males?|females?|another\s+gender|unknown\s+(?:gender|sex)|non-?binary)\s+"
                    r"(?:students\s+)?who\s+(applied|were\s+admitted|enrolled)\b", re.I)
C1_WHAT = {"applied": "applicants", "were admitted": "admits", "enrolled": "enrolled"}


def c1_by_sex(t):
    """{column: its C1 lines by sex added up}, for a total left blank (a calculated field that never ran, or a
    2025-26 Total column after four residency columns). Needs both a men's and a women's line."""
    seen = {}
    for m in C1_SEX.finditer(t):
        col = C1_WHAT[" ".join(m.group(3).lower().split())]
        sex = m.group(2).lower()[:3].replace("mal", "men").replace("fem", "wom")
        key = (col, (m.group(1) or "").lower(), sex)
        if key not in seen:  # the form comes before any repeat of it (a spreadsheet's answer sheet, say)
            got = after(t, m)
            seen[key] = clean("count", got[0]) if got else None
    out = {}
    for col in C1_WHAT.values():
        for load in (("",), ("full", "part")):
            lines = {k: v for k, v in seen.items() if k[0] == col and k[1] in load}
            sexes = {k[2] for k, v in lines.items() if v is not None}
            if {"men", "wom"} <= sexes:
                out[col] = sum(v for v in lines.values() if v)
                break
    return out
COUNTS = {"ed_applicants": r"Number\s+of\s+early\s+decision\s+applications\s+received(?:\s+by\s+your\s+institution)?",
          "ed_admits": r"Number\s+of\s+applicants\s+admitted\s+under\s+early\s+decision(?:\s+plan)?",
          "waitlist_offered": r"Number\s+of\s+qualified\s+applicants\s+offered\s+a\s+place\s+on\s+(?:the\s+)?wait",
          "waitlist_accepted": r"Number\s+accepting\s+a\s+place\s+on\s+the\s+wait",
          "waitlist_admitted": r"Number\s+of\s+wait-?\s*listed\s+students\s+admitted"}
YES_NO = {"ed_offered": r"offer\s+an\s+early\s+decision\s+plan", "ea_offered": r"nonbinding\s+early\s+action\s+plan",
          "waitlist_policy": r"policy\s+of\s+placing\s+students\s+on\s+a\s+wait"}


def yes_no(text, question_re):
    """Yes or No for a CDS yes/no question: the option with a mark in front of it, or the only one written."""
    m = re.search(question_re, text, re.I)
    if not m:
        return None
    q = text.find("?", m.end())
    start = q + 1 if 0 <= q - m.end() <= 400 else m.end()
    window = re.split(r"(?i)If\s+[“\"']?yes|closing\s+date|Number\s+of|Is\s+your|Do\s+you|Please\s+provide"
                      r"|\bC\d{1,2}\b", text[start:start + 200])[0]
    window = re.sub(r"(?i)\bYes\s+or\s+No\b", " ", window)
    marked = {w.title() for w in re.findall(r"(?<!\S)[" + MARKS + r"]\s*(Yes|No)\b", window, re.I)}
    if len(marked) == 1:
        return marked.pop()
    words = {w.title() for w in re.findall(r"\b(Yes|No)\b", window, re.I)}
    if len(words) == 1 and not re.search(r"[☐□○]", window):
        return words.pop()
    return None


def center(m):
    return (m.start() + m.end()) / 2


def factor_columns(lines, h):
    """Character positions of the four C7 headings around line h, or None."""
    vi = re.search(r"Very\s+Important", lines[h], re.I)
    imp = [m for m in re.finditer(r"Important", lines[h], re.I) if m.start() >= vi.end()]
    if not imp:
        return None
    cons, nots = [], []
    for k in range(max(0, h - 2), min(len(lines), h + 3)):
        for m in re.finditer(r"(Not\s+)?Considered", lines[k], re.I):
            (nots if m.group(1) else cons).append(center(m))
    if nots:
        nc = nots[0]
        c = next((x for x in sorted(cons) if abs(x - nc) > 4), None)
    elif len(cons) >= 2 and max(cons) - min(cons) > 4:
        c, nc = min(cons), max(cons)  # "Not" sits on the line above the second "Considered"
    else:
        return None
    cols = {"Very Important": center(vi), "Important": center(imp[0]), "Considered": c,
            "Not Considered": nc}
    order = list(cols.values())
    return cols if c is not None and order == sorted(order) else None


def factor_levels(text):
    """C7: the heading a row's mark sits under (layout text), or the level written in the row."""
    lines = text.split("\n")
    out = {}
    mark = re.compile(r"(?<!\S)[" + MARKS + r"](?!\S)")
    headings = re.compile(r"(?i)Very\s+Important|Not\s+Considered|Important|Considered|\bNot\b|\bC\.?\s?7\b\.?"
                          r"|\bAcademic\b|\bFactors?\b")
    for h, line in enumerate(lines):
        # the table's heading row holds the headings and nothing else (not a sentence that names them)
        if not re.search(r"Very\s+Important", line, re.I) or re.search(r"[A-Za-z]", headings.sub("", line)):
            continue
        cols = factor_columns(lines, h)
        if not cols:
            continue
        end = next((j for j in range(h + 1, min(len(lines), h + 90)) if re.match(r"\s*C8\b", lines[j])),
                   min(len(lines), h + 90))
        for key, _, label in FACTORS:
            for j in range(h + 1, end):
                m = re.search(r"^\s*" + label, lines[j], re.I)
                if not m:
                    continue
                found = [(x.start() + m.end(), x.group()) for x in mark.finditer(lines[j][m.end():])]
                if not found and j + 1 < end and not re.search(r"[A-Za-z]{2,}", lines[j + 1]):
                    found = [(x.start(), x.group()) for x in mark.finditer(lines[j + 1])]  # mark a line lower
                if len(found) == 1:
                    name, pos = min(cols.items(), key=lambda kv: abs(kv[1] - found[0][0]))
                    if abs(pos - found[0][0]) <= 12:
                        out[f"factor_{key}"] = name
                break
        if out:
            break
    for key, _, label in FACTORS:  # rows that name their level instead of ticking a column
        if f"factor_{key}" in out:
            continue
        m = re.search(r"^[ \t]*" + label + r"[ \t:]+(Very\s+Important|Not\s+Considered|Important|Considered)[ \t]*$",
                      text, re.I | re.M)
        if m:
            out[f"factor_{key}"] = clean("level", " ".join(m.group(1).split()))
    return out


def parse(text):
    """Readings from the file's text, matched against the CDS wording. Returns {} for fields not found.
    "_tight" lists the GPA fields whose value sat on its label's own line."""
    t = text.replace("\xa0", " ").replace("\f", "\n").replace("\r", "")
    flat = re.sub(r"[ \t]+", " ", t)
    out, tight, summed = {}, set(), set()
    sums = c1_by_sex(t)
    for col, label in C1.items():
        row = next((g for g in (after(t, m) for m in re.finditer(C1_DEGREE + label, t, re.I)) if g), [])
        v = clean("count", row[-1]) if row else None
        if not v:
            for m in re.finditer(C1_STUDENTS + label, t, re.I):
                got = after(t, m)
                if got:
                    v = clean("count", got[0]) if len(got) == 1 else None
                    break
        s = sums.get(col)
        if s and v != s:
            # The "(degree-seeking)" row of 2025-26 has residency columns (in-state, out-of-state, international,
            # unknown) before its Total; with the Total blank, its last number is only a part: smaller than the
            # lines by sex, or not the sum of the numbers before it.
            parts = [clean("count", x) or 0 for x in row]
            part = bool(row) and parts[-1] == v and (v < s or (len(parts) > 1 and parts[-1] != sum(parts[:-1])))
            if not v or part:
                v = s
                summed.add(col)
        if v:
            out[col] = v
    for col, label in COUNTS.items():
        v = clean("count", first_after(t, label, pick=0))
        if v is not None:
            out[col] = v
    for col, q in YES_NO.items():
        v = yes_no(flat, q)
        if v:
            out[col] = v

    # C12: the value on the label's line ("…who submitted GPA: 3.89", "Average High School GPA 3.89") is tight;
    # otherwise the first GPA-like number before the next question part needs a second reading.
    for m in re.finditer(r"(?:submitted\s+GPA|Average\s+High\s+School\s+GPA)[ \t:]*" + GPA, t, re.I):
        line = t[t.rfind("\n", 0, m.start()) + 1:m.start()]
        if not re.match(r"\s*Percent", line, re.I):
            out["gpa_avg"] = float(m.group(1))
            tight.add("gpa_avg")
            break
    m = re.search(r"Average\s+high\s+school\s+GPA\s+of\s+all", t, re.I)
    if m and "gpa_avg" not in out:
        window = re.split(r"(?i)Percent|\bC13\b|Admission\s+Policies", t[m.end():m.end() + 800])[0]
        g = re.search(GPA, window)
        if g:
            out["gpa_avg"] = float(g.group(1))
    m = re.search(r"(?:submitted\s+high\s+school\s+GPA|Percent\s+Submitting\s+GPA|Percent\s+of\s+total[^\n]{0,160}?"
                  r"submitted\s+GPA)[ \t:]*" + PCT, t, re.I)
    if m and float(m.group(1)) <= 100:
        out["gpa_submit_pct"] = clean("pct", m.group(1) + m.group(2))
        tight.add("gpa_submit_pct")
    else:
        m = re.search(r"Percent\s+of\s+total\s+first-time[^%]{0,200}?submitted\s+high\s+school\s+GPA", t, re.I)
        if m:
            window = re.split(r"(?i)\bC13\b|Admission\s+Policies", t[m.end():m.end() + 600])[0]
            got = [x for x in numbers(window) if x.endswith("%") and (num(x) or 0) <= 100]
            if got:
                out["gpa_submit_pct"] = clean("pct", got[0])

    # C11: the last number on each band's row is the "all enrolled students" column.
    c11 = re.search(r"\bC11\b(.*?)\bC12\b", t, re.S)
    block = c11.group(1) if c11 and re.search(r"Percent\s+who\s+had", c11.group(1), re.I) else t
    raw = {}
    for col, band in BANDS:
        m = re.search(r"Percent\s+who\s+had\s+(?:a\s+)?GPA\s+(?:of\s+)?(?:between\s+)?" + band, block, re.I)
        if m:
            got = after(block, m)
            if got:
                raw[col] = got[-1]
    bands = scale_bands(raw)
    out.update(bands)
    if len(bands) >= 5 and 97 <= sum(bands.values()) <= 103:
        tight.update(bands)

    # C9: percentiles in the order 25th, 50th, 75th; % submitting is the first number on its row.
    c9 = re.search(r"\bC9\b(.*?)\bC10\b", flat, re.S)
    block = c9.group(1) if c9 and re.search(r"Composite", c9.group(1), re.I) else flat
    for name, label, _, lo, hi in TESTS:
        for m in re.finditer(r"(?m)^[ \t]*" + label, block, re.I):
            got = [num(x) for x in after(block, m) if "%" not in x]
            if len(got) == 3 and got == sorted(got) and all(lo <= v <= hi for v in got):
                for q, v in zip((25, 50, 75), got):
                    out[f"{name}_p{q}"] = int(v)
                break
    for col, label in (("sat_submit_pct", r"Submitting\s+SAT\s+Scores"),
                       ("act_submit_pct", r"Submitting\s+ACT\s+Scores")):
        m = re.search(label, block, re.I)
        got = after(block, m) if m else []
        if got and (num(got[0]) or 0) <= 100:
            out[col] = clean("pct", got[0])

    out.update(factor_levels(t))
    out["_tight"], out["_summed"] = tight, summed
    return out


# ---- the form fields ---------------------------------------------------------------------------------------

def form_fields(data):
    """{AcroForm field name: value} from a fillable CDS PDF; empty when the college flattened it."""
    if data[:4] != b"%PDF":
        return {}
    from pypdf import PdfReader
    try:
        fields = PdfReader(io.BytesIO(data)).get_fields() or {}
    except Exception:
        return {}
    return {k: str(f.get("/V")).strip() for k, f in fields.items() if f.get("/V") not in (None, "", "/Off")}


def from_form(fields):
    """Our columns from the form fields. "_summed" lists C1 totals added up from their lines by sex."""
    out = {}
    for col, (kind, tag, _) in FIELDS.items():
        if col not in BAND_COLS and tag in fields:
            v = clean(kind, fields[tag])
            if v is not None:
                out[col] = v
    out.update(scale_bands({c: fields[FIELDS[c][1]] for c in BAND_COLS if FIELDS[c][1] in fields}))
    out["_summed"] = set()
    for col, options in PARTS.items():
        if out.get(col):
            continue
        out.pop(col, None)  # a 0 total is an uncalculated field
        for parts in options:
            total = sum(clean("count", fields.get(p)) or 0 for p in parts)
            if total:
                out[col] = total
                out["_summed"].add(col)
                break
    return out


# ---- deciding what to publish ------------------------------------------------------------------------------

def their_values(theirs, year):
    """collegedata.fyi's values for one file, in our columns and units."""
    out = {}
    for col, (kind, _, _) in FIELDS.items():
        if col not in BAND_COLS:
            v = clean(kind, theirs.get(question(col, year)))
            if v is not None:
                out[col] = v
    out.update(scale_bands({c: theirs[question(c, year)] for c in BAND_COLS
                            if theirs.get(question(c, year)) not in (None, "")}))
    return out


def check(vals):
    problems = []
    if vals.get("gpa_avg") is not None and not 1 <= vals["gpa_avg"] <= 5:
        problems.append(("gpa_avg", f"average GPA {vals['gpa_avg']} outside 1-5"))
    for col, (kind, _, _) in FIELDS.items():
        v = vals.get(col)
        if v is None:
            continue
        if kind == "pct" and not 0 <= v <= 100:
            problems.append((col, f"{v} outside 0-100"))
        if col.startswith("sat_") and kind == "score" and not 200 <= v <= 1600:
            problems.append((col, f"SAT {v} outside 200-1600"))
        if col.startswith("act_") and kind == "score" and not 1 <= v <= 36:
            problems.append((col, f"ACT {v} outside 1-36"))
    bands = [vals[c] for c in BAND_COLS if vals.get(c) is not None]
    if bands and not 97 <= sum(bands) <= 103:
        problems.append(("gpa_bands", f"GPA bands add up to {round(sum(bands), 1)}"))
    a, b, e = vals.get("applicants"), vals.get("admits"), vals.get("enrolled")
    if a is not None and b is not None and b > a:
        problems.append(("admits", "more admits than applicants"))
    if b is not None and e is not None and e > b:
        problems.append(("enrolled", "more enrollees than admits"))
    return problems


GPA_MID = dict(zip(BAND_COLS, (4.0, 3.87, 3.62, 3.37, 3.12, 2.75, 2.25, 1.5, 0.5)))  # each band's middle


def gpa_conflict(values, methods):
    """An average GPA its own bands can't produce: the bands put the class well above the average (bands read from
    a weighted column beside an unweighted average, or another column altogether). A reading that stood alone goes
    to review; when both were confirmed by a second reading, that is what the file says and both stay. (Bands
    below a weighted average are normal: the top band is "4.0 and above".)"""
    g = values.get("gpa_avg")
    bands = {c: values[c] for c in BAND_COLS if values.get(c) is not None}
    total = sum(bands.values())
    if g is None or len(bands) < 5 or not total:
        return []
    implied = sum(GPA_MID[c] * p for c, p in bands.items()) / total
    if implied - g <= 0.2:
        return []
    why = f"the GPA bands put the class near {implied:.2f}, well above its {g} average"
    alone = "file text, checked"
    return ([("gpa_avg", why)] if methods.get("gpa_avg") == alone else []) + \
        ([("gpa_bands", why)] if any(methods.get(c) == alone for c in bands) else [])


def c1_jump(values, ipeds):
    """C1 counts for another fall than IPEDS's that look like a different measure: a count over twice or under
    half IPEDS's, or an admit rate 25 points away. They go to review; IPEDS still gives its own fall's counts."""
    a, b, e = (values.get(c) for c in ("applicants", "admits", "enrolled"))
    ia, ib, ie = (clean("count", ipeds.get(c)) for c in ("applicants", "admits", "enrolled"))
    why = next((f"{v:,} {what} in the CDS but {i:,} in IPEDS for another fall"
                for v, i, what in ((a, ia, "applicants"), (b, ib, "admits"), (e, ie, "enrollees"))
                if v and i and not 0.5 <= v / i <= 2), None)
    if not why and a and b and ia and ib and abs(b / a - ib / ia) > 0.25:
        why = f"admit rate {b / a:.0%} in the CDS but {ib / ia:.0%} in IPEDS for another fall"
    return [(c, why) for c in ("applicants", "admits", "enrolled") if why and values.get(c) is not None]


def decide(src, ipeds, mine_form, mine_text, theirs, producer=""):
    """(published values, provenance rows, review rows) for one college. producer only labels collegedata.fyi's
    reading: an extraction from the college's own cells (Excel, form fields) is still a single reading."""
    year = src["cds_year"]
    same_fall = ipeds if ipeds and ipeds.get("admissions_year") == year[:4] else {}
    cdf = their_values(theirs, year)
    tight, summed = mine_text.get("_tight", set()), mine_form.get("_summed", set())
    text_summed = mine_text.get("_summed", set())
    values, methods, prov, review = {}, {}, [], []

    def flag(col, why):
        review.append({"unitid": src["unitid"], "name": src["name"], "cds_year": year,
                       "file_url": src["source_url"], "field": col, "problem": why})
    for col, (kind, _, _) in FIELDS.items():
        f, t, c = mine_form.get(col), mine_text.get(col), cdf.get(col)
        i = clean(kind, same_fall.get(col)) if col in IPEDS_SAME else None
        if col in POSITIVE:
            f, t, c, i = (None if x == 0 else x for x in (f, t, c, i))
        if f is None and t is None and c is None:
            continue
        by_sex = " (lines by sex added up)"
        if f is not None:
            v, how = f, "form fields" + (by_sex if col in summed else "")
        elif agree(kind, t, c):
            v, how = t, "file text + collegedata.fyi" + (by_sex if col in text_summed else "")
        elif agree(kind, t, i):
            v, how = t, "file text + IPEDS same fall" + (by_sex if col in text_summed else "")
        elif agree(kind, c, i):
            v, how = c, "collegedata.fyi + IPEDS same fall"
        elif t is not None and c is None and col in tight:
            v, how = t, "file text, checked"
        else:
            v, how = None, ""
        prov.append({"unitid": src["unitid"], "field": col, "question": question(col, year), "value": v,
                     "verified": "yes" if v is not None else "no", "method": how,
                     "form": f, "text": t, "collegedata": c, "ipeds": i})
        if v is not None:
            values[col], methods[col] = v, how
        else:
            flag(col, f"readings disagree or unconfirmed: file text {t}, collegedata.fyi {c} ({producer or 'n/a'}), "
                      f"IPEDS same fall {i}")
    problems = check(values) + gpa_conflict(values, methods)
    for col in ("applicants", "admits", "enrolled"):  # the CDS and IPEDS describe the same students
        v, i = values.get(col), clean("count", same_fall.get(col))
        if v and i and abs(v - i) > 0.15 * i:
            problems.append((col, f"{v:,} in the CDS but {i:,} in IPEDS for the same fall"))
    if ipeds and not same_fall:
        problems += c1_jump(values, ipeds)
    for col, why in problems:
        cols = BAND_COLS if col == "gpa_bands" else [col]
        for c in cols:
            values.pop(c, None)
        for p in prov:
            if p["field"] in cols:
                p["verified"], p["method"], p["value"] = "no", f"failed check: {why}", None
        flag(col, why)
    return values, prov, review


# ---- which college a file belongs to ------------------------------------------------------------------------

def file_key(src):
    """The same file under several colleges has the same archived copy (named by its SHA-256) or link."""
    archive = src.get("archive_url") or ""
    return archive.rsplit("/", 1)[-1].split(".")[0] if archive else src.get("source_url")


def first_years(src, form, text, theirs):
    """The file's first-year class as [(enrolled, applicants)] readings: ours (form fields, text), else
    collegedata.fyi's when its three C1 counts hang together. Its row-shifted reads of some flattened PDFs (MIT's
    admits as its applicants, Holy Cross with more enrollees than applicants) would otherwise make a college's own
    file look like another campus's."""
    ours = [(r.get("enrolled"), r.get("applicants")) for r in (form, text) if r.get("enrolled") or r.get("applicants")]
    if ours:
        return ours
    a, b, e = (clean("count", theirs.get(question(c, src["cds_year"]))) for c in ("applicants", "admits", "enrolled"))
    return [(e, a)] if a and b and e and a >= b >= e and a >= 100 else []


def fit(src, form, text, theirs, ipeds):
    """How far the file's first-year class is from IPEDS's for this college, from the closest reading: |log ratio|
    of enrollees (steady from year to year), else of applicants scaled so that 3 times as many counts like twice as
    many enrollees (applications swing more). 0 = equal; None when there's nothing to compare."""
    ipeds = ipeds or {}
    have_e, have_a = num(ipeds.get("enrolled")), num(ipeds.get("applicants"))
    far = []
    for e, a in first_years(src, form, text, theirs):
        if e and have_e:
            far.append(abs(math.log(e / have_e)))
        elif a and have_a:
            far.append(abs(math.log(a / have_a)) * math.log(2) / math.log(3))
    return min(far) if far else None


def described(src, form, text, theirs, ipeds):
    """The file's first-year counts next to IPEDS's, for the review list."""
    e, a = (first_years(src, form, text, theirs) or [(None, None)])[0]
    ipeds = ipeds or {}

    def n(v):
        v = num(v)
        return f"{int(v):,}" if v else "?"
    return (f"file: {n(e)} enrolled, {n(a)} applicants; IPEDS: {n(ipeds.get('enrolled'))} enrolled, "
            f"{n(ipeds.get('applicants'))} applicants")


def assign(readings, ipeds):
    """{unitid: reason} for the files not to use: another college's numbers, or one file under several colleges."""
    skip, groups = {}, defaultdict(list)
    for u, r in readings.items():
        groups[file_key(r[0])].append(u)
    for us in groups.values():
        fits = {u: fit(*readings[u][:4], ipeds.get(u)) for u in us}
        if len(us) == 1:
            u = us[0]
            if fits[u] is not None and fits[u] > math.log(2):
                skip[u] = (f"this file's first-year class is far from IPEDS's for this college "
                           f"({described(*readings[u][:4], ipeds.get(u))}); it probably describes another campus")
            continue
        close = [u for u in us if fits[u] is not None and fits[u] <= math.log(1.5)]
        best = min(close, key=fits.get) if close else None
        names = ", ".join(readings[u][0]["name"] for u in us)
        for u in us:
            if u != best:
                skip[u] = (f"the same file is listed for {names}; its numbers match "
                           + (readings[best][0]["name"] if best else "none of them"))
    return skip


# ---- running it ----------------------------------------------------------------------------------------------

def fetch(url):
    from fetch import get  # same browser headers and retries as the federal downloads
    return get(url, tries=2, timeout=60)


def read_one(src):
    """Downloads the college's file (else the archived copy) and reads it; a file that can't be read (an HTML
    page instead of the workbook, say) falls through to the next copy."""
    err = "no file"
    for url in (src.get("source_url"), src.get("archive_url")):
        if not url:
            continue
        try:
            data = fetch(url)
        except Exception as e:
            err = f"{url}: {e}"[:200]
            continue
        if not data or len(data) <= 500:
            err = f"{url}: empty file"
            continue
        try:
            return src, form_fields(data), parse(text_of(data, src.get("format", ""))), ""
        except Exception as e:
            err = f"could not read file: {e}"[:200]
    return src, None, None, err


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", default=str(OUT / "cds_sources.csv"))
    ap.add_argument("--institutions", default=str(OUT / "institutions.csv"))
    ap.add_argument("--collegedata", default=str(RAW / "cds" / "collegedata_values.json"))
    ap.add_argument("--out", default=str(OUT))
    ap.add_argument("--workers", type=int, default=8)
    a = ap.parse_args(argv)
    if not shutil.which("pdftotext"):
        print("warning: pdftotext (poppler-utils) not found; flattened PDFs will be read with pypdf, less reliably")
    sources = list(csv.DictReader(open(a.sources, encoding="utf-8")))
    ipeds = {r["unitid"]: r for r in csv.DictReader(open(a.institutions, encoding="utf-8"))} \
        if os.path.exists(a.institutions) else {}
    theirs = json.loads(Path(a.collegedata).read_text()) if os.path.exists(a.collegedata) else {}
    review = []

    def note(src, why):
        review.append({"unitid": src["unitid"], "name": src["name"], "cds_year": src["cds_year"],
                       "file_url": src["source_url"], "field": "", "problem": why})
    usable = []
    for src in sources:
        if urlparse(src["source_url"]).netloc.lower().endswith("commondataset.org"):
            note(src, "not this college's CDS: a commondataset.org document")
        else:
            usable.append(src)
    readings = {}
    with ThreadPoolExecutor(a.workers) as pool:
        for src, form, text, err in pool.map(read_one, usable):
            if err:
                note(src, err)
                form, text = {}, {}
            info = theirs.get(src["unitid"]) or {}
            readings[src["unitid"]] = (src, from_form(form), text, info.get("values") or {}, info.get("producer") or "")
    skip = assign(readings, ipeds)
    rows, prov = [], []
    for u, (src, form, text, their, producer) in readings.items():
        if u in skip:
            note(src, skip[u])
            continue
        values, p, r = decide(src, ipeds.get(u), form, text, their, producer)
        prov += p
        review += r
        if values:
            rows.append({**{k: src[k] for k in META}, **values})
    out = Path(a.out)
    rows.sort(key=lambda r: r["name"])
    write_csv(out / "cds_values.csv", rows, COLUMNS)
    write_csv(out / "cds_provenance.csv", prov,
              ["unitid", "field", "question", "value", "verified", "method", "form", "text", "collegedata", "ipeds"])
    write_csv(out / "cds_review.csv", review, ["unitid", "name", "cds_year", "file_url", "field", "problem"])
    have = defaultdict(int)
    for r in rows:
        for col in r:
            have[col] += 1
    print(f"{len(sources):,} CDS files: {sum(1 for r in review if not r['field']):,} not used or unreadable; "
          f"{len(rows):,} colleges with published values; {sum(1 for r in review if r['field']):,} values to review")
    print("colleges per field: " + ", ".join(f"{c} {have[c]}" for c in FIELDS if have[c]))


if __name__ == "__main__":
    main()
