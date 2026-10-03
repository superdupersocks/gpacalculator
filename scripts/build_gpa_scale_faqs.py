"""Write the FAQ for each /gpa-scale/<x-x>-gpa/ page (5-6 questions) as JSON: {slug: [{title, content}]}.

    python3 scripts/build_gpa_scale_faqs.py > content/gpa-scale-faqs.json

No search-volume data is available, so the questions follow the common query patterns for a GPA value
("is a 3.8 GPA good", "3.8 GPA letter grade / percentage", "can I get into college with a 3.8",
"3.8 weighted vs unweighted", "how to raise a 3.8 GPA", plus one band-specific question). Answers lead with a direct
first sentence, use the page's own letter and percentage (content/gpa-scale-intros.md), make no statistical claims
(so no citations are needed in the FAQ) and contain no links.
"""
import json
import math
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
MILESTONE = [(1.95, 2.0), (2.45, 2.5), (2.95, 3.0), (3.45, 3.5), (3.65, 3.7), (3.85, 3.9)]


def figures():
    out = {}
    for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
        m = re.match(r"\| /gpa-scale/([0-9])-([0-9])-gpa/[^|]*\| ([^|]+) \| ([^|]+) \|", line)
        if m:
            out[f"{m.group(1)}-{m.group(2)}-gpa"] = (m.group(3).strip().replace("-", "−"), m.group(4).strip().replace("-", "–"))
    return out


def band(g):
    return "top" if g >= 3.7 else "high" if g >= 3.3 else "mid" if g >= 3.0 else "low" if g >= 2.5 else "weak" if g >= 2.0 else "poor"


def an(letter):
    return "an" if letter[0] in "AF" else "a"


def needed(g, target, grade=4.0, done=8):
    return math.ceil(done * (target - g) / (grade - target) - 1e-9)


def faqs(gs, g, letter, pct):
    b = band(g)
    q = []
    good = {
        "top": f"Yes. A {gs} is an excellent GPA, well above the national average of about 3.1. It shows consistent {letter} work and is competitive for selective colleges, honors programs and merit scholarships.",
        "high": f"Yes. A {gs} is a good GPA. It is above the national average of about 3.1 and meets or beats the typical range at a wide range of four-year colleges.",
        "mid": f"A {gs} is a solid, roughly average GPA. The national average is about 3.1, so it meets the bar at many four-year colleges, though selective schools usually look for more.",
        "low": f"A {gs} is a little below average. It is below the national average of about 3.1, but it still meets the minimum at many four-year colleges, especially less selective ones.",
        "weak": f"A {gs} is below average. It is well under the national average of about 3.1, and some four-year colleges look for at least a 2.5, so raising it will widen your options.",
        "poor": f"No. A {gs} is well below average and under the 2.0 that many colleges and programs set as a minimum. The good news is that grades can recover, and the earlier you start the more they move.",
    }[b]
    q.append((f"Is a {gs} GPA good?", good))
    q.append((f"What letter grade and percentage is a {gs} GPA?",
              f"A {gs} GPA is {an(letter)} {letter} average on the standard unweighted 4.0 scale, which is about {pct}. "
              "Schools set their own cutoffs, so check how your school converts percentages to grade points."))
    college = {
        "top": f"Yes. A {gs} makes you competitive at most colleges, including many selective ones. At the most selective schools a high GPA is expected, so course rigor, essays, recommendations and activities decide between strong applicants.",
        "high": f"Yes. A {gs} fits the typical range at many four-year colleges and is competitive at a good number of selective ones. Treat the most selective schools as reaches and compare your GPA with each college's published range.",
        "mid": f"Yes. Many four-year colleges, including most state universities, admit students with a {gs}. Strong courses, a rising grade trend and good test scores (if you submit them) help at more selective schools.",
        "low": f"Yes. A {gs} meets the minimum at many four-year colleges, especially regional public universities and less selective private colleges. Community college followed by a transfer is another proven route.",
        "weak": f"Yes, but your options are narrower. Some four-year colleges admit students with a {gs}, and so do community colleges, which are open-admission. Raising your GPA before you apply, or transferring after a strong year at a community college, opens more doors.",
        "poor": f"Most four-year colleges won't admit a {gs} directly, but community colleges are open-admission. Many students earn strong grades there and transfer to a four-year college.",
    }[b]
    q.append((f"Can I get into college with a {gs} GPA?", college))
    if g >= 4.0:
        q.append(("Can you have a GPA higher than 4.0?",
                  "Yes, on a weighted scale. Many schools add extra points for Honors, AP or IB classes, so an A in a weighted class can count as more than 4.0. On the unweighted scale, 4.0 is the top."))
        q.append(("How do I keep a 4.0 GPA?",
                  "Any grade below an A lowers a 4.0, so protect every class: plan study time around your hardest courses, ask for help as soon as something doesn't click, and aim above the A cutoff so one weak test doesn't drop the grade."))
        q.append(("Is a 4.0 unweighted better than a higher weighted GPA?",
                  "Neither is automatically better. Colleges look at both the grades and how hard the classes were, and many recalculate GPAs their own way. A 4.0 in demanding courses is the strongest combination."))
        return q
    q.append((f"Is a {gs} weighted GPA the same as a {gs} unweighted GPA?",
              f"No. An unweighted GPA uses the 4.0 scale, where an A is 4.0. A weighted GPA adds extra points for Honors, AP or IB classes, so a {gs} weighted GPA usually stands for a lower unweighted average than a {gs} unweighted. Colleges often recalculate GPAs, so they see both."))
    target = next((t for lim, t in MILESTONE if g < lim), 3.95)
    ts = f"{target:.2f}".rstrip("0") if target == 3.95 else f"{target:.1f}"
    if g >= 3.9:
        q.append((f"Can I raise a {gs} GPA to a 4.0?",
                  f"Not exactly. Once you have any grade below an A, an unweighted GPA can get closer to 4.0 but never reach it. With all A's from here it keeps climbing: after 8 equal-credit classes, {needed(g, target)} more A's take a {gs} to a {ts}."))
    else:
        n1, n2 = needed(g, target), needed(g, target, done=16)
        q.append((f"How can I raise a {gs} GPA to a {ts}?",
                  f"With A's in your next classes. If you have finished 8 equal-credit classes, about {n1} more A's take a {gs} to a {ts}; after 16 classes it takes about {n2}. "
                  "The earlier you start, the faster it moves, so focus first on the classes closest to the next grade up."))
    extra = {
        "top": (f"Is a {gs} GPA good enough for scholarships?",
                f"Yes. A {gs} clears the minimum GPA for most merit scholarships, which makes the rest of the application (essays, activities, test scores where required) the deciding factor."),
        "high": (f"Is a {gs} GPA good enough for scholarships?",
                 f"Yes, for many of them. A {gs} meets the minimum GPA for a large number of merit scholarships; the most competitive awards may ask for more, so check each one's requirements."),
        "mid": (f"Is a {gs} GPA good enough for scholarships?",
                f"Often, yes. Many scholarships set their minimum around a 3.0, so a {gs} keeps you eligible for a good number of them. Look for awards that also weigh essays, service or financial need."),
        "low": (f"Is a {gs} GPA good in college?",
                f"It keeps you in good standing, which usually requires a 2.0, but it is under the 3.0 that many scholarships, internships and graduate programs ask for, so aim to bring it up."),
        "weak": (f"Is a {gs} GPA good in college?",
                 f"It keeps you in good standing, which usually requires a 2.0, but some majors, scholarships and graduate programs ask for more. An academic advisor can help you plan which classes to focus on."),
        "poor": (f"Can I graduate high school with a {gs} GPA?",
                 "Usually, yes, as long as you pass the classes and earn the credits your state and school require; a D is a passing grade at most schools. Some schools set a minimum GPA for sports or activities, so check your school's policy and talk to your counselor."),
    }[b]
    q.append(extra)
    return q


def main():
    figs = figures()
    out = {}
    for slug, (letter, pct) in figs.items():
        gs = f"{slug[0]}.{slug[2]}"
        out[slug] = [{"title": t, "content": c} for t, c in faqs(gs, float(gs), letter, pct)]
    json.dump(out, sys.stdout, indent=1, ensure_ascii=False)
    print(file=sys.stderr)
    print(f"{len(out)} pages, " + ", ".join(f"{k}={len(v)}" for k, v in list(out.items())[:3]) + " …", file=sys.stderr)


if __name__ == "__main__":
    main()
