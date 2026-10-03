"""GPA engine v2 QA: College GPA and UCLA on the shared core, in the live College page's layout.

    python3 tests/gpa_v2_qa.py [--cases N] [--shots]

Builds the preview pages (scripts/calc/build_preview.py --modules, so the real ES modules load) and checks:
  1. Math: random College and UCLA calculations typed into the UI, compared with values computed here
     in Python from the grading tables (never by calling the calculator's code), plus fixed edge cases.
  2. Flow: sample -> result -> planner -> save -> reload -> share link in a fresh browser -> reset + undo,
     old College/homepage saves copied over (old keys left untouched), old UCLA draft copied, GA4 events.
  3. Layout at 390 and 1440 (no sideways scroll, calculator as wide as the column), screenshots with --shots.
  4. Re-color: changing one palette token (--gpa-blue-600) re-colors the calculator.
  5. No color literals (hex, rgb, named) in the new calculator files.
Every page must finish with zero console errors.
"""
import json, random, re, sys, gzip, subprocess
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
from qa_lib import REPO, ARTIFACTS, CALC_ASSETS, Session, Results  # noqa: E402

OUT = ARTIFACTS / "calc-v2"
SHOTS = ARTIFACTS / "calc-v2-shots"
LEXEND = REPO / "node_modules/@fontsource/lexend/files"
args = sys.argv[1:]
N = int(args[args.index("--cases") + 1]) if "--cases" in args else 60
R = Results("GPA engine v2 (College + UCLA)")

# ---------- grading tables (from the profiles' published scales) ----------
STANDARD = {"A+": 4, "A": 4, "A-": 3.7, "B+": 3.3, "B": 3, "B-": 2.7, "C+": 2.3, "C": 2, "C-": 1.7, "D+": 1.3, "D": 1, "D-": 0.7, "F": 0}
SCALES = {
    "standard": STANDARD,
    "a-plus-433": {**STANDARD, "A+": 4.33},
    "no-plus-minus": {"A": 4, "B": 3, "C": 2, "D": 1, "F": 0},
}
UCLA = json.loads((REPO / "data/gpcm-university-profiles/ucla.json").read_text())
UCLA_GRADES = UCLA["baseContext"]["grades"]
UCLA_TYPES = {t["name"]: t for t in UCLA["baseContext"]["specialTypes"]}


def r_half(x, d):
    return float(Decimal(repr(x)).quantize(Decimal(1).scaleb(-d), rounding=ROUND_HALF_UP) if x == x else x)


def fmt(x, d=2):
    # half away from zero, with the same tiny float allowance the UI contract states (1e-9)
    return f"{float(Decimal(repr(x + 1e-9)).quantize(Decimal(1).scaleb(-d), rounding=ROUND_HALF_UP)):.{d}f}"


def fmt_num(x, d=2):
    s = fmt(x, d)
    return s.rstrip("0").rstrip(".") if "." in s else s


# ---------- browser helpers ----------

def lexend_css():
    import base64
    out = []
    for w in (300, 400, 500, 600, 700, 800):
        f = LEXEND / f"lexend-latin-{w}-normal.woff2"
        if f.exists():
            out.append(f"@font-face{{font-family:'Lexend';font-weight:{w};font-display:swap;src:url(data:font/woff2;base64,"
                       f"{base64.b64encode(f.read_bytes()).decode()}) format('woff2');}}")
    return "\n".join(out)


FONT_CSS = None


def ctx_for(s, width=1440, height=900, mobile=False, init=None, clipboard=False):
    global FONT_CSS
    FONT_CSS = FONT_CSS or lexend_css()
    c = s.context(width, height, mobile=mobile, init_script=init)
    c.route("**/fonts.googleapis.com/**", lambda r: r.fulfill(status=200, content_type="text/css", body=FONT_CSS))
    c.route("**/gpacalculator.net/**", lambda r: r.fulfill(status=204, body=""))
    if clipboard:
        c.grant_permissions(["clipboard-read", "clipboard-write"], origin=s.base)
    return c


def open_page(s, c, name):
    p = s.page(c, f"/tests/.artifacts/calc-v2/{name}.html")
    p.on("dialog", lambda d: d.accept(DIALOG["text"] if DIALOG.get("text") is not None else d.default_value) if d.type == "prompt" else d.accept())
    p.wait_for_selector(".calc .calc-card")
    return p


DIALOG = {}


def text(p, sel):
    loc = p.locator(sel)
    return loc.first.inner_text().strip() if loc.count() and loc.first.is_visible() else None


def opts(p):
    """Open Options (scale, previous GPA, Major GPA): closed by default."""
    if not p.locator(".gpa-options").is_visible():
        p.click(".gpa-options-btn")


def pick_grade(p, row, g):
    """Desktop: the grade select. Phones: the grade button opens the letter grid in a bottom sheet."""
    if row.locator(".gpa-f-grade select").is_visible():
        row.locator(".gpa-f-grade select").select_option(g)
        return
    row.locator(".gpa-grade-btn").click()
    label = g.replace("-", "−")
    p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").filter(has_text=re.compile(f"^{re.escape(label)}$")).click()


def set_credits(p, row, v):
    """Desktop: the credits field. Phones: quick buttons 1-5, or Other for the field."""
    field = row.locator(".gpa-f-cr input")
    if field.is_visible():
        field.fill(v)
        return
    row.locator(".gpa-cr-btn").click()
    quick = v in ("1", "2", "3", "4", "5")
    p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").filter(has_text=re.compile(f"^{v if quick else 'Other'}$")).click()
    if not quick:
        field.fill(v)


# ---------- 1. Math ----------

def college_case(rng):
    scale = rng.choice(list(SCALES))
    grades = list(SCALES[scale])
    terms = []
    for _ in range(rng.randint(1, 3)):
        terms.append([{
            "grade": rng.choice(grades),
            "credits": rng.choice(["1", "2", "3", "3", "4", "4", "5", "1.5", "0.5"]),
            "major": rng.random() < 0.3,
        } for _ in range(rng.randint(1, 6))])
    prior = None
    if rng.random() < 0.35:
        prior = (f"{rng.uniform(1.5, 4.0):.2f}", str(rng.randint(12, 90)))
    target = (f"{rng.uniform(2.0, 4.0):.2f}", str(rng.choice([3, 6, 12, 15, 16, 30])))
    return {"scale": scale, "terms": terms, "prior": prior, "target": target}


def college_expect(case):
    t = SCALES[case["scale"]]
    mx = max(t.values())
    out = {"terms": []}
    C = P = MC = MP = 0.0
    if case["prior"]:
        C, P = float(case["prior"][1]), float(case["prior"][0]) * float(case["prior"][1])
    for rows in case["terms"]:
        c = sum(float(r["credits"]) for r in rows)
        pts = sum(t[r["grade"]] * float(r["credits"]) for r in rows)
        out["terms"].append(pts / c)
        C += c
        P += pts
        MC += sum(float(r["credits"]) for r in rows if r["major"])
        MP += sum(t[r["grade"]] * float(r["credits"]) for r in rows if r["major"])
    out.update(gpa=P / C, credits=C, points=P, major=(MP / MC if MC else None))
    T, U = float(case["target"][0]), float(case["target"][1])
    need = (T * (C + U) - P) / U
    out["plan"] = "met" if need <= 0 else "reachable" if need <= mx + 1e-9 else "out"
    out["need"] = need
    return out


def fill_college(p, case):
    if case["scale"] != "standard":
        opts(p)
        p.select_option("#college-scale", case["scale"])
    if case["prior"]:
        opts(p)
        p.click(".gpa-prior-toggle")
        p.fill("#college-prior-gpa", case["prior"][0])
        p.fill("#college-prior-cr", case["prior"][1])
    if any(r["major"] for rows in case["terms"] for r in rows):
        opts(p)
        p.check("#college-major-on")
    for ti, rows in enumerate(case["terms"]):
        if ti:
            p.click(".gpa-add-term")
        term = p.locator(".gpa-term").nth(ti)
        for i, r in enumerate(rows):
            # one blank row to start; grading the last row adds the next (no "Add class" button)
            R.check(f"auto-add: row {i + 1} ready", term.locator(".gpa-row").count(), i + 1) if ti == 0 and i < 3 else None
            row = term.locator(".gpa-row").nth(i)
            row.locator(".gpa-f-grade select").select_option(r["grade"])
            row.locator(".gpa-f-cr input").fill(r["credits"])
            if r["major"]:
                row.locator(".gpa-major input").check()
        # the trailing blank row keeps its default credits and no grade: it must not count
    p.wait_for_timeout(30)


def check_college(p, case, label):
    e = college_expect(case)
    R.check(f"{label} cumulative", text(p, ".calc-score"), fmt(e["gpa"]))
    R.check(f"{label} result label", text(p, ".calc-result .calc-kicker"), "Cumulative GPA" if len(case["terms"]) > 1 or case["prior"] else "Semester GPA")
    for i, g in enumerate(e["terms"] if len(e["terms"]) > 1 else []):  # one semester: no semester header
        R.check(f"{label} term {i + 1}", text(p, f".gpa-term:nth-child({i + 1}) .gpa-term-gpa"), f"GPA {fmt(g)}")
    R.check(f"{label} credits", p.locator(".calc-stat").nth(0).locator(".calc-stat-v").inner_text(), fmt_num(e["credits"]))
    R.check(f"{label} quality points", p.locator(".calc-stat").nth(1).locator(".calc-stat-v").inner_text(), fmt_num(e["points"]))
    major = p.locator(".calc-stat").nth(2)
    R.check(f"{label} major", major.locator(".calc-stat-v").inner_text() if major.is_visible() else None,
            fmt(e["major"]) if e["major"] is not None else None)
    # planner
    p.locator(".calc-next .calc-btn-primary").click()
    p.fill("#college-t-cr", case["target"][1])
    p.fill("#college-t-gpa", case["target"][0])
    big = text(p, ".calc-plan-big")
    want = {"met": "You’re there", "out": "Out of reach this term"}.get(e["plan"]) or fmt(e["need"])
    R.check(f"{label} planner ({e['plan']})", big, want)


def ucla_case(rng):
    grades = list(UCLA_GRADES)
    names = [n for n in UCLA_TYPES]

    def rows(k):
        return [{"grade": rng.choice(grades), "units": rng.choice(["2", "4", "4", "5", "1.5", "4"]),
                 "type": rng.choices(names, weights=[6, 1, 1, 1, 1])[0]} for _ in range(k)]
    prior = (f"{rng.uniform(2.0, 4.0):.2f}", str(rng.randint(20, 120))) if rng.random() < 0.4 else None
    return {"done": rows(rng.randint(1, 5)), "plan": rows(rng.randint(0, 3)), "prior": prior}


def ucla_expect(case):
    def counts(r):
        t = UCLA_TYPES[r["type"]]
        return not t.get("excluded") and not t.get("manualReview") and UCLA_GRADES[r["grade"]] is not None
    C = P = 0.0
    if case["prior"]:
        C, P = float(case["prior"][1]), float(case["prior"][0]) * float(case["prior"][1])
    for r in case["done"]:
        if counts(r):
            C += float(r["units"])
            P += UCLA_GRADES[r["grade"]] * float(r["units"])
    pc = sum(float(r["units"]) for r in case["plan"] if counts(r))
    pp = sum(UCLA_GRADES[r["grade"]] * float(r["units"]) for r in case["plan"] if counts(r))
    return {"gpa": P / C if C else None, "credits": C, "projected": (P + pp) / (C + pc) if pc else None,
            "excluded": sum(1 for r in case["done"] + case["plan"] if not counts(r))}


def fill_ucla(p, case):
    if case["prior"]:
        opts(p)
        p.click(".gpa-prior-toggle")
        p.fill("#ucla-prior-gpa", case["prior"][0])
        p.fill("#ucla-prior-cr", case["prior"][1])
    for ti, key in enumerate(("done", "plan")):
        term = p.locator(".gpa-term").nth(ti)
        for i, r in enumerate(case[key]):
            if i >= term.locator(".gpa-row").count():
                term.locator(".gpa-add-row").click()  # planned courses: still added by hand
            row = term.locator(".gpa-row").nth(i)
            row.locator(".gpa-f-grade select").select_option(r["grade"])
            row.locator(".gpa-f-cr input").fill(r["units"])
            row.locator(".gpa-f-type select").select_option(r["type"])
    p.wait_for_timeout(30)


def check_ucla(p, case, label):
    e = ucla_expect(case)
    R.check(f"{label} UCLA GPA", text(p, ".calc-score"), fmt(e["gpa"], 3) if e["gpa"] is not None else None)
    if e["gpa"] is None:
        return
    R.check(f"{label} units", p.locator(".calc-stat").nth(0).locator(".calc-stat-v").inner_text(), fmt_num(e["credits"]))
    proj = p.locator(".calc-stat").nth(3)
    R.check(f"{label} with planned", proj.locator(".calc-stat-v").inner_text() if proj.is_visible() else None,
            fmt(e["projected"], 3) if e["projected"] is not None else None)
    R.check(f"{label} not-counted notes", p.locator(".calc-row-msg:not(.is-error)").filter(has_text="Not counted").count(), e["excluded"])


def fresh(p):
    # Leaving the page flushes the pending autosave, so clear, reload, clear again, reload.
    for _ in range(2):
        p.evaluate("localStorage.clear()")
        p.reload()
        p.wait_for_selector(".calc .calc-card")


def math(s):
    rng = random.Random(20261003)
    c = ctx_for(s)
    p = open_page(s, c, "college-gpa-calculator")
    for i in range(N):
        case = college_case(rng)
        fresh(p)
        fill_college(p, case)
        check_college(p, case, f"college #{i + 1}")

    # Fixed edge cases: credit validation, decimal comma, prior GPA validation, missing credits
    edges = [
        ("0", "Credits must be more than 0."), ("25", "Credits can be at most 20 per course."),
        ("abc", "Enter credits as a number."), ("", "Enter credits for this course."),
    ]
    for cr, msg in edges:
        fresh(p)
        row = p.locator(".gpa-row").first
        row.locator(".gpa-f-grade select").select_option("B")
        row.locator(".gpa-f-cr input").fill(cr)
        R.check(f"edge credits {cr!r} message", row.locator(".calc-row-msg").inner_text(), msg)
        R.check(f"edge credits {cr!r} not counted", p.locator(".calc-result").is_visible(), False)
        R.check(f"edge credits {cr!r} aria-invalid", row.locator(".gpa-f-cr input").get_attribute("aria-invalid"), "true")
    fresh(p)
    row = p.locator(".gpa-row").first
    row.locator(".gpa-f-grade select").select_option("A-")
    row.locator(".gpa-f-cr input").fill("1,5")
    R.check("edge decimal comma counts", text(p, ".calc-stat:nth-child(1) .calc-stat-v"), "1.5")
    opts(p)
    p.click(".gpa-prior-toggle")
    p.fill("#college-prior-gpa", "4.5")
    p.fill("#college-prior-cr", "30")
    R.check("edge prior GPA over scale", text(p, "#college-prior-gpa-msg"), "Enter a GPA from 0 to 4.")
    R.check("edge prior ignored while invalid", text(p, ".calc-score"), "3.70")
    p.fill("#college-prior-gpa", "3.10")
    R.check("edge prior used", text(p, ".calc-score"), fmt((3.1 * 30 + 3.7 * 1.5) / 31.5))
    fresh(p)
    row = p.locator(".gpa-row").first
    row.locator(".gpa-f-grade select").select_option("D")
    row.locator(".gpa-f-cr input").fill("3")
    R.check("edge below 2.0 verdict", "below the 2.0" in (text(p, ".calc-verdict") or ""), True)
    R.check("edge below 2.0 planner label", text(p, ".calc-next .calc-btn-primary"), "Plan getting back above 2.0")
    p.locator(".calc-next .calc-btn-primary").click()
    R.check("edge rescue target defaults to 2.0", p.input_value("#college-t-gpa"), "2.00")
    R.check("edge rescue needed", text(p, ".calc-plan-big"), fmt((2.0 * 18 - 3) / 15))
    # P, NP and W: on the grade list as "Not counted in GPA", left out of the math
    fresh(p)
    groups = p.evaluate("[...document.querySelector('.gpa-row .gpa-f-grade select').querySelectorAll('optgroup')].map((g) => [g.label, [...g.children].map((o) => o.value)])")
    R.check("edge P/NP/W: grade list group", groups, [["Not counted in GPA", ["P", "NP", "W"]]])
    rows = p.locator(".gpa-row")
    rows.nth(0).locator(".gpa-f-grade select").select_option("B")
    rows.nth(0).locator(".gpa-f-cr input").fill("4")
    names = {"P": "P (pass)", "NP": "NP (no pass)", "W": "W (withdrawn)"}
    for i, g in enumerate(("P", "NP", "W")):
        r = rows.nth(i + 1)
        r.locator(".gpa-f-grade select").select_option(g)
        if g == "W":
            r.locator(".gpa-f-cr input").fill("")  # a withdrawal needs no credits
        R.check(f"edge {g}: note under the row", r.locator(".calc-row-msg").inner_text(), f"Not counted: {names[g]} isn’t counted in GPA.")
        R.check(f"edge {g}: no error", r.locator(".calc-row-msg.is-error").count(), 0)
        R.check(f"edge {g}: GPA unchanged", text(p, ".calc-score"), "3.00")
        R.check(f"edge {g}: credits unchanged", text(p, ".calc-stat:nth-child(1) .calc-stat-v"), "4")
    R.check("edge P/NP/W: result note", text(p, ".calc-result-note"), "3 courses not counted (see the note under each).")
    R.check("edge P/NP/W: auto-added a blank row after W", rows.count(), 5)
    opts(p)
    p.select_option("#college-scale", "no-plus-minus")
    R.check("edge P/NP/W: kept on the no plus/minus scale", text(p, ".calc-score"), "3.00")
    R.check("edge P/NP/W: notes kept on the no plus/minus scale", p.locator(".calc-row-msg:not(.is-error)").filter(has_text="Not counted").count(), 3)
    p.close()
    c.close()

    c = ctx_for(s)
    p = open_page(s, c, "ucla-gpa-calculator")
    for i in range(max(20, N // 3)):
        case = ucla_case(rng)
        fresh(p)
        fill_ucla(p, case)
        check_ucla(p, case, f"ucla #{i + 1}")
    # The profile's own help example and the sample
    fresh(p)
    p.click(".gpa-sample")
    s_ = UCLA["sampleData"]
    pts = float(s_["previousGpa"]) * float(s_["previousUnits"]) + sum(UCLA_GRADES[r["grade"]] * float(r["units"]) for r in s_["completed"])
    cr = float(s_["previousUnits"]) + sum(float(r["units"]) for r in s_["completed"])
    R.check("ucla sample GPA", text(p, ".calc-score"), fmt(pts / cr, 3))
    p.close()
    c.close()


# ---------- 2. Flow ----------

def events(p):
    return [e[1] for e in p.evaluate("window.__events") if e and e[0] == "event"]


def flow(s):
    c = ctx_for(s, clipboard=True)
    p = open_page(s, c, "college-gpa-calculator")
    R.check("flow: first visit offers a sample in the action row", text(p, ".calc-actions .gpa-sample"), "Try a sample")
    R.check("flow: no banner on a first visit", p.locator(".calc-banner").is_visible(), False)
    R.check("flow: no result before input", p.locator(".calc-result").is_visible(), False)
    R.check("flow: Start over hidden until something is entered", p.locator(".gpa-reset").is_visible(), False)
    p.locator(".gpa-row .gpa-f-name input").first.fill("ENG 101")
    R.check("flow: Start over shows once something is entered", p.locator(".gpa-reset").is_visible(), True)
    p.locator(".gpa-row .gpa-f-name input").first.fill("")
    R.check("flow: Start over hides again when it is all cleared", p.locator(".gpa-reset").is_visible(), False)
    p.click(".gpa-sample")
    R.check("flow: sample result", text(p, ".calc-score"), "3.34")
    R.check("flow: 2 semesters read Cumulative GPA", text(p, ".calc-result .calc-kicker"), "Cumulative GPA")
    R.check("flow: credit stat label (P courses earn credits but aren't in the GPA)", text(p, ".calc-stat:nth-child(1) .calc-stat-k"), "GPA credits")
    R.check("flow: Is my GPA good?", p.locator(".calc-insight").first.inner_text().split("\n"),
            ["Is my GPA good?", "3.34 is above 3.0, the usual minimum for grad school and many scholarships."])
    R.check("flow: sample banner", (text(p, ".calc-banner") or "").startswith("Viewing a sample"), True)
    R.check("flow: sample link hidden while viewing it", p.locator(".gpa-sample").is_visible(), False)
    p.click(".calc-how > summary")
    p.wait_for_timeout(100)
    R.check("flow: worked example rows", p.locator(".calc-how .gpa-ex tbody tr").count(), 8)
    R.check("flow: worked example total", p.locator(".gpa-ex__tile--gpa .gpa-ex__value").inner_text(), "3.34")
    R.check("flow: no GPA chart", p.locator(".calc svg polyline, .gpa-trend").count(), 0)
    R.check("flow: no step bar", p.locator(".calc-steps").count(), 0)
    R.check("flow: planner closed until its button", p.locator(".calc-plan").is_visible(), False)
    R.check("flow: Options closed by default", p.locator(".gpa-options").is_visible(), False)
    R.check("flow: Options shows what's on (Major GPA in the sample)", p.locator(".gpa-options-btn").inner_text().replace("\n", " ").split(" ▾")[0], "Options · 1 on")
    R.check("flow: no goal menu (the planner target is the goal)", p.locator(".calc-goal-add").count() + p.locator("text=Add a goal").count(), 0)
    R.check("flow: no goal line before a target", p.locator(".gpa-goal").is_visible(), False)
    R.check("flow: planner button text", text(p, ".calc-next .calc-btn-primary"), "Plan next semester’s grades")
    # semester Remove sits in a ⋯ menu, with Undo
    R.check("flow: no bare semester Remove button", p.locator(".gpa-term-head > .gpa-del-term").count(), 0)
    p.locator(".gpa-term-more").first.click()
    R.check("flow: ⋯ menu item", text(p, ".gpa-term-menu .calc-menu:not([hidden]) .gpa-del-term"), "Remove Fall")
    p.click(".gpa-term-menu .calc-menu:not([hidden]) .gpa-del-term")
    R.check("flow: semester removed", p.locator(".gpa-term").count(), 1)
    R.check("flow: one semester left reads Semester GPA", text(p, ".calc-result .calc-kicker"), "Semester GPA")
    R.check("flow: remove offers Undo", text(p, ".calc-toast button"), "Undo")
    p.click(".calc-toast button")
    R.check("flow: Undo brings the semester back", p.locator(".gpa-term").count(), 2)
    R.check("flow: Undo restores the GPA", text(p, ".calc-score"), "3.34")
    p.locator(".calc-next .calc-btn-primary").click()
    R.check("flow: planner open", p.locator(".calc-plan").is_visible(), True)
    R.ok("flow: planner sits after the result", p.evaluate("document.querySelector('.calc-result').compareDocumentPosition(document.querySelector('.calc-plan')) & 4") > 0)
    R.check("flow: default target", p.input_value("#college-t-gpa"), "3.50")
    R.check("flow: planner needed", text(p, ".calc-plan-big"), fmt((3.5 * 41 - 86.8) / 15))
    # the slider's chart kit loads on demand, so wait for it rather than counting at once (CI raced here)
    p.locator(".calc-whatif input[type=range]").first.wait_for(timeout=5000)
    R.check("flow: what-if slider", p.locator(".calc-whatif input[type=range]").count(), 1)
    p.locator(".calc-whatif input[type=range]").fill("4")
    R.check("flow: what-if readout", text(p, ".calc-whatif output"), f"A 4.0 average next term → {fmt((86.8 + 60) / 41)} cumulative")

    DIALOG["text"] = "Sample can't save"
    p.click("[aria-label='My saves']")
    p.click(".calc-menu >> text=Save as…")
    R.check("flow: samples can't be saved", "can’t be saved" in (text(p, ".calc-toast") or ""), True)

    p.click(".calc-banner >> text=Clear")
    R.check("flow: sample cleared", p.locator(".calc-result").is_visible(), False)
    row = p.locator(".gpa-row")
    R.check("flow: one blank row to start", row.count(), 1)
    row.nth(0).locator(".gpa-f-grade select").select_option("A")
    row.nth(0).locator(".gpa-f-cr input").fill("4")
    row.nth(1).locator(".gpa-f-grade select").select_option("B-")
    want = fmt((16 + 2.7 * 3) / 7)
    R.check("flow: own result", text(p, ".calc-score"), want)
    R.check("flow: one semester reads Semester GPA", text(p, ".calc-result .calc-kicker"), "Semester GPA")
    R.check("flow: saved-on-device note", text(p, ".calc-saved-note"), "Saved on this device")
    DIALOG["text"] = "Fall test"
    p.click("[aria-label='Save']")
    R.check("flow: saved name shown", text(p, ".calc-save-name"), "Fall test")
    row.nth(2).locator(".gpa-f-grade select").select_option("C")
    R.check("flow: unsaved changes flagged", text(p, ".calc-save-name"), "Fall test · unsaved changes")
    want2 = fmt((16 + 2.7 * 3 + 2 * 3) / 10)
    # the planner's target becomes the saved goal
    p.locator(".calc-next .calc-btn-primary").click()
    p.fill("#college-t-gpa", "3.50")
    p.wait_for_timeout(500)  # autosave debounce

    seen = events(p)
    p.reload()
    p.wait_for_selector(".calc .calc-card")
    R.check("flow: welcome back banner", (text(p, ".calc-banner") or "").startswith("Welcome back — we restored your last calculation"), True)
    R.check("flow: returning visitor sees 'Show an example'", text(p, ".gpa-sample"), "Show an example")
    R.check("flow: restored result", text(p, ".calc-score"), want2)
    R.check("flow: restored save name", text(p, ".calc-save-name"), "Fall test · unsaved changes")
    R.check("flow: saved goal line from the planner target", text(p, ".gpa-goal"), f"Your goal: 3.50. You need a {fmt((3.5 * 25 - 30.1) / 15)} over your next 15 credits.")
    p.click(".gpa-goal")
    R.check("flow: goal line opens the planner", p.locator(".calc-plan").is_visible(), True)
    R.check("flow: planner keeps the goal", p.input_value("#college-t-gpa"), "3.50")
    p.click("[aria-label='My saves']")
    R.check("flow: save listed", p.locator(".calc-menu li", has_text="Fall test").count() >= 1, True)
    p.keyboard.press("Escape")
    p.click("[aria-label='Save']")
    R.check("flow: saved again", text(p, ".calc-save-name"), "Fall test")

    p.click("[aria-label='My saves']")
    p.click(".calc-menu >> text=Copy link")
    link = p.evaluate("navigator.clipboard.readText()")
    R.ok("flow: share link has state", "#gpa=" in link, link)
    p.click("[aria-label='My saves']")
    p.click(".calc-menu >> text=Copy summary")
    summary = p.evaluate("navigator.clipboard.readText()")
    R.ok("flow: summary text", summary.startswith(f"My College GPA: {want2}"), summary)
    with p.expect_download() as d:
        p.click("[aria-label='My saves']")
        p.click(".calc-menu >> text=Download CSV")
    csv = Path(d.value.path()).read_text()
    R.ok("flow: CSV has the courses and GPA", "Cumulative GPA" in csv and want2 in csv and csv.count("\n") >= 6, csv[:200])

    # share link in a fresh browser
    c2 = ctx_for(s)
    p2 = c2.new_page()
    p2.on("pageerror", lambda e: s.errors.append(f"pageerror: {e}"))
    p2.on("console", lambda m: m.type == "error" and s.errors.append(f"console: {m.text}"))
    p2.goto(link.replace("http://127.0.0.1", "http://127.0.0.1"))
    p2.wait_for_selector(".calc .calc-card")
    R.check("flow: shared link result", text(p2, ".calc-score"), want2)
    R.check("flow: shared banner", (text(p2, ".calc-banner") or "").startswith("Viewing a shared calculation"), True)
    R.check("flow: shared view writes no draft", p2.evaluate("localStorage.getItem('gpac:college:v1.draft')"), None)
    R.check("flow: shared GA4 event", "col_open_link" in events(p2), True)
    c2.close()

    # reset + undo
    p.click("text=Start over")
    R.check("flow: reset clears result", p.locator(".calc-result").is_visible(), False)
    R.check("flow: undo offered", text(p, ".calc-toast button"), "Undo")
    p.click(".calc-toast button")
    R.check("flow: undo restores", text(p, ".calc-score"), want2)
    R.check("flow: undo restores save name", text(p, ".calc-save-name"), "Fall test")

    ev = seen + events(p)
    for name in ("col_sample", "col_result", "col_step_2", "col_plan", "col_how", "col_save", "col_share", "col_reset", "gpa_export"):
        R.check(f"flow: GA4 {name}", name in ev, True)
    R.check("flow: calculator_used left to the theme inside #root", "calculator_used" in ev, False)
    p.close()
    c.close()

    # Old College/homepage saves copied once, old keys untouched
    old_draft = {"calculatorMode": "college", "gpaScale": 4, "semesters": [{"id": "1", "name": "Fall 2025", "courses": [
        {"id": "a", "name": "BIO 1", "grade": "A-", "credits": 4, "isMajor": True, "courseType": ""},
        {"id": "b", "name": "ENG 1", "grade": "B", "credits": 3, "isMajor": False, "courseType": ""}]}]}
    old_saved = {"version": 1, "calculations": [
        {"id": "9", "name": "Freshman year", "calculatorMode": "college", "semesters": [{"name": "Fall", "courses": [
            {"name": "X", "grade": "C+", "credits": 3, "isMajor": False}]}]},
        {"id": "8", "name": "HS stuff", "calculatorMode": "highschool", "semesters": [{"name": "9th", "courses": [
            {"name": "Y", "grade": "A", "credits": 1, "courseType": "honors"}]}]}]}
    init = (f"if(!sessionStorage.getItem('seeded')){{sessionStorage.setItem('seeded','1');"
            f"localStorage.setItem('gpa_calc_draft_v1',{json.dumps(json.dumps(old_draft))});"
            f"localStorage.setItem('gpa_calc_saved_v1',{json.dumps(json.dumps(old_saved))});}}")
    c = ctx_for(s, init=init)
    p = open_page(s, c, "college-gpa-calculator")
    R.check("legacy: old draft restored", text(p, ".calc-score"), fmt((3.7 * 4 + 9) / 7))
    R.check("legacy: welcome back", "Welcome back" in (text(p, ".calc-banner") or ""), True)
    R.check("legacy: Major kept", p.locator(".gpa-major input").first.is_checked(), True)
    p.click("[aria-label='My saves']")
    R.check("legacy: college save copied", p.locator(".calc-menu li", has_text="Freshman year").count(), 1)
    R.check("legacy: high school save not copied", p.locator(".calc-menu li", has_text="HS stuff").count(), 0)
    R.check("legacy: old draft untouched", json.loads(p.evaluate("localStorage.getItem('gpa_calc_draft_v1')")), old_draft)
    R.check("legacy: old saves untouched", json.loads(p.evaluate("localStorage.getItem('gpa_calc_saved_v1')")), old_saved)
    p.keyboard.press("Escape")
    p.click(".calc-banner >> text=Start fresh")
    p.click(".calc-toast button") if False else None
    p.reload()
    p.wait_for_selector(".calc .calc-card")
    R.check("legacy: copied only once", p.locator(".calc-result").is_visible(), False)
    p.close()
    c.close()

    # UCLA: old university draft copied; calculator_used fired by the core (outside #root)
    old_uni = {"activeRecord": "UCLA undergraduate", "records": {"UCLA undergraduate": {
        "previousGpa": "3.5", "previousUnits": "45",
        "completed": [{"name": "LS 7A", "units": "5", "grade": "B+", "special": "UCLA course"},
                      {"name": "Transfer", "units": "4", "grade": "A", "special": "Non-UC transfer / other institution"}],
        "planned": []}}}
    init = f"if(!localStorage.getItem('x')){{localStorage.setItem('x','1');localStorage.setItem('top-uni-gpa-calculator-v3-ucla',{json.dumps(json.dumps(old_uni))});}}"
    c = ctx_for(s, init=init)
    p = open_page(s, c, "ucla-gpa-calculator")
    R.check("legacy UCLA: old draft restored", text(p, ".calc-score"), fmt((3.5 * 45 + 3.3 * 5) / 50, 3))
    R.check("legacy UCLA: transfer not counted", p.locator(".calc-row-msg", has_text="Not counted").count(), 1)
    p.locator(".gpa-row").first.locator(".gpa-f-grade select").select_option("A")
    R.check("UCLA: calculator_used once", events(p).count("calculator_used"), 1)
    R.check("UCLA: GA4 uni_result", "uni_result" in events(p), True)
    p.close()
    c.close()


# ---------- 3. Layout + screenshots ----------

SIZES = ((390, 844, True), (768, 1024, False), (1366, 768, False), (1440, 900, False))


def pill_state(p):
    return p.evaluate("(() => { const b = document.querySelector('.calc-pill'); const r = b.getBoundingClientRect(); return [b.classList.contains('is-on'), b.innerText.replace(/\\s+/g, ' ').trim(), Math.round(innerHeight - r.bottom)]; })()")


def layout(s, shots):
    SHOTS.mkdir(parents=True, exist_ok=True)
    print("  above the fold (card top / first course field / result visible with 4 courses):")
    for name in ("college-gpa-calculator", "ucla-gpa-calculator"):
        for w, hgt, mobile in SIZES:
            c = ctx_for(s, w, hgt, mobile=mobile)
            p = open_page(s, c, name)
            host = p.locator("#root, .gpcm-host").first
            top = p.evaluate("Math.round(document.querySelector('.calc-card').getBoundingClientRect().top + scrollY)")
            first = p.locator(".gpa-row .gpa-f-name input").first.bounding_box()
            if name.startswith("college") and mobile:
                # Nothing between the card header and the first course field; it is on the first screen.
                between = p.evaluate("""(() => { const card = document.querySelector('.calc-card');
                    const head = card.querySelector('.gpa-top').getBoundingClientRect();
                    const f = card.querySelector('.gpa-row .gpa-f-name input').getBoundingClientRect();
                    return [...card.querySelectorAll('*')].filter((e) => { const r = e.getBoundingClientRect();
                      return r.height > 0 && r.top >= head.bottom - 0.5 && r.bottom <= f.top + 0.5 && !e.closest('.gpa-row') && getComputedStyle(e).visibility !== 'hidden'; })
                      .map((e) => e.className || e.tagName); })()""")
                R.check(f"first screen {name} {w}: nothing between the card header and the first course field", between, [])
                R.ok(f"first screen {name} {w}: first course field on the first screen", first["y"] + first["height"] <= hgt, first)
                R.check(f"first screen {name} {w}: one blank row", p.locator(".gpa-row").count(), 1)
                R.check(f"first screen {name} {w}: Options closed", p.locator(".gpa-options").is_visible(), False)
            if shots:
                host.screenshot(path=str(SHOTS / f"{name}-{w}x{hgt}-1-empty.png"))
            # mid-typing: own grades, scrolled so the result is below the screen
            rows = p.locator(".gpa-term").last.locator(".gpa-row")
            for i, (g, cr) in enumerate((("A", "4"), ("B+", "3"), ("A-", "3"), ("B", "4"))):
                if i >= rows.count():
                    p.locator(".gpa-add-row").first.click()
                pick_grade(p, rows.nth(i), g)
                set_credits(p, rows.nth(i), cr)
                if mobile:
                    rows.nth(i + 1).locator(".gpa-f-name input").focus() if i + 1 < rows.count() else None
            if mobile and name.startswith("college"):
                R.check(f"rows {name} {w}: finished rows collapse to one line", [p.locator(".gpa-row.is-collapsed .gpa-row-sum-t").first.inner_text(), p.locator(".gpa-row.is-collapsed .gpa-row-sum-m").first.inner_text()], ["Course 1", "A · 4 cr"])
                p.locator(".gpa-row.is-collapsed .gpa-row-sum").first.click()
                R.check(f"rows {name} {w}: tap opens it again", p.locator(".gpa-row").first.locator(".gpa-f-name input").is_visible(), True)
                rows.last.locator(".gpa-f-name input").focus()
            p.evaluate("scrollTo(0, 0)")
            p.wait_for_timeout(250)
            visible = p.evaluate("(() => { const r = document.querySelector('.calc-result').getBoundingClientRect(); return r.top < innerHeight && r.bottom > 0; })()")
            below = p.evaluate("document.querySelector('.calc-result').getBoundingClientRect().top >= innerHeight")
            p.evaluate("document.activeElement && document.activeElement.blur()")
            p.wait_for_timeout(150)
            on, txt, gap = pill_state(p)
            R.ok(f"pill {name} {w}: shows only while the result is below the screen", on == below, f"pill {on}, result below {below}")
            print(f"    {name} {w}x{hgt}: card top {top}px; first field {round(first['y'])}px; result on first screen with 4 courses: {visible}; pill: {txt if on else 'hidden'}")
            if on:
                R.ok(f"pill {name} {w}: text", txt.startswith("GPA ") and "Details" in txt, txt)
                R.ok(f"pill {name} {w}: 12px above the screen bottom (no ad here)", abs(gap - 12) <= 1, f"gap {gap}")
                rows.last.locator(".gpa-f-name input").focus()
                p.wait_for_timeout(200)
                R.check(f"pill {name} {w}: hides while typing a course name", pill_state(p)[0], False)
                p.evaluate("document.activeElement.blur()")
                if shots:
                    p.screenshot(path=str(SHOTS / f"{name}-{w}x{hgt}-2-typing-pill.png"))
                p.click(".calc-pill")
                p.wait_for_timeout(400)
                R.check(f"pill {name} {w}: tap scrolls to the result and hides", pill_state(p)[0], False)
                p.evaluate("window.scrollBy(0, document.querySelector('.calc-result').getBoundingClientRect().bottom + 400)")
                p.wait_for_timeout(250)
                R.check(f"pill {name} {w}: stays hidden once scrolled past the result", pill_state(p)[0], False)
            # full result: the sample
            p.evaluate("localStorage.clear()")
            p.reload()
            p.wait_for_selector(".calc .calc-card")
            p.click(".gpa-sample")
            p.wait_for_timeout(300)
            sw = p.evaluate("document.documentElement.scrollWidth")
            R.ok(f"layout {name} {w}: no sideways scroll", sw <= w, f"scrollWidth {sw}")
            box = p.locator(".calc-card").bounding_box()
            col = p.evaluate("(() => { const r = document.querySelector('.entry-content').getBoundingClientRect(); return [r.left, r.width]; })()")
            if mobile:
                R.ok(f"layout {name} {w}: 14px from the screen edge", abs(box["x"] - 14) <= 1, f"x {box['x']}")
                if name.startswith("college"):
                    kg = p.locator(".calc-keep").bounding_box()
                    R.ok(f"layout {name} {w}: Keep going 20px from the screen edge, like the text", abs(kg["x"] - 20) <= 1 and abs(w - kg["x"] - kg["width"] - 20) <= 1, kg)
                    R.check(f"layout {name} {w}: earlier semester collapses", p.locator(".gpa-term.is-collapsed .gpa-term-sum").first.inner_text(), "Fall · 4 classes · GPA 3.41")
                    R.check(f"layout {name} {w}: sample rows collapse", p.locator(".gpa-term").last.locator(".gpa-row.is-collapsed").count(), 4)
            elif w >= 1260:
                R.ok(f"layout {name} {w}: as wide as the column (677-800)", 677 <= box["width"] <= 800, f"width {box['width']}")
            if not mobile:
                R.check(f"layout {name} {w}: rows never collapse on desktop", p.locator(".gpa-row.is-collapsed .gpa-f-name input:visible").count() == p.locator(".gpa-row.is-collapsed").count(), True)
            R.ok(f"layout {name} {w}: inside the column", box["x"] >= col[0] - 15 and box["x"] + box["width"] <= col[0] + col[1] + 15, f"{box} {col}")
            font = p.evaluate("getComputedStyle(document.querySelector('.calc')).fontFamily")
            R.ok(f"layout {name} {w}: Lexend", font.split(",")[0].strip("'\" ") == "Lexend", font)
            # Course-row rules (Calculator Design Standard), on the last open row (opening one if all are collapsed)
            if not p.locator(".gpa-row:not(.is-collapsed) .gpa-f-name input:visible").count():
                p.locator(".gpa-row-sum:visible").last.click()
            rr = p.evaluate("""(() => { const row = [...document.querySelectorAll('.gpa-row:not(.is-collapsed)')].filter((r) => r.getBoundingClientRect().height > 0).pop();
                const vis = (e) => e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0;
                const pick = (sel) => [...row.querySelectorAll(sel)].find(vis);
                const box = (e) => e.getBoundingClientRect();
                const n = box(pick('.gpa-f-name')), g = box(pick('.gpa-f-grade')), c = box(pick('.gpa-f-cr')), x = box(pick('.gpa-rm'));
                const gc = pick('.gpa-f-grade :is(select, .gpa-grade-btn)'), cc = pick('.gpa-f-cr :is(input, .gpa-cr-btn)');
                const sel = row.querySelector('.gpa-f-grade select'), cr = row.querySelector('.gpa-f-cr input');
                const head = [...document.querySelectorAll('.calc-row-head span')].map((e) => e.textContent).filter(Boolean);
                return { n: [n.left, n.top, n.width], g: [g.left, g.top, g.width], c: [c.left, c.top, c.width], x: [x.left, x.top],
                         align: [getComputedStyle(gc).textAlign, getComputedStyle(cc).textAlign], ph: sel.options[0].textContent,
                         btn: gc.tagName, im: cr.inputMode, head }; })()""")
            R.check(f"rows {name} {w}: grade and credits centered", rr["align"], ["center", "center"])
            R.check(f"rows {name} {w}: grade placeholder", rr["ph"], "Grade")
            R.check(f"rows {name} {w}: credits decimal keypad", rr["im"], "decimal")
            R.check(f"rows {name} {w}: column labels", rr["head"], ["Course (optional)", "Grade", "Credits"] + (["Type"] if "ucla" in name else []) + (["Major"] if "college" in name else []))
            R.check(f"rows {name} {w}: grade control", rr["btn"], "BUTTON" if mobile else "SELECT")
            if mobile:
                R.ok(f"rows {name} {w}: line 1 is the name + remove", abs(rr["n"][1] - rr["x"][1]) <= 2 and rr["x"][0] > rr["n"][0] + rr["n"][2] - 1, rr)
                R.ok(f"rows {name} {w}: line 2 grade ~120 + credits ~104, left-aligned", rr["g"][1] > rr["n"][1] + 20 and abs(rr["g"][1] - rr["c"][1]) <= 2
                     and abs(rr["g"][0] - rr["n"][0]) <= 1 and 110 <= rr["g"][2] <= 130 and 94 <= rr["c"][2] <= 114, rr)
            else:
                R.ok(f"rows {name} {w}: one line", abs(rr["n"][1] - rr["g"][1]) <= 2 and abs(rr["g"][1] - rr["c"][1]) <= 2, rr)
            long_name = p.locator(".gpa-row .gpa-f-name input:visible").last
            long_name.fill("Introduction to Organic Chemistry")
            ov = long_name.evaluate("e => [getComputedStyle(e).textOverflow, getComputedStyle(e).whiteSpace]")
            R.check(f"layout {name} {w}: long course names truncate with an ellipsis", ov[0], "ellipsis")
            long_name.fill("")
            if shots:
                host.screenshot(path=str(SHOTS / f"{name}-{w}x{hgt}-3-sample.png"))
                if name.startswith("college"):
                    p.locator(".calc-next .calc-btn-primary").click()
                    p.wait_for_timeout(300)
                host.screenshot(path=str(SHOTS / f"{name}-{w}x{hgt}-4-result.png"))
            p.close()
            c.close()

    # Phone entry: the letter grid and the credit quick buttons
    c = ctx_for(s, 390, 844, mobile=True)
    p = open_page(s, c, "college-gpa-calculator")
    row = p.locator(".gpa-row").first
    row.locator(".gpa-grade-btn").click()
    R.check("phone: grade sheet letters", p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").all_inner_texts(),
            ["A+", "A", "A\u2212", "B+", "B", "B\u2212", "C+", "C", "C\u2212", "D+", "D", "D\u2212", "F", "P", "NP", "W"])
    R.check("phone: grade sheet 'Not counted in GPA' group before P, NP, W", p.evaluate("(() => { const g = document.querySelector('.calc-sheet-wrap:not([hidden]) .calc-sheet-group'); return g && [g.textContent, g.nextElementSibling.textContent]; })()"), ["Not counted in GPA", "P"])
    p.keyboard.press("Escape")
    R.check("phone: Escape closes the sheet", p.locator(".calc-sheet-wrap").is_visible(), False)
    row.locator(".gpa-cr-btn").click()
    R.check("phone: credit quick buttons", p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").all_inner_texts(), ["1", "2", "3", "4", "5", "Other"])
    p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt", has_text="Other").click()
    R.check("phone: Other opens the credits field", row.locator(".gpa-f-cr input").is_visible(), True)
    row.locator(".gpa-f-cr input").fill("3.5")
    pick_grade(p, row, "B+")
    R.check("phone: grade picked from the sheet counts", text(p, ".calc-score"), "3.30")
    R.check("phone: next row added", p.locator(".gpa-row").count(), 2)
    # Chevron buttons on the phone grade and credits buttons show they open a picker
    R.check("phone: grade and credits show a chevron", p.evaluate("""(() => ['.gpa-f-grade', '.gpa-f-cr'].map((f) => getComputedStyle(document.querySelectorAll('.gpa-row')[1].querySelector(f), '::after').display))()"""), ["block", "block"])
    # The first tap on the next row's Grade opens the sheet (the finished row collapses only after the tap lands)
    gb = p.locator(".gpa-row").nth(1).locator(".gpa-grade-btn")
    gb.scroll_into_view_if_needed()
    bb = gb.bounding_box()
    p.touchscreen.tap(bb["x"] + bb["width"] / 2, bb["y"] + bb["height"] / 2)
    p.wait_for_timeout(300)
    R.check("phone: first tap on the next row's Grade opens the sheet", p.locator(".calc-sheet-wrap:not([hidden])").count(), 1)
    p.keyboard.press("Escape")
    # The sheet sits above the sticky ad and the floating video player stacked on it
    p.evaluate("""(() => { const a = document.createElement('div'); a.id = 'fs-sticky-footer'; a.style.cssText = 'position:fixed;left:0;right:0;bottom:0;height:60px;z-index:2147483647';
        const v = document.createElement('div'); v.className = 'qa-video'; v.style.cssText = 'position:fixed;right:8px;bottom:68px;width:224px;height:126px;z-index:2147483647'; document.body.append(a, v); })()""")
    gb.click()
    lift = p.evaluate("(() => [document.querySelector('.calc-sheet-wrap:not([hidden]) .calc-sheet').getBoundingClientRect().bottom, document.querySelector('.qa-video').getBoundingClientRect().top])()")
    R.ok("phone: grade sheet sits above the sticky ad and video", lift[0] <= lift[1] + 1, lift)
    p.keyboard.press("Escape")
    p.evaluate("document.querySelectorAll('#fs-sticky-footer, .qa-video').forEach((e) => e.remove())")
    row.locator(".gpa-row-sum").click()  # back to editing the first course
    p.wait_for_timeout(100)
    # A long course name truncates in the collapsed line; grade and credits always show at the right
    row.locator(".gpa-f-name input").fill("Introduction to Organic Chemistry Laboratory")
    p.locator(".gpa-row").nth(1).locator(".gpa-f-name input").focus()
    sm = p.evaluate("""(() => { const s = document.querySelector('.gpa-row.is-collapsed .gpa-row-sum'); const t = s.querySelector('.gpa-row-sum-t'), m = s.querySelector('.gpa-row-sum-m'), e = s.querySelector('.gpa-row-sum-e');
        const sb = s.getBoundingClientRect(), mb = m.getBoundingClientRect(), eb = e.getBoundingClientRect();
        return { cut: t.scrollWidth > t.clientWidth, meta: m.textContent, whole: m.scrollWidth <= m.clientWidth + 0.5, right: sb.right - eb.right < 1 && eb.left - mb.right < 16, one: sb.height < 60 }; })()""")
    R.check("phone: collapsed long name truncates, grade/credits whole at the right", sm, {"cut": True, "meta": "B+ · 3.5 cr", "whole": True, "right": True, "one": True})
    pick_grade(p, p.locator(".gpa-row").nth(1), "P")
    p.locator(".gpa-row").nth(2).locator(".gpa-f-name input").focus()
    R.check("phone: P row collapses as not counted", p.locator(".gpa-row.is-collapsed .gpa-row-sum-m").nth(1).inner_text(), "P · not counted")
    R.check("phone: P leaves the GPA alone", text(p, ".calc-score"), "3.30")
    p.close()
    c.close()

    # 375px phones: the planner button stays on one line
    c = ctx_for(s, 375, 667, mobile=True)
    p = open_page(s, c, "college-gpa-calculator")
    p.click(".gpa-sample")
    lines = p.evaluate("(() => { const b = document.querySelector('.calc-next .calc-btn-primary'); const r = document.createRange(); r.selectNodeContents(b); return new Set([...r.getClientRects()].map((x) => Math.round(x.top))).size; })()")
    R.check("phone 375: planner button on one line", lines, 1)
    p.close()
    c.close()


# ---------- 4. Re-color ----------

def recolor(s):
    c = ctx_for(s)
    p = open_page(s, c, "college-gpa-calculator")
    p.click(".gpa-sample")
    probe = """() => {
      const cta = getComputedStyle(document.querySelector('.calc-next .calc-btn-primary')).backgroundImage;
      const link = getComputedStyle(document.querySelector('.calc-btn-text')).color;
      return [cta, link];
    }"""
    before = p.evaluate(probe)
    p.add_style_tag(content=":root{--gpa-blue-600: rgb(15, 118, 110) !important;}")
    after = p.evaluate(probe)
    R.ok("re-color: CTA follows the palette", "rgb(15, 118, 110)" in after[0] and after[0] != before[0], f"{before[0]} -> {after[0]}")
    p.close()
    c.close()


# ---------- 5. Color literals + sizes ----------

NEW_FILES = ["core/calc-core.css", "core/calc-core.js", "core/chart-kit.js", "engines/gpa-engine.js", "gpa/gpa-app.js",
             "gpa/gpa-app.css", "gpa/college-gpa.js", "gpa/uni-gpa.js", "gpa/home-gpa.js", "profiles/college.js", "profiles/from-gpcm.js",
             "profiles/high-school.js", "profiles/hs-courses.js", "profiles/home.js", "gpa/home-v2.js", "gpa/home-v2.css"]
NAMED = r"\b(white|black|red|green|blue|gray|grey|silver|navy|purple|orange|yellow|pink|teal)\b"


def literals():
    for f in NEW_FILES:
        src = (CALC_ASSETS / f).read_text()
        hexes = re.findall(r"(?<![\w&])#[0-9a-fA-F]{3,8}\b", src)
        rgbs = re.findall(r"\b(?:rgba?|hsla?)\(", src)
        named = []
        if f.endswith(".css"):
            body = re.sub(r"var\([^)]*\)", "", re.sub(r"/\*.*?\*/", "", src, flags=re.S))
            named = [m for d in re.findall(r":\s*([^;{}]+);", body) for m in re.findall(NAMED, d)]
        R.check(f"no color literals in {f}", hexes + rgbs + named, [])


def import_tags():
    """Every import between the modules carries the folder's current ?v= tag (scripts/calc/stamp_imports.py),
    so a deploy never mixes a new file with an old cached one."""
    sys.path.insert(0, str(REPO / "scripts/calc"))
    import stamp_imports
    tag, files = stamp_imports.stale()
    R.check(f"imports carry the current ?v={tag}", [str(p.relative_to(CALC_ASSETS)) for p in files], [])
    for f in ("gpa/college-gpa.js", "gpa/gpa-app.js", "gpa/home-gpa.js", "gpa/uni-gpa.js"):
        bare = re.findall(r"from\s+'(\.{1,2}/[^'?]+\.js)'", (CALC_ASSETS / f).read_text())
        R.check(f"no untagged import in {f}", bare, [])


def sizes():
    exe = REPO / "node_modules/.bin/esbuild"
    out = {}
    def gz(entry, external=()):
        cmd = [str(exe), str(CALC_ASSETS / entry), "--bundle", "--format=esm", "--minify"] + [f"--external:{x}*" for x in external]  # imports carry ?v=<tag>
        return len(gzip.compress(subprocess.run(cmd, capture_output=True, check=True).stdout))
    out["core JS (calc-core.js)"] = gz("core/calc-core.js")
    out["core CSS"] = len(gzip.compress((CALC_ASSETS / "core/calc-core.css").read_bytes()))
    out["GPA screen + engine (gpa-app.js, gpa-engine.js)"] = gz("gpa/gpa-app.js", ["../core/calc-core.js", "../core/chart-kit.js"])
    out["College profile + entry"] = gz("gpa/college-gpa.js", ["./gpa-app.js", "../core/calc-core.js"])
    out["chart kit (loaded only when a chart shows)"] = gz("core/chart-kit.js")
    out["GPA CSS"] = len(gzip.compress((CALC_ASSETS / "gpa/gpa-app.css").read_bytes()))
    for k, v in out.items():
        print(f"  size {k}: {v / 1024:.1f} KB gzipped")
    R.ok("size: core JS+CSS <= 25 KB gz", out["core JS (calc-core.js)"] + out["core CSS"] <= 25 * 1024)
    R.ok("size: one calculator's own code (profile + entry) <= 10 KB gz", out["College profile + entry"] <= 10 * 1024)
    total = sum(v for k, v in out.items() if not k.startswith("chart kit"))
    old = sum(len(gzip.compress((CALC_ASSETS / f).read_bytes())) for f in ("college-gpa-calculator.js", "college-gpa-calculator.css"))
    print(f"  size College page total (core + GPA engine + profile + CSS): {total / 1024:.1f} KB gzipped; old Bolt bundle {old / 1024:.1f} KB")
    R.ok("size: College page total <= 40 KB gz and smaller than the old bundle", total <= 40 * 1024 and total < old)
    return out


# ---------- 5. Add to home screen (phones) ----------

IPHONE_UA = "Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1"
ANDROID_UA = "Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36"
# Chrome fires beforeinstallprompt once a page qualifies; the test stands in for it.
FAKE_INSTALL = ("window.__prompted=0;window.__fireInstall=()=>{const e=new Event('beforeinstallprompt');"
                "e.prompt=()=>{window.__prompted++;return Promise.resolve();};e.userChoice=Promise.resolve({outcome:'dismissed'});"
                "window.dispatchEvent(e);};")


def phone_ctx(s, ua, w=390, hgt=844):
    global FONT_CSS
    FONT_CSS = FONT_CSS or lexend_css()
    c = s.browser.new_context(viewport={"width": w, "height": hgt}, device_scale_factor=2, is_mobile=True, has_touch=True,
                              reduced_motion="reduce", user_agent=ua)
    c.route("**/fonts.googleapis.com/**", lambda r: r.fulfill(status=200, content_type="text/css", body=FONT_CSS))
    c.route("**/gpacalculator.net/**", lambda r: r.fulfill(status=204, body=""))
    c.add_init_script(FAKE_INSTALL)
    return c


def calc_once(p, g):
    row = p.locator(".gpa-row").first
    if row.locator(".gpa-row-sum").is_visible():
        row.locator(".gpa-row-sum").click()  # phones: a finished row is one line until tapped
    pick_grade(p, row, g)
    p.wait_for_timeout(1800)  # a calculation counts once its inputs settle (1.5s)


def a2hs_names(p):
    return [e[1] for e in p.evaluate("window.__events") if e and e[0] == "event" and str(e[1]).startswith("a2hs_")]


def a2hs(s, shots):
    bar = ".calc-a2hs"
    # Android / Chrome
    c = phone_ctx(s, ANDROID_UA)
    p = open_page(s, c, "college-gpa-calculator")
    p.evaluate("window.__fireInstall()")
    R.check("a2hs: hidden on first load", p.locator(bar).is_visible(), False)
    calc_once(p, "A")
    R.check("a2hs: hidden after one calculation", p.locator(bar).is_visible(), False)
    p.reload(); p.wait_for_selector(".calc .calc-card"); p.evaluate("window.__fireInstall()"); p.wait_for_timeout(1800)
    R.check("a2hs: a restored calculation isn't a new one", p.locator(bar).is_visible(), False)
    calc_once(p, "B+")
    R.check("a2hs: shown after the second calculation", p.locator(bar).is_visible(), True)
    geo = p.evaluate("""(() => { const b = document.querySelector('.calc-a2hs'), card = document.querySelector('.calc-card');
        const bb = b.getBoundingClientRect(), cb = card.getBoundingClientRect();
        return { next: card.nextElementSibling === b, inside: card.contains(b), pos: getComputedStyle(b).position,
                 gap: Math.round(bb.top - cb.bottom), w: Math.round(bb.width) === Math.round(cb.width) }; })()""")
    R.check("a2hs: in the flow right under the card, card width, not fixed", geo, {"next": True, "inside": False, "pos": "static", "gap": 16, "w": True})
    R.check("a2hs: copy", [t for t in p.locator(bar + " .calc-a2hs-text").inner_text().split("\n") if t.strip()], ["Use this calculator again?", "Add it to your home screen."])
    R.check("a2hs: Android has the Add button", p.locator(bar + " .calc-a2hs-add").inner_text(), "Add")
    p.locator(bar).scroll_into_view_if_needed()
    p.wait_for_timeout(300)
    if shots:
        p.evaluate("window.scrollTo(0, document.querySelector('.calc-a2hs').getBoundingClientRect().bottom + scrollY - innerHeight + 140)")
        p.wait_for_timeout(300)
        p.screenshot(path=str(SHOTS / "a2hs-android-390x844.png"))
    over = p.evaluate("""(() => { const a = document.querySelector('.calc-a2hs').getBoundingClientRect();
        return [...document.querySelectorAll('.calc-pill.is-on, [id*="sticky"], .calc-sheet-wrap:not([hidden])')].filter((e) => {
          const r = e.getBoundingClientRect(); return r.height > 0 && r.left < a.right && r.right > a.left && r.top < a.bottom && r.bottom > a.top; }).length; })()""")
    R.check("a2hs: overlaps nothing fixed (pill, sticky ad, sheet)", over, 0)
    p.click(bar + " .calc-a2hs-add")
    R.check("a2hs: Add opens the browser's install prompt", p.evaluate("window.__prompted"), 1)
    p.click(bar + " .calc-a2hs-x")
    R.check("a2hs: × hides it", p.locator(bar).is_visible(), False)
    R.ok("a2hs: dismissal remembered", (p.evaluate("Number(localStorage.getItem('gpac:a2hs:dismissed'))") or 0) > 0)
    R.check("a2hs: GA4 events", a2hs_names(p), ["a2hs_shown", "a2hs_add_click", "a2hs_dismiss"])
    p.reload(); p.wait_for_selector(".calc .calc-card"); p.evaluate("window.__fireInstall()")
    calc_once(p, "C"); calc_once(p, "D")
    R.check("a2hs: stays hidden for 30 days after ×", p.locator(bar).is_visible(), False)
    p.evaluate("localStorage.setItem('gpac:a2hs:dismissed', String(Date.now() - 31 * 864e5))")
    calc_once(p, "B")
    R.check("a2hs: back after 30 days", p.locator(bar).is_visible(), True)
    p.close(); c.close()

    # No install offer from the browser: no bar. Save shows it at once. Home-screen launch: never, and counted.
    c = phone_ctx(s, ANDROID_UA)
    p = open_page(s, c, "college-gpa-calculator")
    calc_once(p, "A"); calc_once(p, "B")
    R.check("a2hs: no bar when the browser never offers install", p.locator(bar).is_visible(), False)
    p.close(); c.close()
    c = phone_ctx(s, ANDROID_UA)
    p = open_page(s, c, "college-gpa-calculator")
    p.evaluate("window.__fireInstall()")
    calc_once(p, "A")
    DIALOG["text"] = "Fall"
    p.click("[aria-label='Save']")
    R.check("a2hs: shown after Save", p.locator(bar).is_visible(), True)
    p.goto(p.url.split("?")[0] + "?source=homescreen"); p.wait_for_selector(".calc .calc-card"); p.evaluate("window.__fireInstall()")
    calc_once(p, "B"); calc_once(p, "C")
    R.check("a2hs: hidden when opened from the home screen", p.locator(bar).is_visible(), False)
    R.check("a2hs: home-screen open counted", "a2hs_open" in a2hs_names(p), True)
    p.close(); c.close()

    # Desktop: never
    c = ctx_for(s)
    c.add_init_script(FAKE_INSTALL)
    p = open_page(s, c, "college-gpa-calculator")
    p.evaluate("window.__fireInstall()")
    calc_once(p, "A"); calc_once(p, "B")
    R.check("a2hs: never on desktop", p.locator(bar).is_visible(), False)
    p.close(); c.close()

    # iPhone: Share-sheet line, no button
    c = phone_ctx(s, IPHONE_UA)
    p = open_page(s, c, "college-gpa-calculator")
    calc_once(p, "A")
    R.check("a2hs iOS: hidden after one calculation", p.locator(bar).is_visible(), False)
    calc_once(p, "B+")
    R.check("a2hs iOS: shown after the second", p.locator(bar).is_visible(), True)
    R.check("a2hs iOS: Share instructions", p.locator(bar + " .calc-a2hs-how").inner_text().replace("\n", " ").replace("  ", " "), "Tap Share, then Add to Home Screen")
    R.check("a2hs iOS: share icon", p.locator(bar + " .calc-a2hs-how svg").count(), 1)
    R.check("a2hs iOS: no Add button", p.locator(bar + " .calc-a2hs-add").count(), 0)
    R.check("a2hs iOS: badge icon loads", p.evaluate("(() => { const i = document.querySelector('.calc-a2hs-icon'); return !!i && i.complete && i.naturalWidth > 0; })()"), True)
    if shots:
        p.evaluate("window.scrollTo(0, document.querySelector('.calc-a2hs').getBoundingClientRect().bottom + scrollY - innerHeight + 140)")
        p.wait_for_timeout(300)
        p.screenshot(path=str(SHOTS / "a2hs-ios-390x844.png"))
    p.close(); c.close()


# ---------- 7. Homepage: School level switch (College | High School), levels, weighted GPA ----------

HS_PTS = {"A+": 4, "A": 4, "A-": 3.7, "B+": 3.3, "B": 3, "B-": 2.7, "C+": 2.3, "C": 2, "C-": 1.7, "D+": 1.3, "D": 1, "D-": 0.7, "F": 0}
HS_BOOST = {"reg": 0, "hon": 0.5, "ap": 1, "ib": 1, "de": 1}
HS_DRAFT = {"calculatorMode": "highschool", "gpaScale": 4, "isWeighted": True, "useEqualCredits": True, "showCredits": False,
            "semesters": [{"id": 1, "name": "Fall 2025", "courses": [
                {"id": 1, "name": "AP Biology", "grade": "A-", "credits": 1, "courseType": "AP"},
                {"id": 2, "name": "English 10", "grade": "B+", "credits": 1, "courseType": "Regular"},
                {"id": 3, "name": "Chemistry", "grade": "A", "credits": 1, "courseType": "Honors", "typeManuallySet": True}]}],
            "currentSemesterId": 1}
COL_DRAFT = {"calculatorMode": "college", "semesters": [{"id": 1, "name": "Fall 2025", "courses": [
    {"id": 1, "name": "BIO 110", "grade": "B", "credits": 4}, {"id": 2, "name": "ENG 101", "grade": "A", "credits": 3}]}]}


def hs_expect(rows):
    c = sum(r[2] for r in rows)
    u = sum(HS_PTS[r[0]] * r[2] for r in rows)
    w = sum((HS_PTS[r[0]] + (HS_BOOST[r[1]] if HS_PTS[r[0]] > 0 else 0)) * r[2] for r in rows)
    return fmt(u / c), fmt(w / c), any(HS_BOOST[r[1]] and HS_PTS[r[0]] > 0 for r in rows)


def home_pane(p):
    return p.locator(".gpa-home-pane:not([hidden])")


def home_reset(p, mode=None, draft=None):
    """Clear storage (twice: leaving the page flushes the pending autosave), then seed it and reload."""
    for _ in range(2):
        p.evaluate("localStorage.clear()")
        p.reload()
        p.wait_for_selector(".gpa-home-switch")
    p.evaluate("""([m, d]) => { localStorage.clear(); if (m) localStorage.setItem('gpac:home:mode', m); if (d) localStorage.setItem('gpa_calc_draft_v1', JSON.stringify(d)); }""", [mode, draft])
    p.goto(p.url)
    p.wait_for_selector(".gpa-home-switch")
    return home_pane(p)


def home(s, shots):
    c = ctx_for(s)
    p = open_page(s, c, "gpa-calculator")
    fresh(p)
    R.check("home: opens on College", p.locator(".calc-seg-btn[aria-checked='true']").inner_text(), "College")
    R.check("home: one visible screen", p.locator(".gpa-home-pane:not([hidden]) .calc-card").count(), 1)
    R.check("home: College has no Level column", home_pane(p).locator(".gpa-f-level").count(), 0)
    p.locator(".calc-seg-btn", has_text="High School").click()
    pane = home_pane(p)
    R.check("home: High School has a Level column", pane.locator(".calc-row-head").inner_text().split("\n")[-1].strip() if pane.locator(".calc-row-head").count() else None, "Level")
    R.check("home: level remembered", p.evaluate("localStorage.getItem('gpac:home:mode')"), "hs")
    p.reload(); p.wait_for_selector(".gpa-home-switch")
    R.check("home: reload opens High School", p.locator(".calc-seg-btn[aria-checked='true']").inner_text(), "High School")

    # Math: weighted and unweighted against an independent calculation
    rng = random.Random(7)
    grades = list(HS_PTS)
    for case in range(12):
        pane = home_reset(p, "hs")
        rows = [(rng.choice(grades), rng.choice(list(HS_BOOST)), rng.choice([1, 1, 1, 0.5])) for _ in range(rng.randint(1, 6))]
        for i, (g, lv, cr) in enumerate(rows):
            row = pane.locator(".gpa-row").nth(i)
            row.locator(".gpa-f-level select").select_option(lv)
            row.locator(".gpa-f-cr input").fill(str(cr))
            row.locator(".gpa-f-grade select").select_option(g)
        u, w, boosted = hs_expect(rows)
        got = (pane.locator(".calc-kicker").inner_text(), pane.locator(".calc-score").inner_text(), pane.locator(".calc-stat").first.inner_text().split("\n"))
        want = ("Weighted GPA", w, ["Unweighted GPA", u]) if boosted else ("GPA", u, ["Weighted GPA", w])
        R.check(f"home hs math case {case}", got, want)

    # Auto level from the course name, never over a level picked by hand
    pane = home_reset(p, "hs")
    row = pane.locator(".gpa-row").first
    row.locator(".gpa-f-name input").fill("AP Biology")
    R.check("home: AP course name sets AP", row.locator(".gpa-f-level select").input_value(), "ap")
    row.locator(".gpa-f-name input").fill("Honors Chemistry")
    R.check("home: Honors course name sets Honors", row.locator(".gpa-f-level select").input_value(), "hon")
    row.locator(".gpa-f-level select").select_option("ib")
    row.locator(".gpa-f-name input").fill("AP Chemistry")
    R.check("home: a level picked by hand stays", row.locator(".gpa-f-level select").input_value(), "ib")
    row.locator(".gpa-f-grade select").select_option("A")
    p.wait_for_timeout(700)
    p.reload(); p.wait_for_selector(".gpa-home-switch")
    R.check("home: hand-picked level kept after reload", home_pane(p).locator(".gpa-row").first.locator(".gpa-f-level select").input_value(), "ib")

    # Suggestions with nicknames
    pane = home_pane(p)
    row = pane.locator(".gpa-row").nth(1)
    inp = row.locator(".gpa-f-name input")
    inp.click()
    inp.press_sequentially("apush")
    R.check("home: 'apush' suggests AP United States History", pane.locator(".calc-suggest:not([hidden]) li").first.inner_text().split("\n")[0] if pane.locator(".calc-suggest:not([hidden]) li").count() else None, "AP United States History")
    inp.press("ArrowDown"); inp.press("Enter")
    R.check("home: picking a suggestion fills the name", inp.input_value(), "AP United States History")
    R.check("home: picked course sets its level", row.locator(".gpa-f-level select").input_value(), "ap")
    R.check("home: list closes after a pick", pane.locator(".calc-suggest:not([hidden])").count(), 0)
    R.check("home: Enter on a pick stays on the row", p.evaluate("document.activeElement.value"), "AP United States History")

    # Old homepage saves: a High School draft opens in High School with its levels; the old keys are untouched
    pane = home_reset(p, None, HS_DRAFT)
    R.check("home legacy: High School draft opens High School", p.locator(".calc-seg-btn[aria-checked='true']").inner_text(), "High School")
    R.check("home legacy: levels restored", [pane.locator(".gpa-row").nth(i).locator(".gpa-f-level select").input_value() for i in range(3)], ["ap", "reg", "hon"])
    u, w, _ = hs_expect([("A-", "ap", 1), ("B+", "reg", 1), ("A", "hon", 1)])
    R.check("home legacy: weighted GPA", (pane.locator(".calc-score").inner_text(), pane.locator(".calc-stat").first.inner_text().split("\n")[1]), (w, u))
    R.check("home legacy: old draft unchanged", json.loads(p.evaluate("localStorage.getItem('gpa_calc_draft_v1')")), HS_DRAFT)
    pane = home_reset(p, None, COL_DRAFT)
    R.check("home legacy: College draft opens College", p.locator(".calc-seg-btn[aria-checked='true']").inner_text(), "College")
    R.check("home legacy: College GPA", pane.locator(".calc-score").inner_text(), fmt((3 * 4 + 4 * 3) / 7))
    p.close(); c.close()

    # Phone: the level button opens a picker; the collapsed row shows the boost
    c = ctx_for(s, 390, 844, mobile=True)
    p = open_page(s, c, "gpa-calculator")
    pane = home_reset(p, "hs")
    row = pane.locator(".gpa-row").first
    R.check("home phone: grade, credits and level share line 2", p.evaluate("""(() => { const r = document.querySelector('.gpa-home-pane:not([hidden]) .gpa-row');
        const y = (s) => Math.round(r.querySelector(s).getBoundingClientRect().top); return [y('.gpa-grade-btn') === y('.gpa-cr-btn'), y('.gpa-cr-btn') === y('.gpa-level-btn')]; })()"""), [True, True])
    row.locator(".gpa-f-name input").fill("Chemistry")
    row.locator(".gpa-level-btn").click()
    R.check("home phone: level picker", p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").all_inner_texts(), ["Regular", "Honors +0.5", "AP +1.0", "IB +1.0", "Dual Enrollment +1.0"])
    p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt", has_text="Honors").click()
    R.check("home phone: level button shows the boost", row.locator(".gpa-level-btn").inner_text(), "Hon +0.5")
    pick_grade(p, row, "B")
    pane.locator(".gpa-row").nth(1).locator(".gpa-f-name input").focus()
    p.wait_for_timeout(100)
    R.check("home phone: collapsed row shows level", row.locator(".gpa-row-sum-m").inner_text(), "B · Hon +0.5 · 1 cr")
    R.check("home phone: weighted headline", (pane.locator(".calc-kicker").inner_text(), pane.locator(".calc-score").inner_text()), ("Weighted GPA", "3.50"))
    if shots:
        p.locator(".gpa-home-switch").scroll_into_view_if_needed()
        p.screenshot(path=str(SHOTS / "home-hs-390x844.png"))
    p.close(); c.close()


# ---------- 8. Homepage v2 proposal: one calculator, scale menu, typed grades, insight line ----------

PCT = [(97, "A+"), (93, "A"), (90, "A-"), (87, "B+"), (83, "B"), (80, "B-"), (77, "C+"), (73, "C"), (70, "C-"), (67, "D+"), (63, "D"), (60, "D-"), (0, "F")]
V2_SCALES = {"standard": HS_PTS, "a-plus-433": {**HS_PTS, "A+": 4.33, "A-": 3.67, "B+": 3.33, "B-": 2.67, "C+": 2.33, "C-": 1.67, "D+": 1.33, "D-": 0.67},
             "weighted-5": HS_PTS}
LEVEL_NAMES = {"reg": "Regular", "hon": "Honors", "ap": "AP", "ib": "IB", "de": "Dual Enroll."}


def pct_letter(v):
    return next(l for m, l in PCT if v + 1e-9 >= m)


def v2_reset(p):
    for _ in range(2):
        p.evaluate("localStorage.clear()")
        p.reload()
        p.wait_for_selector(".calc .calc-card")


def v2_fill(p, i, name, grade, credits=None, level=None):
    row = p.locator(".gpa-row").nth(i)
    row.locator(".gpa-f-name input").fill(name)
    g = row.locator(".gpa-grade-in")
    g.click()
    g.fill(grade)
    g.press("Tab")
    if credits is not None:
        row.locator(".gpa-f-cr .calc-input:not(.gpa-cr-btn)").fill(str(credits))
    if level is not None:
        row.locator(".gpa-f-level select").select_option(level)
    return row


def v2_events(p):
    return [e[1] for e in p.evaluate("window.__events") if e[0] == "event"]


def home_v2(s, shots):
    c = ctx_for(s)
    p = open_page(s, c, "gpa-calculator-v2")
    v2_reset(p)
    R.check("v2: no level switch", p.locator(".gpa-home-switch, .calc-seg").count(), 0)
    R.check("v2: scale menu in the header", p.locator(".gpa-top .gpa-scale-top option").all_inner_texts(), ["4.0", "4.33", "5.0 weighted"])
    R.check("v2: columns", [x.strip() for x in p.locator(".calc-row-head").inner_text().split("\n") if x.strip()], ["Course (optional)", "Grade", "Credits", "Level"])
    row = p.locator(".gpa-row").first
    R.check("v2: credits default 1", row.locator(".gpa-f-cr .calc-input:not(.gpa-cr-btn)").input_value(), "1")
    R.check("v2: level default Regular", row.locator(".gpa-f-level select").input_value(), "reg")
    R.check("v2: no Options button (prior GPA in the action row)", (p.locator(".gpa-options-btn").count(), p.locator(".gpa-actions .gpa-prior-toggle").count()), (0, 1))

    # Typed grades: letters or percentages
    for typed, want in (("93", "A · 93%"), ("b+", "B+"), ("A−", "A−"), ("89.5", "B+ · 89.5%"), ("59", "F · 59%"), ("100", "A+ · 100%"), ("96.99", "A · 96.99%"), ("97", "A+ · 97%")):
        v2_fill(p, 0, "Test", typed)
        R.check(f"v2: typed grade {typed!r}", row.locator(".gpa-grade-in").input_value(), want)
    v2_fill(p, 0, "Test", "150")
    R.check("v2: 150 is not a grade", (row.locator(".calc-row-msg").inner_text(), row.locator(".gpa-grade-in").get_attribute("aria-invalid")),
            ("Type a letter (A, B+) or a percentage from 0 to 100.", "true"))
    v2_fill(p, 0, "Test", "Q")
    R.check("v2: Q is not a grade", row.locator(".gpa-grade-in").get_attribute("aria-invalid"), "true")
    g = row.locator(".gpa-grade-in")
    g.click(); g.fill("")
    g.type("87")
    R.check("v2: typing a % previews its letter", p.locator(".gpa-f-grade .calc-suggest:not([hidden]) li").first.inner_text().split("\n")[0], "B+ · 87%")
    g.press("Enter")
    R.check("v2: Enter takes the previewed letter", (g.input_value(), p.locator(".calc-score").inner_text()), ("87", "3.30"))
    g.press("Tab")
    g.click(); g.fill("")
    R.check("v2: empty box lists every grade", len(p.locator(".gpa-f-grade .calc-suggest:not([hidden]) li").all()), 16)
    g.press("Escape"); g.press("Tab")

    # Level follows the course name until picked by hand
    v2_reset(p)
    r0 = v2_fill(p, 0, "AP Calculus AB", "A")
    R.check("v2: AP name sets AP", r0.locator(".gpa-f-level select").input_value(), "ap")
    r0.locator(".gpa-f-level select").select_option("hon")
    r0.locator(".gpa-f-name input").fill("AP Calculus BC")
    R.check("v2: hand-picked level kept", r0.locator(".gpa-f-level select").input_value(), "hon")

    # Math against an independent calculation, every scale
    rng = random.Random(11)
    for case in range(12):
        v2_reset(p)
        scale = ["standard", "a-plus-433", "weighted-5"][case % 3]
        p.select_option(".gpa-scale-top select", scale)
        rows = []
        for i in range(rng.randint(2, 5)):
            lvl = rng.choice(["reg", "reg", "hon", "ap", "ib", "de"])
            cr = rng.choice([0.5, 1, 1, 2, 3, 4])
            if rng.random() < 0.4:
                v = rng.randint(55, 100)
                typed, letter = str(v), pct_letter(v)
            else:
                letter = rng.choice(list(HS_PTS)); typed = letter
            v2_fill(p, i, f"Class {i + 1}", typed, cr, lvl)
            rows.append((letter, lvl, cr))
        pts = V2_SCALES[scale]
        C = sum(r[2] for r in rows)
        u = sum(pts[r[0]] * r[2] for r in rows) / C
        w = sum((pts[r[0]] + (HS_BOOST[r[1]] if pts[r[0]] > 0 else 0)) * r[2] for r in rows) / C
        boosted = any(HS_BOOST[r[1]] and pts[r[0]] > 0 for r in rows)
        lead_w = boosted and scale == "weighted-5"
        got = (p.locator(".calc-kicker").inner_text(), p.locator(".calc-score").inner_text(),
               p.locator(".calc-score2").inner_text().replace("\n", " ") if p.locator(".calc-score2").is_visible() else None)
        kick = "Weighted GPA" if lead_w else "Unweighted GPA" if boosted else "GPA"
        want = (kick, fmt(w if lead_w else u), (f"Unweighted {fmt(u)}" if lead_w else f"Weighted {fmt(w)}") if boosted else None)
        R.check(f"v2 math #{case} ({scale}, {len(rows)} rows)", got, want)
        # Insight: the best one-step raise, checked by brute force
        steps = sorted({v for v in pts.values()})
        best = None
        for i, (l, lv, cr) in enumerate(rows):
            up = [k for k, v in sorted(pts.items(), key=lambda kv: kv[1]) if v > pts[l] + 1e-9]
            if not up:
                continue
            nl = up[0]
            nr = rows[:i] + [(nl, lv, cr)] + rows[i + 1:]
            nu = sum(pts[r[0]] * r[2] for r in nr) / C
            nw = sum((pts[r[0]] + (HS_BOOST[r[1]] if pts[r[0]] > 0 else 0)) * r[2] for r in nr) / C
            gain = (nw - w) if lead_w else (nu - u)
            if best is None or gain > best + 1e-9:
                best = gain
        line = p.locator(".calc-insight-line")
        if best is not None and best >= 0.005:
            m = re.search(r"\(\+([\d.]+)\)", line.inner_text())
            R.check(f"v2 insight #{case}: biggest gain", m.group(1) if m else None, fmt(best))
        else:
            R.check(f"v2 insight #{case}: hidden with nothing to raise", line.is_visible(), False)

    # Insight tap goes to the course; GA4 events
    v2_reset(p)
    v2_fill(p, 0, "English 10", "A")
    v2_fill(p, 1, "Chemistry", "C", 2)
    ev = v2_events(p)
    R.check("v2 events: result_shown once", ev.count("home_result_shown"), 1)
    R.check("v2 insight: names the course", "Chemistry from C to C+" in p.locator(".calc-insight-line").inner_text(), True)
    p.locator(".calc-insight-line").click()
    p.wait_for_timeout(150)
    R.check("v2 insight tap: focus on that course's grade", p.evaluate("document.activeElement.closest('.gpa-row').querySelector('.gpa-f-name input').value"), "Chemistry")
    R.check("v2 events: insight_tap", "home_insight_tap" in v2_events(p), True)
    p.evaluate("localStorage.setItem('gpac:home:v2.lastVisit', JSON.stringify(Date.now() - 2 * 864e5))")
    p.reload(); p.wait_for_selector(".calc .calc-card")
    rv = [e for e in p.evaluate("window.__events") if e[0] == "event" and e[1] == "home_return_visit"]
    R.check("v2 events: return_visit with days and draft", (len(rv), rv[0][2].get("days") if rv else None, rv[0][2].get("draft") if rv else None), (1, 2, 1))
    p.reload(); p.wait_for_selector(".calc .calc-card")
    R.check("v2 events: no return_visit within 30 minutes", "home_return_visit" in v2_events(p), False)
    R.check("v2 events: restored result counts as shown", "home_result_shown" in v2_events(p), True)

    # Samples
    v2_reset(p)
    p.locator(".gpa-sample[data-sample='high_school']").click()
    R.check("v2 sample: high school", (p.locator(".calc-score").inner_text(), p.locator(".calc-score2").inner_text().replace("\n", " ")), ("3.55", "Weighted 3.73"))
    R.check("v2 sample: % grade shown", p.locator(".gpa-row").nth(1).locator(".gpa-grade-in").input_value(), "B+ · 88%")
    p.locator(".calc-banner button", has_text="Clear").click()
    p.locator(".gpa-sample[data-sample='college']").click()
    R.check("v2 sample: college", (p.locator(".calc-score").inner_text(), p.locator(".calc-score2").is_visible()), ("3.34", False))

    # The old homepage's high school draft comes in once, the old key unchanged
    for _ in range(2):
        p.evaluate("localStorage.clear()"); p.reload(); p.wait_for_selector(".calc .calc-card")
    p.evaluate("d => { localStorage.clear(); localStorage.setItem('gpa_calc_draft_v1', JSON.stringify(d)); }", HS_DRAFT)
    p.goto(p.url); p.wait_for_selector(".calc .calc-card")
    u, w, _ = hs_expect([("A-", "ap", 1), ("B+", "reg", 1), ("A", "hon", 1)])
    R.check("v2 legacy: old high school draft", (p.locator(".calc-score").inner_text(), p.locator(".calc-score2").inner_text().replace("\n", " ")), (u, f"Weighted {w}"))
    R.check("v2 legacy: old key unchanged", json.loads(p.evaluate("localStorage.getItem('gpa_calc_draft_v1')")), HS_DRAFT)
    if shots:
        p.screenshot(path=str(SHOTS / "home-v2-1440x900.png"))
    p.close(); c.close()

    # Phone: letter grid with "Type a %"; the % shows in the row summary; nothing wider than the screen
    for width in (390, 360):
        c = ctx_for(s, width, 844, mobile=True)
        p = open_page(s, c, "gpa-calculator-v2")
        v2_reset(p)
        R.check(f"v2 phone {width}: no sideways scroll", p.evaluate("document.documentElement.scrollWidth <= innerWidth && document.querySelector('.calc-toolbar').scrollWidth <= document.querySelector('.calc-toolbar').clientWidth"), True)
        row = p.locator(".gpa-row").first
        row.locator(".gpa-f-name input").fill("Honors Chemistry")
        row.locator(".gpa-grade-btn").click()
        R.check(f"v2 phone {width}: grid has Type a %", p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt").all_inner_texts()[-1], "Type a %")
        p.locator(".calc-sheet-wrap:not([hidden]) .calc-sheet-opt", has_text="Type a %").click()
        g = row.locator(".gpa-grade-in")
        R.check(f"v2 phone {width}: % box focused, number keypad", (p.evaluate("document.activeElement.classList.contains('gpa-grade-in')"), g.get_attribute("inputmode")), (True, "decimal"))
        g.type("86"); g.press("Enter")
        p.locator(".gpa-row").nth(1).locator(".gpa-f-name input").focus()
        p.wait_for_timeout(100)
        R.check(f"v2 phone {width}: summary shows the %", row.locator(".gpa-row-sum-m").inner_text(), "B · 86% · Hon +0.5 · 1 cr")
        R.check(f"v2 phone {width}: weighted beside", (p.locator(".calc-score").inner_text(), p.locator(".calc-score2").inner_text().replace("\n", " ")), ("3.00", "Weighted 3.50"))
        fits = p.evaluate("""[...document.querySelectorAll('.gpa-row:not(.is-collapsed)')].flatMap(r => [...r.querySelectorAll('.gpa-grade-btn, .gpa-cr-btn, .gpa-level-btn')]).every(b => b.scrollWidth <= b.clientWidth + 1)""")
        R.check(f"v2 phone {width}: grade, credits and level text fits", fits, True)
        if shots:
            p.screenshot(path=str(SHOTS / f"home-v2-{width}x844.png"))
        p.close(); c.close()


if __name__ == "__main__":
    subprocess.run([sys.executable, str(REPO / "scripts/calc/build_preview.py"), str(OUT), "--modules"], check=True, capture_output=True)
    literals()
    import_tags()
    sizes()
    with Session() as s:
        math(s)
        flow(s)
        layout(s, "--shots" in args)
        recolor(s)
        a2hs(s, "--shots" in args)
        home(s, "--shots" in args)
        home_v2(s, "--shots" in args)
        R.check("zero console errors", s.errors, [])
    sys.exit(0 if R.report() else 1)
