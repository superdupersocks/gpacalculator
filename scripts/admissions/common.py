"""Shared paths and helpers for the admissions data scripts (fetch.py, build.py, match.py)."""
import csv
import json
import re
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent.parent
RAW = ROOT / "data" / "admissions" / "raw"  # downloads, not committed (see .gitignore)
OUT = ROOT / "data" / "admissions"
COLLEGES = ROOT / "data" / "colleges"

# Scorecard and IPEDS spellings of "no value".
MISSING = {"", ".", "NULL", "PrivacySuppressed", "NA", "N/A"}

# IPEDS imputation flags (the X<var> column next to a value). Values the institution did not report itself
# are dropped: H imputed, K ratio adjustment, L group median, N nearest neighbor, P prior year.
IMPUTED_FLAGS = {"H", "K", "L", "N", "P"}

STATES = {
    "Alabama": "AL", "Alaska": "AK", "Arizona": "AZ", "Arkansas": "AR", "California": "CA", "Colorado": "CO",
    "Connecticut": "CT", "Delaware": "DE", "District of Columbia": "DC", "Florida": "FL", "Georgia": "GA",
    "Hawaii": "HI", "Idaho": "ID", "Illinois": "IL", "Indiana": "IN", "Iowa": "IA", "Kansas": "KS",
    "Kentucky": "KY", "Louisiana": "LA", "Maine": "ME", "Maryland": "MD", "Massachusetts": "MA", "Michigan": "MI",
    "Minnesota": "MN", "Mississippi": "MS", "Missouri": "MO", "Montana": "MT", "Nebraska": "NE", "Nevada": "NV",
    "New Hampshire": "NH", "New Jersey": "NJ", "New Mexico": "NM", "New York": "NY", "North Carolina": "NC",
    "North Dakota": "ND", "Ohio": "OH", "Oklahoma": "OK", "Oregon": "OR", "Pennsylvania": "PA",
    "Rhode Island": "RI", "South Carolina": "SC", "South Dakota": "SD", "Tennessee": "TN", "Texas": "TX",
    "Utah": "UT", "Vermont": "VT", "Virginia": "VA", "Washington": "WA", "West Virginia": "WV",
    "Wisconsin": "WI", "Wyoming": "WY", "Puerto Rico": "PR", "Guam": "GU", "Virgin Islands": "VI",
    "U.S. Virgin Islands": "VI", "American Samoa": "AS", "Northern Mariana Islands": "MP",
    "Federated States of Micronesia": "FM", "Marshall Islands": "MH", "Palau": "PW",
}


def read_csv(path):
    """Rows of a CSV as dicts with upper-case keys. IPEDS files are Latin-1 and may start with a BOM."""
    data = Path(path).read_bytes()
    try:
        text = data.decode("utf-8-sig")
    except UnicodeDecodeError:
        text = data.decode("latin-1")
    rows = list(csv.DictReader(text.splitlines()))
    return [{(k or "").strip().upper(): (v or "").strip() for k, v in r.items()} for r in rows]


def write_csv(path, rows, columns):
    path.parent.mkdir(parents=True, exist_ok=True)
    with open(path, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=columns, extrasaction="ignore", lineterminator="\n")
        w.writeheader()
        for r in rows:
            w.writerow({k: "" if r.get(k) is None else r[k] for k in columns})


def write_json(path, data):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n")


def norm_name(s):
    """Comparable form of an institution name: ASCII, lower case, no punctuation, common abbreviations spelled out."""
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    s = s.replace("&", " and ")
    s = re.sub(r"[^a-z0-9]+", " ", s)
    words = {"st": "saint", "ste": "sainte", "univ": "university", "coll": "college", "inst": "institute",
             "tech": "technical", "comm": "community", "ctr": "center", "mt": "mount"}
    out = [words.get(w, w) for w in s.split() if w not in ("the", "of", "at", "in", "and")]
    return " ".join(out)
