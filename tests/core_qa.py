"""QA for the shared calculator core and the starter layout template.

Run: python3 tests/core_qa.py
Every expected value below is computed in Python from the spec, not by calling the JS.
"""
import base64
import json
import sys

from qa_lib import ARTIFACTS, Results, Session, fmt_pct, round_half_up

CORE = "/plugin/gpacalculator-manager/assets/calc-assets/core/calc-core.js"
CATALOG = "/plugin/gpacalculator-manager/assets/calc-assets/core/course-catalog.js"
PAGE = "/tests/fixtures/starter.html"

# Standard scale used across the site: (min %, letter, points)
SCALE = [(97, "A+", 4.0), (93, "A", 4.0), (90, "A-", 3.7), (87, "B+", 3.3), (83, "B", 3.0), (80, "B-", 2.7),
         (77, "C+", 2.3), (73, "C", 2.0), (70, "C-", 1.7), (67, "D+", 1.3), (63, "D", 1.0), (60, "D-", 0.7), (0, "F", 0.0)]
LETTER_MID = {"A+": 98, "A": 95, "A-": 91, "B+": 88, "B": 85, "B-": 81, "C+": 78, "C": 75, "C-": 71,
              "D+": 68, "D": 65, "D-": 61, "F": 50}


def grade(p):
    p = round_half_up(p, 2)
    for mn, letter, pts in SCALE:
        if p >= mn:
            return letter, pts
    return "F", 0.0


def score_pct(s):
    s = s.strip()
    if not s:
        return None
    if "/" in s:
        a, b = (float(x) for x in s.split("/"))
        return a / b * 100 if b > 0 and a >= 0 else None
    if s.upper() in LETTER_MID:
        return LETTER_MID[s.upper()]
    try:
        v = float(s.rstrip("%"))
        return v if v >= 0 else None
    except ValueError:
        return None


def weighted(rows):
    used = [(score_pct(s), w) for s, w in rows if score_pct(s) is not None]
    if not used:
        return None
    given = [(p, float(w)) for p, w in used if w.strip()]
    missing = [p for p, w in used if not w.strip()]
    if not given:
        return sum(p for p, _ in used) / len(used)
    share = max(0.0, 100 - sum(w for _, w in given)) / len(missing) if missing else 0
    pairs = given + [(p, share) for p in missing]
    tw = sum(w for _, w in pairs)
    return sum(p * w for p, w in pairs) / tw if tw > 0 else None


def b64url(obj):
    return base64.urlsafe_b64encode(json.dumps(obj).encode()).decode().rstrip("=")


def unit_tests(s, R):
    ctx = s.context()
    page = s.page(ctx, PAGE)
    page.wait_for_selector("#root .calc")

    # parseScore
    cases = {"84": 84, "84%": 84, " 84.5 % ": 84.5, "42/50": 84, "42 / 50": 84, "42 out of 50": 84, "B+": 88,
             "a-": 91, "0": 0, "100": 100, "105": 105, "0/10": 0, "84,5": 84.5, "": None, "abc": None,
             "-5": None, "5/0": None, "B++": None}
    got = page.evaluate("""async ([core, cases]) => { const m = await import(core);
        const o = {}; for (const c of cases) { const r = m.parseScore(c); o[c] = r ? r.pct : null; } return o; }""",
                        [CORE, list(cases)])
    for c, want in cases.items():
        g = got[c]
        R.check(f"parseScore({c!r})", None if g is None else round(g, 6), want)

    # gradeFor boundaries
    pts = [100, 97, 96.99, 93, 92.995, 92.994, 90, 89.99, 83, 80, 79.999, 70, 60, 59.99, 0, 120]
    got = page.evaluate("""async ([core, pts]) => { const m = await import(core);
        return pts.map(p => { const g = m.gradeFor(p); return [g.letter, g.gpa, g.band]; }); }""", [CORE, pts])
    for p, g in zip(pts, got):
        L, gpa = grade(p)
        R.check(f"gradeFor({p})", g, [L, gpa, L[0].lower()])

    # nextGrade
    got = page.evaluate("async (core) => { const m = await import(core); return [m.nextGrade(88.5), m.nextGrade(99)]; }", CORE)
    R.check("nextGrade(88.5)", got[0], {"letter": "A-", "min": 90, "gap": 1.5})
    R.check("nextGrade(99)", got[1], None)

    # fmtPct / round
    for n, want in [(84, "84%"), (84.5, "84.5%"), (84.567, "84.57%"), (1.005, "1.01%"), (99.995, "100%"), (0, "0%")]:
        R.check(f"fmtPct({n})", page.evaluate("async ([c, n]) => (await import(c)).fmtPct(n)", [CORE, n]), want)

    # encode/decode: JS decodes Python's encoding and vice versa
    obj = {"rows": [{"name": "Español ✓ 数学", "score": "42/50"}], "n": 3}
    R.check("decodeState(python b64url)", page.evaluate("async ([c, s]) => (await import(c)).decodeState(s)", [CORE, b64url(obj)]), obj)
    enc = page.evaluate("async ([c, o]) => (await import(c)).encodeState(o)", [CORE, obj])
    R.check("encodeState -> python decode", json.loads(base64.urlsafe_b64decode(enc + "=" * (-len(enc) % 4))), obj)
    R.check("decodeState(garbage)", page.evaluate("async (c) => (await import(c)).decodeState('%%%')", CORE), None)

    # CSV escaping and formula guard
    rows = [["a,b", 'say "hi"', "=SUM(A1)", "-5", "line\nbreak", None, 84.5]]
    want = '"a,b","say ""hi""",\'=SUM(A1),-5,"line\nbreak",,84.5'
    R.check("toCSV", page.evaluate("async ([c, r]) => (await import(c)).toCSV(r)", [CORE, rows]), want)

    # Tracker fires once per view unless repeatable
    ev = page.evaluate("""async (c) => { const m = await import(c); window.__events = [];
        const t = m.createTracker('zz'); t('a'); t('a'); t('b', null, true); t('b', null, true); return window.__events; }""", CORE)
    R.check("tracker once/repeat", ev, ["zz_a", "zz_b", "zz_b"])

    # Course catalog
    got = page.evaluate("""async (c) => { const m = await import(c);
        return { g: ['AP Bio', 'Honors Chemistry', 'Chem H', 'IB Math HL', 'Dual Enrollment English', 'College Prep English', 'Biology', ''].map(m.guessLevel),
                 w: [m.weightedPoints(4, 'ap'), m.weightedPoints(3, 'honors'), m.weightedPoints(0, 'ap'), m.weightedPoints(0, 'ap', { bonusOnF: true }),
                     m.weightedPoints(3.7, 'ib', { bonuses: { ib: 0.5 } }), m.weightedPoints(3, 'nope')],
                 s: m.searchCourses('ap calc').map(x => x.name),
                 n: m.COURSES.length, dup: m.COURSES.length - new Set(m.COURSES.map(x => x.name)).size }; }""", CATALOG)
    R.check("guessLevel", got["g"], ["ap", "honors", "honors", "ib", "de", None, None, None])
    R.check("weightedPoints", got["w"], [5, 3.5, 0, 1, 4.2, 3])
    R.check("searchCourses('ap calc')", got["s"], ["AP Calculus AB", "AP Calculus BC"])
    R.ok("catalog size", got["n"] > 150, f"(n={got['n']})")
    R.check("catalog duplicate names", got["dup"], 0)
    ctx.close()


def fill_rows(page, rows):
    """Type rows into the real UI, adding rows as needed."""
    while page.locator("#root .calc-rows .calc-row").count() < len(rows):
        page.click("text=+ Add row")
    for i, (name, score, weight) in enumerate(rows):
        n = i + 1
        page.fill(f"[aria-label='Category {n} name']", name)
        page.fill(f"[aria-label='Category {n} score']", score)
        page.fill(f"[aria-label='Category {n} weight percent']", weight)


def score_text(page):
    return page.locator("#root .calc-score").inner_text().strip()


def ui_tests(s, R):
    MATH = [
        ("all weights", [("HW", "92", "20"), ("Quiz", "85%", "30"), ("Test", "78.5", "50")]),
        ("fraction + letter", [("HW", "42/50", "40"), ("Quiz", "B+", "60")]),
        ("missing weight shares rest", [("HW", "90", "30"), ("Quiz", "80", "30"), ("Test", "70", "")]),
        ("no weights = plain average", [("A", "91", ""), ("B", "77", ""), ("C", "66.5", "")]),
        ("weights over 100", [("A", "100", "80"), ("B", "50", "80")]),
        ("zero and blank rows", [("A", "0", "50"), ("B", "", "25"), ("C", "100", "50")]),
        ("100%", [("A", "100", "100")]),
        ("decimals", [("A", "88.88", "33.3"), ("B", "77.77", "66.7")]),
        ("over 100 extra credit", [("A", "105", "50"), ("B", "95", "50")]),
    ]
    ctx = s.context()
    page = s.page(ctx, PAGE)
    page.wait_for_selector("#root .calc")
    R.ok("result hidden before data", not page.locator("#root .calc-result").is_visible())
    R.check("#root min-height overridden", page.evaluate("getComputedStyle(document.getElementById('root')).minHeight"), "0px")
    for label, rows in MATH:
        page.click("#root .calc-toolbar >> text=My classes")
        page.click("#root .calc-menu >> text=New (start over)")
        fill_rows(page, [(n, sc, w) for n, sc, w in rows])
        want = weighted([(sc, w) for _, sc, w in rows])
        R.check(f"grade: {label}", score_text(page), fmt_pct(want))
        L, gpa = grade(want)
        R.check(f"letter: {label}", page.locator("#root .calc-ring-letter").inner_text(), L)

    # Invalid input: hint shown, row ignored
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=New (start over)")
    fill_rows(page, [("A", "80", "50"), ("B", "abc", "50")])
    R.check("invalid hint", page.locator("#root .calc-hint.is-error").inner_text(), "Try 84, 84% or 42/50")
    R.check("invalid row ignored", score_text(page), "80%")
    page.fill("[aria-label='Category 2 score']", "45/50")
    R.check("conversion hint", page.locator("#root .calc-hint.is-ok").inner_text(), "= 90%")
    R.check("verdict", page.locator("#root .calc-verdict").first.inner_text(), "Right now you’re passing with a B.")

    # Planner: needed on final
    page.click("text=What do I need on the final?")
    current = weighted([("80", "50"), ("45/50", "50")])
    for fw, tg in [("25", "90"), ("40", "50"), ("10", "99"), ("20", "85")]:
        page.fill("[aria-label='Final exam weight percent']", fw)
        page.fill("[aria-label='Target grade percent']", tg)
        need = (float(tg) - current * (1 - float(fw) / 100)) / (float(fw) / 100)
        out = page.locator("#root .calc-plan-out").inner_text()
        if need <= 0:
            R.ok(f"plan {fw}/{tg} (<=0)", "even with a 0%" in out, out)
        elif need > 100:
            R.ok(f"plan {fw}/{tg} (>100)", f"You’d need {fmt_pct(need)}" in out, out)
        else:
            R.ok(f"plan {fw}/{tg}", out.startswith(f"You need {fmt_pct(need)} on the final"), out)
    page.fill("[aria-label='Final exam weight percent']", "0")
    R.check("plan weight 0", page.locator("#root .calc-plan-out").inner_text(), "Final weight must be between 0 and 100%.")
    R.ok("step 2 current", page.locator("#root .calc-step").nth(1).get_attribute("aria-current") == "step")
    R.check("step 1 checkmark", page.locator("#root .calc-step-dot").first.inner_text(), "✓")

    # At risk: button switches to pass mode and presets target 60
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=New (start over)")
    fill_rows(page, [("A", "62", "100")])
    R.check("at-risk button", page.locator("#root .calc-actions .calc-btn-primary").inner_text(), "What do I need to pass?")
    page.click("text=What do I need to pass?")
    R.check("target preset to pass", page.input_value("[aria-label='Target grade percent']"), "60")

    # Sample: banner, can't be saved, clear
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=New (start over)")
    page.click("text=Try a sample")
    R.ok("sample banner", page.locator("#root .calc-banner").is_visible())
    want = weighted([("92%", "20"), ("42/50", "30"), ("B+", "25")])
    R.check("sample grade", score_text(page), fmt_pct(want))
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=Save as…")
    R.ok("sample not saveable", "can’t be saved" in page.locator("#root .calc-toast").inner_text())
    page.click("#root .calc-banner >> text=Clear")
    R.ok("clear hides result", not page.locator("#root .calc-result").is_visible())

    # Named save -> new -> reopen
    fill_rows(page, [("Bio", "88", "50"), ("Chem", "93", "50")])
    page.once("dialog", lambda d: d.accept("Fall classes"))
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=Save as…")
    R.ok("ga stx_save", "stx_save" in page.evaluate("window.__events"))
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=New (start over)")
    R.ok("new clears", not page.locator("#root .calc-result").is_visible())
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("#root .calc-menu >> text=Fall classes")
    R.check("reopen save", score_text(page), fmt_pct(90.5))
    page.keyboard.press("Escape")

    # Draft survives reload
    page.fill("[aria-label='Category 2 score']", "73")
    page.wait_for_timeout(600)
    page.reload()
    page.wait_for_selector("#root .calc-score")
    R.check("draft restored", score_text(page), fmt_pct((88 + 73) / 2))
    R.ok("returning: onboarding hidden", not page.locator("#root .calc-onboard").is_visible())
    R.ok("returning: example link", page.locator("text=Show an example").is_visible())

    # Escape closes menu
    page.click("#root .calc-toolbar >> text=My classes")
    page.keyboard.press("Escape")
    R.ok("escape closes menu", not page.locator("#root .calc-menu").first.is_visible())

    # Delete save
    page.once("dialog", lambda d: d.accept())
    page.click("#root .calc-toolbar >> text=My classes")
    page.click("[aria-label='Delete Fall classes']")
    R.ok("delete save", page.locator("#root .calc-menu >> text=No saves yet").is_visible())

    # Enter moves to the next field
    page.keyboard.press("Escape")
    page.focus("[aria-label='Category 1 name']")
    page.keyboard.press("Enter")
    R.check("enter -> next", page.evaluate("document.activeElement.getAttribute('aria-label')"), "Category 1 score")

    # GA events fired once
    ev = page.evaluate("window.__events")
    R.ok("ga stx_result once after reload", ev.count("stx_result") == 1, str(ev))
    ctx.close()

    # Share link: state encoded by Python opens in the calculator
    ctx = s.context()
    state = {"rows": [{"name": "Shared", "score": "45/60", "weight": ""}], "final": {"weight": "", "target": ""}}
    page = s.page(ctx, f"{PAGE}#stx={b64url(state)}")
    page.wait_for_selector("#root .calc-score")
    R.check("share link opens", score_text(page), fmt_pct(75))
    R.check("hash cleared", page.evaluate("location.hash"), "")
    ctx.close()

    # Blocked storage: still works, no errors
    blocked = "Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } });"
    ctx = s.context(init_script=blocked)
    page = s.page(ctx, PAGE)
    page.wait_for_selector("#root .calc")
    fill_rows(page, [("A", "77", "")])
    R.check("works with storage blocked", score_text(page), "77%")
    ctx.close()


def shortcode_mount_tests(s, R):
    """Calculators printed by a shortcode mount into .gpacalc-mount (no #root), styled the same."""
    ctx = s.context()
    page = s.page(ctx, "/tests/fixtures/starter-shortcode.html")
    page.wait_for_selector(".gpacalc-mount .calc")
    R.check("shortcode: both mounts rendered", page.locator(".gpacalc-mount > .calc").count(), 2)
    R.check("shortcode: scoped styles apply", page.evaluate(
        "getComputedStyle(document.querySelector('.gpacalc-mount .calc-card')).borderTopLeftRadius"), "24px")
    first = page.locator(".gpacalc-mount").first
    first.locator("[aria-label='Category 1 score']").fill("42/50")
    R.check("shortcode: live result", first.locator(".calc-score").inner_text().strip(), "84%")
    R.ok("shortcode: other instance untouched", not page.locator(".gpacalc-mount").nth(1).locator(".calc-result").is_visible())
    got = page.evaluate("""async (c) => { const m = await import(c);
        const els = [...document.querySelectorAll('.gpacalc-mount')];
        return { again: m.mountsFor('starter').length, atts: els.map(m.readAtts) }; }""", CORE)
    R.check("shortcode: no double mount", got["again"], 0)
    R.check("shortcode: readAtts", got["atts"], [{"country": "uk"}, {}])
    ctx.close()


def token_tests(s, R):
    """Calculators read the theme's brand tokens, with built-in fallbacks when they're missing."""
    probe = """() => { const b = document.querySelector('#root .calc-btn-text');
        const c = document.querySelector('#root .calc');
        return { color: getComputedStyle(b).color, font: getComputedStyle(c).fontFamily.split(',')[0].trim(),
                 radius: getComputedStyle(document.querySelector('#root .calc-card')).borderTopLeftRadius }; }"""
    want = {"color": "rgb(124, 58, 237)", "font": "Inter", "radius": "24px"}
    for label, qs in [("theme tokens", ""), ("no theme tokens (fallbacks)", "?tokens=off")]:
        ctx = s.context()
        page = s.page(ctx, PAGE.replace(".html", f".html{qs}"))
        page.wait_for_selector("#root .calc")
        R.check(label, page.evaluate(probe), want)
        theme_var = page.evaluate("getComputedStyle(document.documentElement).getPropertyValue('--gpa-calc-brand-1').trim()")
        R.check(f"{label}: --gpa-brand-1 present", bool(theme_var), not qs)
        ctx.close()
    ctx = s.context()
    page = s.page(ctx, f"{PAGE}?brand=%23dc2626")
    page.wait_for_selector("#root .calc")
    R.check("brand token override restyles calculator", page.evaluate(probe)["color"], "rgb(220, 38, 38)")
    ctx.close()


def visual_tests(s, R):
    ARTIFACTS.mkdir(parents=True, exist_ok=True)
    for name, kw in [("desktop", dict(width=1440, height=900)), ("mobile", dict(width=390, height=844, mobile=True))]:
        ctx = s.context(reduced_motion="no-preference", **kw)
        page = s.page(ctx, PAGE)
        page.wait_for_selector("#root .calc")
        R.check(f"{name}: Inter loaded", page.evaluate("document.fonts.check('800 16px Inter')"), True)
        page.screenshot(path=str(ARTIFACTS / f"starter-{name}-empty.png"), full_page=False)
        fill_rows(page, [("Homework", "92", "20"), ("Quizzes", "42/50", "30"), ("Midterm", "B+", "25"), ("Labs", "95", "25")])
        page.wait_for_timeout(900)  # count-up
        R.check(f"{name}: count-up lands on value", score_text(page), fmt_pct(weighted([("92", "20"), ("42/50", "30"), ("B+", "25"), ("95", "25")])))
        if name == "mobile":
            page.evaluate("window.scrollTo(0, 0)")
            page.focus("[aria-label='Category 1 score']")
            page.wait_for_timeout(300)
            pill = page.locator("#root .calc-pill")
            R.ok("mobile: live pill shows while result is below", "is-on" in (pill.get_attribute("class") or ""))
            page.screenshot(path=str(ARTIFACTS / "starter-mobile-typing.png"))
            pill.click()
            page.wait_for_timeout(500)
            R.ok("mobile: pill hides at result", "is-on" not in (pill.get_attribute("class") or ""))
            sw = page.evaluate("document.documentElement.scrollWidth")
            R.ok("mobile: no horizontal scroll", sw <= 390, f"(scrollWidth={sw})")
        page.locator("#root .calc").screenshot(path=str(ARTIFACTS / f"starter-{name}-result.png"))
        ctx.close()


def main():
    R = Results("core_qa")
    with Session() as s:
        unit_tests(s, R)
        ui_tests(s, R)
        shortcode_mount_tests(s, R)
        token_tests(s, R)
        visual_tests(s, R)
        R.check("page errors", s.errors, [])
    sys.exit(0 if R.report() else 1)


if __name__ == "__main__":
    main()
