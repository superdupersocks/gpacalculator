"""Smoke test for every calculator served from the plugin's assets/calc-assets/.

For each <name>.js: loads the plugin copy (plus <name>.css) as a module on a page shaped like a
live calculator page (theme tokens + calculator-page.css + <div id="root">), and checks that it
mounts something into #root with zero page errors, on desktop and mobile. Also checks that every
calculator the theme still has is byte-identical in the plugin, so pages that still load the
theme URL get the same code.

Math for each calculator is covered by its own <name>_qa.py suite.
"""
import sys

from qa_lib import ARTIFACTS, CALC_ASSETS, THEME_CALC_ASSETS, Results, Session

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/child-theme/generatepress-child/gpa-design-tokens.css">
<link rel="stylesheet" href="/child-theme/generatepress-child/calculator-page.css">
{css}
<script type="module" src="/plugin/gpacalculator-manager/assets/calc-assets/{name}.js"></script>
</head><body class="page page-template-template-calculator">
<main class="site-content"><div class="entry-content"><div id="root"></div></div></main>
</body></html>"""


def main():
    R = Results("calculators_qa")
    names = sorted(p.stem for p in CALC_ASSETS.glob("*.js"))
    for p in sorted(THEME_CALC_ASSETS.glob("*.*")):
        twin = CALC_ASSETS / p.name
        R.ok(f"{p.name}: plugin copy matches theme", twin.exists() and twin.read_bytes() == p.read_bytes())
    fixtures = CALC_ASSETS.parent.parent.parent.parent / "tests" / ".artifacts" / "pages"
    fixtures.mkdir(parents=True, exist_ok=True)
    ARTIFACTS.mkdir(parents=True, exist_ok=True)
    with Session() as s:
        for name in names:
            css = (f'<link rel="stylesheet" href="/plugin/gpacalculator-manager/assets/calc-assets/{name}.css">'
                   if (CALC_ASSETS / f"{name}.css").exists() else "")
            (fixtures / f"{name}.html").write_text(PAGE.format(name=name, css=css))
            for label, kw in (("desktop", dict(width=1440, height=900)), ("mobile", dict(width=390, height=844, mobile=True))):
                before = len(s.errors)
                ctx = s.context(**kw)
                page = s.page(ctx, f"/tests/.artifacts/pages/{name}.html")
                try:
                    page.wait_for_function("document.querySelector('#root') && document.querySelector('#root').querySelectorAll('input,button,select').length > 0", timeout=15000)
                    mounted = True
                except Exception:
                    mounted = False
                page.wait_for_timeout(300)
                R.ok(f"{name} ({label}): mounted with controls", mounted)
                errs = s.errors[before:]
                R.ok(f"{name} ({label}): no page errors", not errs, "; ".join(errs)[:300])
                if label == "desktop":
                    page.screenshot(path=str(ARTIFACTS / f"calc-{name}.png"))
                ctx.close()
    sys.exit(0 if R.report() else 1)


if __name__ == "__main__":
    main()
