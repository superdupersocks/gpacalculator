#!/usr/bin/env python3
"""How first-year students' high school GPAs were spread (CDS C11), for the college pages that show a cited GPA.

    python3 scripts/admissions/phase3_gpa_bands.py

Admissions Phase 3 (template). A page that publishes its college's average GPA (phase2_b2_gpa.csv, CDS C12) can also
show the share of first-year students in each GPA range, from the same Common Data Set. A college's ranges are used
when:

- its B2 GPA came from this file: same IPEDS unitid and CDS year in data/admissions/cds_values.csv, and the same
  average;
- every range value the file gives passed the Phase 1 checks (cds_provenance.csv: verified). A range the file leaves
  blank stays blank, never 0;
- at least three ranges are given and they add up to 99-101% (the shares are of the students who submitted a GPA,
  rounded by the college).

Writes data/admissions/audit/phase3_gpa_bands.csv (the rows an import would write as cds_gpa_band_* fields, citing
the page's existing GPA source) and phase3_gpa_bands_pending.csv (the rest, with the reason). Changes nothing on the
site.
"""
import csv
from pathlib import Path

DATA = Path(__file__).resolve().parents[2] / "data" / "admissions"
AUDIT = DATA / "audit"
# CDS C11 field -> post field, highest range first
BANDS = [("gpa_4_0", "400"), ("gpa_375_399", "375"), ("gpa_350_374", "350"), ("gpa_325_349", "325"),
         ("gpa_300_324", "300"), ("gpa_250_299", "250"), ("gpa_200_249", "200"), ("gpa_100_199", "100"),
         ("gpa_below_100", "000")]
COLS = ["slug", "unitid", "college", "cds_gpa", "cds_gpa_year"] + [f"cds_gpa_band_{k}" for _, k in BANDS] + ["sum"]
PENDING_COLS = ["slug", "unitid", "college", "cds_gpa_year", "why"]


def read(path):
    with open(path, newline="") as f:
        return list(csv.DictReader(f))


def main():
    cds = {r["unitid"]: r for r in read(DATA / "cds_values.csv")}
    verified = {(r["unitid"], r["field"]) for r in read(DATA / "cds_provenance.csv") if r["verified"] == "yes"}
    rows, pending = [], []
    for b2 in read(AUDIT / "phase2_b2_gpa.csv"):
        u, base = b2["unitid"], [b2["slug"], b2["unitid"], b2["college"], b2["cds_gpa_year"]]
        c = cds.get(u)
        if not c or c["cds_year"] != b2["cds_gpa_year"] or c["gpa_avg"].strip() == "" \
                or float(c["gpa_avg"]) != float(b2["cds_gpa"]):
            pending.append(base + ["the GPA isn't from the Common Data Set we have the ranges of"])
            continue
        given = {f: c[f].strip() for f, _ in BANDS if c[f].strip() != ""}
        if len(given) < 3:
            pending.append(base + [f"{len(given)} GPA ranges given (needs 3 or more)"])
            continue
        unchecked = [f for f in given if (u, f) not in verified]
        if unchecked:
            pending.append(base + ["ranges not verified in Phase 1: " + ", ".join(unchecked)])
            continue
        total = round(sum(float(v) for v in given.values()), 2)
        if not 99 <= total <= 101:
            pending.append(base + [f"ranges add up to {total}%"])
            continue
        rows.append(base[:3] + [b2["cds_gpa"], b2["cds_gpa_year"]] + [given.get(f, "") for f, _ in BANDS] + [total])

    for name, cols, data in (("phase3_gpa_bands.csv", COLS, rows), ("phase3_gpa_bands_pending.csv", PENDING_COLS, pending)):
        with open(AUDIT / name, "w", newline="") as f:
            w = csv.writer(f)
            w.writerow(cols)
            w.writerows(sorted(data))
    print(f"GPA ranges for {len(rows)} of {len(rows) + len(pending)} pages with a cited GPA; pending: {len(pending)}")


if __name__ == "__main__":
    main()
