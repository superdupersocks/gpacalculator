"""Keep the College Scorecard columns we use, from a copy downloaded by hand (its host refuses GitHub's runners).

    python3 scripts/admissions/slim_scorecard.py Most-Recent-Cohorts-Institution_MMDDYYYY.zip
    python3 scripts/admissions/slim_scorecard.py Most-Recent-Cohorts-Field-of-Study_MMDDYYYY.zip

The field-of-study file becomes data/admissions/scorecard/programs.csv: bachelor's programs only, with the
number of graduates, median earnings 1 and 4 years out, median debt, and the national median for the same
field. Suppressed values ("PS", "NA") stay empty.

Writes data/admissions/scorecard/institutions.csv (a few MB instead of several hundred) and source.json
(file name, size, SHA-256, date). fetch.py uses this copy whenever its own Scorecard download fails.
"""
import csv
import datetime
import hashlib
import io
import json
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEST = ROOT / "data" / "admissions" / "scorecard"
KEEP = (["UNITID", "OPEID", "INSTNM", "CITY", "STABBR", "ZIP", "INSTURL", "LATITUDE", "LONGITUDE", "MAIN",
         "CURROPER", "PREDDEG", "HIGHDEG", "ACCREDAGENCY", "OPENADMP", "ADM_RATE", "SAT_AVG", "UGDS",
         "TUITIONFEE_IN", "TUITIONFEE_OUT", "NPT4_PUB", "NPT4_PRIV", "C150_4", "C150_L4", "RET_FT4", "RET_FTL4",
         "MD_EARN_WNE_P10", "GRAD_DEBT_MDN", "PCTPELL", "PCTFLOAN"]
        + [f"{p}{q}" for p in ("SATVR", "SATMT", "ACTCM", "ACTEN", "ACTMT") for q in (25, 50, 75)])


PROGRAMS = {"UNITID": "unitid", "CIPCODE": "cip", "CIPDESC": "program", "IPEDSCOUNT1": "graduates_prev",
            "IPEDSCOUNT2": "graduates", "EARN_MDN_1YR": "earnings_1yr", "EARN_MDN_4YR": "earnings_4yr",
            "DEBT_ALL_STGP_EVAL_MDN": "median_debt", "EARN_MDN_4YR_NAT": "earnings_4yr_national"}


def programs(rows, path, raw, member):
    out = []
    for r in rows:
        if r.get("CREDLEV") != "3":
            continue
        row = {new: ("" if (r.get(old) or "").strip() in ("PS", "NA", "PrivacySuppressed", "NULL") else r[old].strip())
               for old, new in PROGRAMS.items()}
        row["program"] = row["program"].rstrip(".")
        out.append(row)
    with open(DEST / "programs.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=list(PROGRAMS.values()))
        w.writeheader()
        w.writerows(out)
    (DEST / "programs_source.json").write_text(json.dumps({
        "file": Path(path).name, "member": member, "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest(),
        "page": "https://collegescorecard.ed.gov/data/", "columns": PROGRAMS,
        "slimmed_at": datetime.date.today().isoformat()}, indent=1))
    print(f"{len(out):,} bachelor's programs -> {DEST / 'programs.csv'}")


def main(path):
    raw = Path(path).read_bytes()
    if raw[:2] == b"PK":
        with zipfile.ZipFile(io.BytesIO(raw)) as zf:
            names = [n for n in zf.namelist() if n.lower().endswith(".csv")
                     and ("institution" in n.lower() or "field-of-study" in n.lower())]
            names = names or [n for n in zf.namelist() if n.lower().endswith(".csv")]
            member, text = names[0], zf.read(names[0]).decode("utf-8-sig", "ignore")
    else:
        member, text = Path(path).name, raw.decode("utf-8-sig", "ignore")
    rows = list(csv.DictReader(io.StringIO(text)))
    DEST.mkdir(parents=True, exist_ok=True)
    if "CIPCODE" in rows[0]:
        return programs(rows, path, raw, member)
    have = [c for c in KEEP if c in rows[0]]
    missing = [c for c in KEEP if c not in rows[0]]
    DEST.mkdir(parents=True, exist_ok=True)
    with open(DEST / "institutions.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=have, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)
    (DEST / "source.json").write_text(json.dumps({
        "file": Path(path).name, "member": member, "bytes": len(raw), "sha256": hashlib.sha256(raw).hexdigest(),
        "page": "https://collegescorecard.ed.gov/data/", "kept_columns": have, "missing_columns": missing,
        "slimmed_at": datetime.date.today().isoformat()}, indent=1))
    print(f"{len(rows):,} institutions, {len(have)} columns -> {DEST / 'institutions.csv'}; missing: {missing}")


if __name__ == "__main__":
    main(sys.argv[1])
