#!/usr/bin/env python3
"""Every name each college has carried in the IPEDS directory since 2002, for the Phase 3 names check.

    python3 scripts/admissions/ipeds_names.py

Runs on GitHub (admissions-names.yml), since the cloud sessions can't reach ed.gov. Reads the directory files
HD2002-HD2023 (audit.load_history) and writes data/admissions/review/ipeds_names.csv: for each UNITID in
data/admissions/institutions.csv, one row per stretch of years under the same name (unitid, first_year, last_year,
name). The 2024 name is institutions.csv's. scripts/admissions/phase3_names.py reads both to tell a page titled by
a college's former name from one titled by another form of its current name. Changes nothing on the site.
"""
import csv
import os
import sys
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
import audit  # noqa: E402

DATA = Path(__file__).resolve().parents[2] / "data" / "admissions"
OUT = DATA / "review" / "ipeds_names.csv"
COLUMNS = ["unitid", "first_year", "last_year", "name"]


def main():
    with open(DATA / "institutions.csv", newline="", encoding="utf-8") as f:
        ids = {r["unitid"] for r in csv.DictReader(f)}
    hist = audit.load_history(2002, 2023)
    rows = []
    for uid in sorted(ids & set(hist), key=int):
        span = None
        for year in sorted(hist[uid]):
            name = " ".join(hist[uid][year]["INSTNM"].split())
            if span and span["name"] == name:
                span["last_year"] = year
            else:
                span = {"unitid": uid, "first_year": year, "last_year": year, "name": name}
                rows.append(span)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with open(OUT, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS, lineterminator="\n")
        w.writeheader()
        w.writerows(rows)
    print(f"{len(rows):,} names for {len(ids & set(hist)):,} of {len(ids):,} colleges")


if __name__ == "__main__":
    main()
