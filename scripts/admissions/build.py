"""Clean and join the downloaded IPEDS and College Scorecard files into one row per college.

    python3 scripts/admissions/build.py [--raw DIR] [--out DIR]

Reads data/admissions/raw/ (from fetch.py) and writes to data/admissions/:
- institutions.csv: one row per UNITID, plain values (rates 0-1, whole dollars, decoded labels), empty when unknown
- field_sources.json: source file, variable, data year and meaning of every column, for citations
- qa_report.md: coverage per column and every value dropped by the checks

Rules: suppressed or missing values stay empty, never 0. IPEDS values the college did not report itself
(imputed) are dropped. Out-of-range or out-of-order values are dropped and listed in the report. The
admissions block (admit rate, counts, SAT/ACT) of a row comes from one source only: IPEDS ADM when the
college filed it, else College Scorecard, so a row never mixes years. The script stops if a column it
needs is missing from a file, so a renamed variable can't silently empty a field.
"""
import argparse
import json
import os
import re
import sys
from collections import Counter
from pathlib import Path

sys.path.insert(0, os.path.dirname(__file__))
from common import IMPUTED_FLAGS, MISSING, OUT, RAW, read_csv, write_csv, write_json  # noqa: E402

PREDDEG = {"0": "Not classified", "1": "Certificate", "2": "Associate's", "3": "Bachelor's", "4": "Graduate"}
HIGHDEG = {"0": "Non-degree", "1": "Certificate", "2": "Associate's", "3": "Bachelor's", "4": "Graduate"}

# ADMCONn and CREDITSn are identified by the words in their dictionary title, not by number,
# because IPEDS has added and renumbered these items over the years. Order matters (TOEFL before test scores).
ADMCON_KEYWORDS = [("req_gpa", "gpa"), ("req_class_rank", "rank"), ("req_hs_record", "record"),
                   ("req_prep_program", "prepar"), ("req_recommendations", "recommend"),
                   ("req_competencies", "competenc"), ("req_toefl", "toefl"), ("req_other_test", "other test"),
                   ("req_test_scores", "test score"), ("req_work_experience", "work experience"),
                   ("req_essay", "essay"), ("req_legacy", "legacy")]
CREDITS_KEYWORDS = [("dual_credit", "dual credit"), ("life_experience_credit", "life experience"),
                    ("ap_credit", "advanced placement")]

TEST_PARTS = {"SATVR": (200, 800), "SATMT": (200, 800), "ACTCM": (1, 36), "ACTEN": (1, 36), "ACTMT": (1, 36)}
TEST_NAMES = {"SATVR": "sat_erw", "SATMT": "sat_math", "ACTCM": "act_comp", "ACTEN": "act_english",
              "ACTMT": "act_math"}

IDENTITY = ["unitid", "opeid", "name", "alias", "website", "city", "state", "zip", "lat", "lon", "control",
            "level", "locale", "carnegie", "religious_affiliation", "hbcu", "predominant_degree",
            "highest_degree", "accreditor", "main_campus", "operating", "active", "closed_date", "merged_into",
            "address", "sector", "highest_offering", "size_category", "admissions_url", "application_url",
            "net_price_calculator_url", "financial_aid_url"]
# HD columns -> variable; *_url and address are text, the rest decoded labels.
HD_EXTRA = {"address": "ADDR", "sector": "SECTOR", "highest_offering": "HLOFFER", "size_category": "INSTSIZE",
            "admissions_url": "ADMINURL", "application_url": "APPLURL", "net_price_calculator_url": "NPRICURL",
            "financial_aid_url": "FAIDURL"}
ADMISSIONS = ["admissions_source", "admissions_year", "open_admission", "applicants", "admits", "enrolled",
              "admit_rate", "yield_rate", "sat_submit_pct", "act_submit_pct"]
TESTS = [f"{TEST_NAMES[p]}_p{q}" for p in TEST_PARTS for q in (25, 50, 75)] + ["sat_avg"]
COST = ["undergrad_enrollment", "tuition_in_state", "tuition_out_of_state", "net_price", "grad_rate",
        "retention_rate", "median_earnings_10yr"]


IPEDS_KEYS = ("hd", "adm", "ic", "ic_ay", "drvef", "efd", "drvgr", "sfa", "drvic")
OPTIONAL_KEYS = {"drvic"}  # files whose absence only empties their columns
# IPEDS measures found by dictionary title: column -> (file, title phrase, phrases to exclude).
MEASURES = {
    "undergrad_enrollment": ("drvef", "undergraduate enrollment", ("percent", "full-time", "part-time")),
    "tuition_in_state": ("ic_ay", "in-state tuition and fees", ("out-of-state", "in-district")),
    "tuition_out_of_state": ("ic_ay", "out-of-state tuition and fees", ()),
    "net_price": ("sfa", "average net price-students awarded grant or scholarship aid", ("income",)),
    "grad_rate": ("drvgr", "graduation rate, total cohort", ()),
    "retention_rate": ("efd", "full-time retention rate", ()),
}

# Optional measures: empty (not an error) when a release lacks them. Published prices only, never computed.
INCOME = [("0_30k", "0-30,000"), ("30_48k", "30,001-48,000"), ("48_75k", "48,001-75,000"),
          ("75_110k", "75,001-110,000"), ("110k_plus", "110,001")]
EXTRA_MEASURES = {
    "tuition_in_district": ("ic_ay", "in-district tuition and fees", ("out-of-state", "in-state")),
    "room_board_on_campus": ("ic_ay", ("on campus, food and housing", "on campus, room and board"), ()),
    "books_supplies": ("ic_ay", "books and supplies", ()),
    "cost_in_state_on_campus": ("drvic", "total price for in-state students living on campus", ()),
    "cost_out_of_state_on_campus": ("drvic", "total price for out-of-state students living on campus", ()),
    **{f"net_price_{k}": ("sfa", f"average net price (income {band}", ("grant or scholarship",))
       for k, band in INCOME[:-1]},
    "net_price_110k_plus": ("sfa", ("average net price (income 110,001", "average net price (income over 110",
                                    "average net price (income greater than 110", "average net price (income above 110"),
                            ("grant or scholarship",)),
}
COST += list(EXTRA_MEASURES)


class Data:
    def __init__(self, raw):
        raw = Path(raw)
        self.ipeds = {}
        self.dicts = {}
        for f in IPEDS_KEYS:
            if f in OPTIONAL_KEYS and not (raw / "ipeds" / f"{f}.csv").exists():
                self.ipeds[f], self.dicts[f] = {}, {"vars": {}, "codes": {}, "header": set()}
                continue
            rows = read_csv(raw / "ipeds" / f"{f}.csv")
            self.ipeds[f] = {r["UNITID"]: r for r in rows}
            self.dicts[f] = json.loads((raw / "ipeds" / f"{f}_dict.json").read_text())
            self.dicts[f]["header"] = set(rows[0]) if rows else set()
        sc_file = raw / "scorecard" / "institutions.csv"  # optional: the download host may refuse us
        sc = read_csv(sc_file) if sc_file.exists() else []
        self.sc = {r["UNITID"]: r for r in sc}
        self.sc_header = set(sc[0]) if sc else set()
        manifest = raw.parent / "manifest.json"
        self.manifest = json.loads(manifest.read_text()) if manifest.exists() else {}
        self.years = self.manifest.get("ipeds_years", {})  # {"hd": 2024, "adm": 2024, "ic": 2024}
        self.year = self.years.get("adm")
        self.measures = {}
        for col, (f, phrases, exclude) in {**MEASURES, **EXTRA_MEASURES}.items():
            found = None  # several phrases: IPEDS renamed some titles ("room and board" -> "food and housing")
            for phrase in (phrases,) if isinstance(phrases, str) else phrases:
                found = found or self.titled(f, phrase, exclude)
            self.measures[col] = (f, found)
            if found is None and col in EXTRA_MEASURES and self.dicts[f]["vars"]:
                first = phrases if isinstance(phrases, str) else phrases[0]
                near = [m["title"] for m in self.dicts[f]["vars"].values()
                        if first.split("(")[0].strip() in m["title"].lower()][:4]
                print(f"note: no {f} variable titled like '{first}' for {col}; nearest: {near}")
        self.imputed = Counter()
        self.dropped = []  # (unitid, column, value, reason)

    def require(self):
        """Stop when a variable we read is missing from its file."""
        need = {
            "hd": ["UNITID", "INSTNM", "IALIAS", "CITY", "STABBR", "ZIP", "WEBADDR", "OPEID", "CONTROL", "ICLEVEL",
                   "LOCALE", "HBCU", "LATITUDE", "LONGITUD", "CYACTIVE", "CLOSEDAT", "NEWID", "SECTOR"],
            "adm": ["UNITID", "APPLCN", "ADMSSN", "ENRLT", "SATPCT", "ACTPCT", "ADMCON1", "ADMCON7"]
                   + [f"{p}{q}" for p in TEST_PARTS for q in (25, 75)],
            "ic": ["UNITID", "OPENADMP", "RELAFFIL"],
        }
        missing = [f"{f}: {v}" for f, vs in need.items() for v in vs if v not in self.dicts[f]["header"]]
        sc_need = ["UNITID", "INSTNM", "CITY", "STABBR", "ADM_RATE", "SAT_AVG", "UGDS", "TUITIONFEE_IN",
                   "TUITIONFEE_OUT", "NPT4_PUB", "NPT4_PRIV", "CURROPER", "MAIN", "PREDDEG", "HIGHDEG",
                   "ACCREDAGENCY", "C150_4", "C150_L4", "RET_FT4", "RET_FTL4", "MD_EARN_WNE_P10"] + \
                  [f"{p}{q}" for p in TEST_PARTS for q in (25, 75)]
        if self.sc:
            missing += [f"scorecard: {v}" for v in sc_need if v not in self.sc_header]
        for col, (f, var) in self.measures.items():
            if var is None and col in MEASURES:
                missing.append(f"{f}: no variable titled like {MEASURES[col][1]}")
        if missing:
            raise SystemExit("columns missing from the source files (renamed in this release?):\n  "
                             + "\n  ".join(missing))

    def titled(self, f, phrase, exclude=()):
        """Variables whose dictionary title holds phrase (an exact title wins), from the latest year named in the
        titles: IC_AY and SFA carry several years side by side (e.g. CHG2AY0..CHG2AY3). Several variables can
        share a title (SFA's public and private net price); the first with a value is used."""
        hits = []
        for var, meta in self.dicts[f]["vars"].items():
            t = " ".join(meta["title"].lower().split())
            if phrase in t and not any(x in t for x in exclude) and var in self.dicts[f]["header"]:
                year = max((int(y) for y in re.findall(r"(?:19|20)\d\d", t)), default=0)
                hits.append((t != phrase, -year, var))
        if not hits:
            return None
        best = min(hits)[:2]
        return tuple(sorted(v for e, y, v in hits if (e, y) == best))

    def by_title(self, f, prefix, keywords):
        """{our column: variable} for variables named like pattern, matched on their dictionary title."""
        found = {}
        for var, meta in self.dicts[f]["vars"].items():
            if not re.fullmatch(prefix, var) or var not in self.dicts[f]["header"]:
                continue
            title = meta["title"].lower()
            for col, kw in keywords:
                if kw in title and col not in found:
                    found[col] = var
                    break
        return found

    # value readers -------------------------------------------------------------------------------------------
    def iv(self, f, uid, var):
        """An IPEDS value, or None when missing, not applicable (code -1/-2/-3) or imputed."""
        r = self.ipeds[f].get(uid)
        if not r:
            return None
        v = r.get(var, "")
        if v in MISSING or v in ("-1", "-2", "-3"):  # IPEDS: not reported / not applicable
            return None
        if r.get("X" + var, "") in IMPUTED_FLAGS:
            self.imputed[f"{f}.{var}"] += 1
            return None
        return v

    def label(self, f, uid, var):
        v = self.iv(f, uid, var)
        if v is None:
            return None
        lab = self.dicts[f]["codes"].get(var, {}).get(v)
        if lab is None or lab.lower() in ("not applicable", "not reported", "{item not available}"):
            return None
        return lab

    def sv(self, uid, var):
        r = self.sc.get(uid)
        v = r.get(var, "") if r else ""
        return None if v in MISSING else v


def num(v, kind="float"):
    if v is None:
        return None
    try:
        x = float(v.replace(",", "")) if isinstance(v, str) else float(v)
    except ValueError:
        return None
    if kind == "int":
        return int(round(x))
    return x


def yes_no(label):
    if label is None:
        return None
    lab = label.lower()
    if re.match(r"yes\b", lab):
        return "Yes"
    if re.match(r"(implied )?no\b", lab):
        return "No"
    return label


def build_row(d, uid, admcon, credits):
    hd = d.ipeds["hd"].get(uid, {})
    row = {"unitid": int(uid)}
    row["name"] = d.iv("hd", uid, "INSTNM") or d.sv(uid, "INSTNM")
    row["alias"] = d.iv("hd", uid, "IALIAS")
    row["opeid"] = d.iv("hd", uid, "OPEID") or d.sv(uid, "OPEID")
    row["website"] = d.iv("hd", uid, "WEBADDR") or d.sv(uid, "INSTURL")
    row["city"] = d.iv("hd", uid, "CITY") or d.sv(uid, "CITY")
    row["state"] = d.iv("hd", uid, "STABBR") or d.sv(uid, "STABBR")
    row["zip"] = d.iv("hd", uid, "ZIP") or d.sv(uid, "ZIP")
    row["lat"] = num(d.iv("hd", uid, "LATITUDE") or d.sv(uid, "LATITUDE"))
    row["lon"] = num(d.iv("hd", uid, "LONGITUD") or d.sv(uid, "LONGITUDE"))
    row["control"] = d.label("hd", uid, "CONTROL")
    row["level"] = d.label("hd", uid, "ICLEVEL")
    row["locale"] = d.label("hd", uid, "LOCALE")
    carnegie = sorted(v for v in d.dicts["hd"]["header"] if re.fullmatch(r"C\d\dBASIC", v))
    row["carnegie"] = d.label("hd", uid, carnegie[-1]) if carnegie else None
    row["religious_affiliation"] = d.label("ic", uid, "RELAFFIL")
    row["hbcu"] = yes_no(d.label("hd", uid, "HBCU"))
    row["predominant_degree"] = PREDDEG.get(d.sv(uid, "PREDDEG") or "")
    row["highest_degree"] = HIGHDEG.get(d.sv(uid, "HIGHDEG") or "")
    row["accreditor"] = d.sv(uid, "ACCREDAGENCY")
    main = d.sv(uid, "MAIN")
    row["main_campus"] = None if main is None else ("Yes" if main == "1" else "No")
    oper = d.sv(uid, "CURROPER")
    row["operating"] = None if oper is None else ("Yes" if oper == "1" else "No")
    row["active"] = yes_no(d.label("hd", uid, "CYACTIVE")) if hd else None
    row["closed_date"] = d.iv("hd", uid, "CLOSEDAT")
    row["merged_into"] = d.iv("hd", uid, "NEWID")
    for col, var in HD_EXTRA.items():
        if var in d.dicts["hd"]["header"]:
            row[col] = d.iv("hd", uid, var) if col == "address" or col.endswith("_url") else d.label("hd", uid, var)

    row["open_admission"] = yes_no(d.label("ic", uid, "OPENADMP"))
    if row["open_admission"] is None and d.sv(uid, "OPENADMP") is not None:
        row["open_admission"] = "Yes" if d.sv(uid, "OPENADMP") == "1" else "No"

    # Admissions block: one source per row.
    if uid in d.ipeds["adm"]:
        row["admissions_source"], row["admissions_year"] = "IPEDS ADM", d.year
        get = lambda var: d.iv("adm", uid, var)  # noqa: E731
        apps, admits, enr = (num(get(v), "int") for v in ("APPLCN", "ADMSSN", "ENRLT"))
        row.update(applicants=apps, admits=admits, enrolled=enr)
        row["sat_submit_pct"] = num(get("SATPCT"), "int")
        row["act_submit_pct"] = num(get("ACTPCT"), "int")
        if apps and admits is not None:
            row["admit_rate"] = round(admits / apps, 4)
        if admits and enr is not None:
            row["yield_rate"] = round(enr / admits, 4)
    elif d.sv(uid, "ADM_RATE") is not None or d.sv(uid, "SATVR25") is not None:
        row["admissions_source"], row["admissions_year"] = "College Scorecard", None
        get = lambda var: d.sv(uid, var)  # noqa: E731
        row["admit_rate"] = num(get("ADM_RATE"))
    else:
        get = None
    if get:
        for part in TEST_PARTS:
            for q in (25, 50, 75):
                row[f"{TEST_NAMES[part]}_p{q}"] = num(get(f"{part}{q}"), "int")
    row["sat_avg"] = num(d.sv(uid, "SAT_AVG"), "int")

    for col, var in admcon.items():
        row[col] = d.label("adm", uid, var)
    for col, var in credits.items():
        row[col] = yes_no(d.label("ic", uid, var))

    def measure(col, kind="int", scale=1):
        f, variables = d.measures[col]
        v = None
        for var in variables or ():
            v = num(d.iv(f, uid, var), "float")
            if v is not None:
                break
        return None if v is None else (int(round(v)) if kind == "int" else round(v / scale, 4))

    row["undergrad_enrollment"] = measure("undergrad_enrollment") or num(d.sv(uid, "UGDS"), "int")
    row["tuition_in_state"] = measure("tuition_in_state") or num(d.sv(uid, "TUITIONFEE_IN"), "int")
    row["tuition_out_of_state"] = measure("tuition_out_of_state") or num(d.sv(uid, "TUITIONFEE_OUT"), "int")
    row["net_price"] = measure("net_price") or num(d.sv(uid, "NPT4_PUB") or d.sv(uid, "NPT4_PRIV"), "int")
    gr, ret = measure("grad_rate", "rate", 100), measure("retention_rate", "rate", 100)
    row["grad_rate"] = gr if gr is not None else num(d.sv(uid, "C150_4") or d.sv(uid, "C150_L4"))
    row["retention_rate"] = ret if ret is not None else num(d.sv(uid, "RET_FT4") or d.sv(uid, "RET_FTL4"))
    row["median_earnings_10yr"] = num(d.sv(uid, "MD_EARN_WNE_P10"), "int")
    for col in EXTRA_MEASURES:
        row[col] = measure(col)
    check(d, row)
    return row


def check(d, row):
    def drop(cols, reason):
        for c in cols:
            if row.get(c) is not None:
                d.dropped.append((row["unitid"], c, row[c], reason))
                row[c] = None

    for part, (lo, hi) in TEST_PARTS.items():
        cols = [f"{TEST_NAMES[part]}_p{q}" for q in (25, 50, 75)]
        bad = [c for c in cols if row.get(c) is not None and not lo <= row[c] <= hi]
        drop(bad, f"outside {lo}-{hi}")
        vals = [row.get(c) for c in cols if row.get(c) is not None]
        if vals != sorted(vals):
            drop(cols, "percentiles out of order")
    if row.get("sat_avg") is not None and not 400 <= row["sat_avg"] <= 1600:
        drop(["sat_avg"], "outside 400-1600")
    for c in ("admit_rate", "yield_rate", "grad_rate", "retention_rate"):
        if row.get(c) is not None and not 0 <= row[c] <= 1:
            drop([c], "outside 0-1")
    for c in ("sat_submit_pct", "act_submit_pct"):
        if row.get(c) is not None and not 0 <= row[c] <= 100:
            drop([c], "outside 0-100")
    if row.get("applicants") is not None and row.get("admits") is not None and row["admits"] > row["applicants"]:
        drop(["applicants", "admits", "enrolled", "admit_rate", "yield_rate"], "more admits than applicants")


def sources(d, admcon, credits):
    y, yh, yi = d.year, d.years.get("hd"), d.years.get("ic")
    sc = "College Scorecard, Most Recent Institution-Level Data"
    s = {
        "unitid": ("IPEDS / College Scorecard", "UNITID", None, "Federal institution ID; join key"),
        "opeid": (f"IPEDS HD{yh}", "OPEID", yh, "Federal Student Aid ID"),
        "name": (f"IPEDS HD{yh}", "INSTNM", yh, "Official name"),
        "alias": (f"IPEDS HD{yh}", "IALIAS", yh, "Other names the college uses"),
        "website": (f"IPEDS HD{yh}", "WEBADDR", yh, ""),
        "city": (f"IPEDS HD{yh}", "CITY", yh, ""), "state": (f"IPEDS HD{yh}", "STABBR", yh, ""),
        "zip": (f"IPEDS HD{yh}", "ZIP", yh, ""), "lat": (f"IPEDS HD{yh}", "LATITUDE", yh, ""),
        "lon": (f"IPEDS HD{yh}", "LONGITUD", yh, ""),
        "control": (f"IPEDS HD{yh}", "CONTROL", yh, "Public / private nonprofit / private for-profit"),
        "level": (f"IPEDS HD{yh}", "ICLEVEL", yh, "Four or more years / two-year / less than two-year"),
        "locale": (f"IPEDS HD{yh}", "LOCALE", yh, "City, suburb, town or rural"),
        "carnegie": (f"IPEDS HD{yh}", "C??BASIC (newest)", yh, "Carnegie basic classification"),
        "religious_affiliation": (f"IPEDS IC{yi}", "RELAFFIL", yi, ""),
        "hbcu": (f"IPEDS HD{yh}", "HBCU", yh, "Historically Black college or university"),
        "predominant_degree": (sc, "PREDDEG", None, ""), "highest_degree": (sc, "HIGHDEG", None, ""),
        "accreditor": (sc, "ACCREDAGENCY", None, ""), "main_campus": (sc, "MAIN", None, ""),
        "operating": (sc, "CURROPER", None, "Currently operating"),
        "active": (f"IPEDS HD{yh}", "CYACTIVE", yh, "Active in the current IPEDS year"),
        "closed_date": (f"IPEDS HD{yh}", "CLOSEDAT", yh, ""),
        "merged_into": (f"IPEDS HD{yh}", "NEWID", yh, "UNITID of the institution it merged into"),
        "open_admission": (f"IPEDS IC{yi}", "OPENADMP", yi, "Open admission policy"),
        "admissions_source": ("", "", None, "IPEDS ADM when the college filed it, else College Scorecard"),
        "admissions_year": ("", "", None, "Fall of the entering class the admissions figures describe"),
        "applicants": (f"IPEDS ADM{y}", "APPLCN", y, "First-time, degree-seeking applicants"),
        "admits": (f"IPEDS ADM{y}", "ADMSSN", y, ""), "enrolled": (f"IPEDS ADM{y}", "ENRLT", y, ""),
        "admit_rate": (f"IPEDS ADM{y} (else Scorecard ADM_RATE)", "ADMSSN / APPLCN", y, "0-1"),
        "yield_rate": (f"IPEDS ADM{y}", "ENRLT / ADMSSN", y, "0-1"),
        "sat_submit_pct": (f"IPEDS ADM{y}", "SATPCT", y, "% of enrollees who submitted SAT"),
        "act_submit_pct": (f"IPEDS ADM{y}", "ACTPCT", y, "% of enrollees who submitted ACT"),
        "sat_avg": (sc, "SAT_AVG", None, "Scorecard's SAT-equivalent average of admitted students (derived)"),
        **{col: (f"IPEDS {d.measures[col][0].upper()} {d.years.get(d.measures[col][0])}"
                 + (" (else Scorecard)" if col in MEASURES else ""),
                 " / ".join(d.measures[col][1] or ()), d.years.get(d.measures[col][0]),
                 d.dicts[d.measures[col][0]]["vars"].get((d.measures[col][1] or ("",))[0], {}).get("title", ""))
           for col in {**MEASURES, **EXTRA_MEASURES}},
        **{col: (f"IPEDS HD{yh}", var, yh, "") for col, var in HD_EXTRA.items()},
        "median_earnings_10yr": (sc, "MD_EARN_WNE_P10", None, "Median earnings 10 years after entry, USD"),
    }
    for part in TEST_PARTS:
        for q in (25, 50, 75):
            s[f"{TEST_NAMES[part]}_p{q}"] = (f"IPEDS ADM{y} (else Scorecard)", f"{part}{q}", y,
                                             "50th percentile reported from 2022-23 on")
    for col, var in admcon.items():
        s[col] = (f"IPEDS ADM{y}", var, y, d.dicts["adm"]["vars"][var]["title"])
    for col, var in credits.items():
        s[col] = (f"IPEDS IC{yi}", var, yi, d.dicts["ic"]["vars"][var]["title"])
    # Tables from an IPEDS provisional release (newer than the complete data files) say so in their source.
    prov = {k for k in IPEDS_KEYS if d.manifest.get("files", {}).get(f"ipeds_{k}", {}).get("release") == "provisional"}
    for k, v in s.items():
        m = re.match(r"IPEDS ([A-Z_]+?) ?\d{4}", v[0])
        if m and m.group(1).lower() in prov:
            s[k] = (v[0].replace(m.group(0), m.group(0) + " provisional release", 1),) + v[1:]
    return {k: dict(zip(("source", "variable", "year", "meaning"), v)) for k, v in s.items()}


def report(d, rows, columns, path):
    n = len(rows)
    by_src = Counter(r.get("admissions_source") or "none" for r in rows)
    four = [r for r in rows if (r.get("level") or "").lower().startswith("four")]
    lines = ["# Admissions data QA report", "",
             f"IPEDS years: {d.years}. Scorecard file: {d.manifest.get('files', {}).get('scorecard', {}).get('url')}",
             "", f"{n:,} institutions; {len(four):,} four-year.", "",
             "Admissions block source: " + ", ".join(f"{k} {v:,}" for k, v in by_src.most_common()), "",
             "## Coverage", "", "| Column | All | Four-year |", "| --- | --- | --- |"]
    for c in columns:
        a = sum(r.get(c) is not None for r in rows)
        b = sum(r.get(c) is not None for r in four)
        lines.append(f"| {c} | {a:,} ({a / max(n, 1):.0%}) | {b:,} ({b / max(len(four), 1):.0%}) |")
    lines += ["", "## Imputed IPEDS values left out", ""]
    lines += [f"- {k}: {v:,}" for k, v in d.imputed.most_common()] or ["- none"]
    lines += ["", f"## Values dropped by checks ({len(d.dropped):,})", ""]
    reasons = Counter((c, why) for _, c, _, why in d.dropped)
    lines += [f"- {c}: {why} ({k:,})" for (c, why), k in reasons.most_common()] or ["- none"]
    if d.dropped:
        lines += ["", "| unitid | column | value | reason |", "| --- | --- | --- | --- |"]
        lines += [f"| {u} | {c} | {v} | {why} |" for u, c, v, why in d.dropped[:300]]
    path.write_text("\n".join(lines) + "\n")


def main(argv=None):
    ap = argparse.ArgumentParser()
    ap.add_argument("--raw", default=str(RAW))
    ap.add_argument("--out", default=str(OUT))
    a = ap.parse_args(argv)
    out = Path(a.out)
    d = Data(a.raw)
    d.require()
    admcon = d.by_title("adm", r"ADMCON\d+", ADMCON_KEYWORDS)
    credits = d.by_title("ic", r"\w+", CREDITS_KEYWORDS)  # optional: IPEDS has moved these items between years
    if len(credits) < len(CREDITS_KEYWORDS):
        print(f"note: credit-policy items found in IC: {credits or 'none'}")
    for need in ("req_gpa", "req_test_scores"):
        if need not in admcon:
            raise SystemExit(f"no ADMCON variable titled like {need} in the ADM dictionary")
    ids = set(d.sc) | set(d.ipeds["adm"]) | {u for u, r in d.ipeds["hd"].items() if r.get("SECTOR") != "0"}
    rows = [build_row(d, uid, admcon, credits) for uid in sorted(ids, key=int)]
    order = [c for c, _ in ADMCON_KEYWORDS if c in admcon]
    order += [c for c, _ in CREDITS_KEYWORDS if c in credits]
    columns = IDENTITY + ADMISSIONS + TESTS + order + COST
    write_csv(out / "institutions.csv", rows, columns)
    src = sources(d, admcon, credits)
    write_json(out / "field_sources.json", {c: src[c] for c in columns})
    report(d, rows, columns, out / "qa_report.md")
    print(f"{len(rows):,} institutions -> {out / 'institutions.csv'}; {len(d.dropped)} values dropped")
    return rows


if __name__ == "__main__":
    main()
