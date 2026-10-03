"""Draft the one-structure rewrite of the /gpa-scale/<x-x>-gpa/ pages.

    python3 scripts/build_gpa_scale_rewrite.py PAGES_JSON OUT_DIR [slug ...]

PAGES_JSON is {slug: post_content} from the site. For each page writes OUT_DIR/<slug>.html (block markup ready
to save) built as:

  intro (kept) -> quick facts -> scale table + weighted note (kept) -> "Is a X GPA good?" (high school, college,
  weighted vs unweighted) -> "What a X GPA means for college" (+ the page's own Freshman-Senior notes, kept)
  -> "How to raise a X GPA" (computed table of A's needed to reach the next milestone, the page's own tips, Raise
  GPA calculator link) -> the page's FAQ (kept) .

Figures (letter, %) come from content/gpa-scale-intros.md, the same source as the intro, table and chart image.
"""
import json
import math
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
HOME = "https://gpacalculator.net/"
RAISE = "https://gpacalculator.net/how-to-raise-gpa/"
WEIGHTED = "https://gpacalculator.net/weighted-gpa-calculator/"
SOURCE = "https://www.nationsreportcard.gov/hstsreport/"
NAEP = f'<a href="{SOURCE}">NAEP High School Transcript Study</a>'


def a(url, text):
    return f'<a href="{url}">{text}</a>'


def blk(name, html, attrs=None):
    j = " " + json.dumps(attrs, ensure_ascii=False, separators=(",", ":")) if attrs else ""
    return f"<!-- wp:{name}{j} -->\n{html}\n<!-- /wp:{name} -->"


def p(html):
    return blk("paragraph", f"<p>{html}</p>")


def h(level, text):
    attrs = None if level == 2 else {"level": level}
    return blk("heading", f'<h{level} class="wp-block-heading">{text}</h{level}>', attrs)


def ul(items):
    li = "".join(blk("list-item", f"<li>{i}</li>") for i in items)
    return blk("list", f'<ul class="wp-block-list">{li}</ul>')


def table(head, rows, caption):
    def cell(tag, t):
        return f'<{tag} class="has-text-align-center" data-align="center">{t}</{tag}>'
    th = "<thead><tr>" + "".join(cell("th", x) for x in head) + "</tr></thead>"
    tb = "<tbody>" + "".join("<tr>" + "".join(cell("td", x) for x in r) + "</tr>" for r in rows) + "</tbody>"
    return blk("table", f'<figure class="wp-block-table"><table class="has-fixed-layout">{th}{tb}</table>'
                        f'<figcaption class="wp-element-caption">{caption}</figcaption></figure>')


def figures():
    out = {}
    for line in (REPO / "content" / "gpa-scale-intros.md").read_text().splitlines():
        m = re.match(r"\| /gpa-scale/([0-9])-([0-9])-gpa/[^|]*\| ([^|]+) \| ([^|]+) \|", line)
        if m:
            out[f"{m.group(1)}-{m.group(2)}-gpa"] = (m.group(3).strip().replace("-", "−"), m.group(4).strip().replace("-", "–"))
    return out


def band(g):
    return "top" if g >= 3.7 else "high" if g >= 3.3 else "mid" if g >= 3.0 else "low" if g >= 2.5 else "weak" if g >= 2.0 else "poor"


def art(word):
    return "an" if word[:1] in "AEF8" or word.startswith("1.8") else "a"


# ---------------------------------------------------------------- section text, by band
def is_it_good(gs, g, letter, pct):
    b = band(g)
    lead = {
        "top": f"Yes. A {gs} is an excellent GPA: it means {letter} work across your classes, about {pct}, and it is well above the national average. High school graduates in 2019 averaged 3.11, according to the {NAEP}.",
        "high": f"Yes. A {gs} is a good GPA: {letter} work, about {pct}, and comfortably above the national average of 3.11 for 2019 graduates ({NAEP}).",
        "mid": f"A {gs} is a solid GPA that sits right around the national average, which was 3.11 for 2019 high school graduates ({NAEP}). It works out to {letter} work, about {pct}.",
        "low": f"A {gs} is a little below average. It means {letter} work, about {pct}, while 2019 high school graduates averaged 3.11 ({NAEP}). It is not a problem for many colleges, but it narrows your options at selective ones.",
        "weak": f"A {gs} is below average. It works out to {letter} work, about {pct}, against a national average of 3.11 for 2019 graduates ({NAEP}). It meets the minimum at some colleges but leaves little room.",
        "poor": f"No. A {gs} is well below average: {letter} work, about {pct}, against a national average of 3.11 for 2019 graduates ({NAEP}). It is under the 2.0 that many colleges set as a minimum, so raising it is the priority.",
    }[b]
    hs = {
        "top": "Admissions officers will read it as consistent, high-level work. At this level the rest of your application (course rigor, test scores if you send them, essays, activities) decides the most competitive admissions.",
        "high": "It shows steady, above-average work and keeps a wide range of four-year colleges open. Strong courses and an upward trend make it even more convincing.",
        "mid": "Most colleges will consider it, especially with a challenging course load or an upward trend in your grades. Selective colleges usually expect more.",
        "low": "It meets the minimum at many four-year colleges, but you will be below the typical admitted student at selective ones. Improving your grades this year is worth the effort.",
        "weak": "Many four-year colleges look for at least a 2.5, so this GPA limits your choices. Community colleges and open-admission schools remain open to you while you work on it.",
        "poor": "Most four-year colleges will not admit a student below 2.0. Use the time you have left in high school to raise it; community colleges are open-admission and a common route to a four-year degree.",
    }[b]
    col = {
        "top": "In college a GPA this high stands out for scholarships, honors programs and graduate school, where 3.0 to 3.5 is a common minimum.",
        "high": "In college it keeps you in good standing for most scholarships and clears the 3.0 that many graduate programs and employers ask for.",
        "mid": "In college it meets the 3.0 that many scholarships, graduate programs and employers use as a cutoff, so keep it there.",
        "low": "In college it is under the 3.0 that many scholarships and graduate programs require, but well above the 2.0 needed for good standing.",
        "weak": "In college you are in good standing (usually 2.0 or above), but some majors, scholarships and graduate programs require more.",
        "poor": "In college a GPA below 2.0 usually means academic probation, which can affect financial aid. Talk to an academic advisor about a plan.",
    }[b]
    blocks = [h(2, f"Is a {gs} GPA good?"), p(lead), h(3, "In high school"), p(hs), h(3, "In college"), p(col)]
    if g < 3.5:
        blocks += [h(3, "Weighted or unweighted?"),
                   p(f"These figures are for the unweighted 4.0 scale. If your school gives extra points for Honors, AP or IB classes, "
                     f"your weighted GPA can be higher than {gs}. Colleges usually recalculate GPAs their own way, so they see both. "
                     f"Work out yours with the {a(WEIGHTED, 'Weighted GPA calculator')}.")]
    return blocks


def for_college(gs, g):
    b = band(g)
    text = {
        "top": [f"A {gs} makes you a competitive applicant at most colleges, including many selective ones. At the most selective schools most admitted students have a GPA this high or higher, so your courses, essays and activities carry the decision.",
                "Apply to a balanced list: a few reach schools, several where your GPA is at or above their typical range, and a couple of likely admits."],
        "high": [f"A {gs} fits the typical range at a large number of four-year colleges and is competitive at many selective ones. The most selective schools usually admit students with higher GPAs, so treat them as reaches.",
                 "Check each college's published GPA range (often on its admissions page or Common Data Set) and build a balanced list around it."],
        "mid": [f"A {gs} is close to the middle of the range at many state universities and four-year colleges. Selective colleges usually look for higher, so they are reaches.",
                "A strong senior year, a challenging course load and good test scores (if you send them) can make up ground. Compare your GPA with each college's published range."],
        "low": [f"With a {gs} you can get into many four-year colleges, especially regional public universities and less selective private colleges. Selective colleges are a stretch.",
                "Colleges notice an upward trend, so better grades this year help. Community college followed by a transfer is another proven route."],
        "weak": [f"A {gs} meets the minimum at some four-year colleges and at all open-admission and community colleges. Many colleges look for 2.5 or higher, so your choices are limited for now.",
                 "Raising your GPA before you apply, or starting at a community college and transferring after a strong first year, opens more options."],
        "poor": [f"With a {gs} most four-year colleges will not admit you directly. Community colleges are open-admission, and many students transfer to a four-year college after earning good grades there.",
                 "If you are still in high school, every semester of better grades counts. Ask your counselor about credit recovery for classes you failed."],
    }[b]
    return [h(2, f"What a {gs} GPA means for college")] + [p(t) for t in text]


DEFAULT_TIPS = {
    "top": ["<strong>Protect the hard classes:</strong> one B in a heavy course costs more than it seems at this level, so plan study time around them.",
            "<strong>Ask early:</strong> go to office hours or tutoring the first time something doesn't click, not before the final.",
            "<strong>Keep a margin:</strong> aim above the cutoff on every assignment so one weak test doesn't drop the grade."],
    "high": ["<strong>Find the weakest class:</strong> moving one B+ to an A− lifts your GPA more than polishing classes you already ace.",
             "<strong>Use office hours:</strong> ask teachers what separates your work from an A.",
             "<strong>Plan ahead:</strong> keep a calendar of tests and deadlines so nothing is done last-minute."],
    "mid": ["<strong>Target two classes:</strong> pick the classes closest to the next grade up and focus there first.",
            "<strong>Never skip assignments:</strong> missing work pulls grades down faster than low scores.",
            "<strong>Study in short daily blocks:</strong> regular review beats cramming before tests."],
    "low": ["<strong>Turn in everything:</strong> complete, on-time work is the quickest way to stop losing points.",
            "<strong>Get help in your hardest class:</strong> tutoring or a study group early in the term.",
            "<strong>Ask about retakes:</strong> some schools replace or average a retaken grade."],
    "weak": ["<strong>Talk to your counselor:</strong> ask about retaking classes or credit recovery; many schools replace or average the old grade.",
             "<strong>Focus on core classes:</strong> English, math, science and social studies matter most to colleges.",
             "<strong>Turn in every assignment:</strong> zeros do the most damage to a low GPA."],
    "poor": ["<strong>Start with your counselor:</strong> ask about credit recovery and retaking failed classes, which many schools replace or average.",
             "<strong>Pass every class this term:</strong> an F counts as 0.0, so moving any F to a C makes a big difference.",
             "<strong>Get support early:</strong> tutoring, after-school help or a study partner, from the first weeks of the term.",
             f"<strong>Set a semester target:</strong> use the {a(RAISE, 'Raise GPA calculator')} to see what grades get you to 2.0."],
}


def year_notes(gs, g):
    target = next(t for lim, t in MILESTONE if g < lim)
    return [
        h(3, "Freshman"),
        p(f"Most of high school is still ahead of you, so a {gs} now can change a lot. Every semester of better grades pulls "
          f"your average up; the table below shows how many strong classes it takes to reach a {target:.1f}."),
        h(3, "Sophomore"),
        p(f"You are about halfway through, and raising a {gs} is still realistic. Focus first on the classes where you are "
          "closest to the next grade, and turn in every assignment. Colleges also notice an upward trend."),
        h(3, "Junior"),
        p("Junior-year grades are the last full year most colleges see before you apply, so they count. Even if your overall "
          "GPA moves only a little, strong junior grades show an upward trend. Ask your counselor about retaking classes you failed."),
        h(3, "Senior"),
        p("Your GPA will not move much before applications, so plan around it: apply to colleges whose requirements you meet, "
          "including community colleges, and keep your senior grades up, because colleges see them and some offers depend on them."),
    ]


MILESTONE = [(1.95, 2.0), (2.45, 2.5), (2.95, 3.0), (3.45, 3.5), (3.65, 3.7), (3.85, 3.9)]


def raise_section(gs, g, tips):
    blocks = [h(2, f"How to raise a {gs} GPA")]
    if g >= 4.0:
        blocks.append(p(f"A 4.0 is the top of the unweighted scale, so the goal is to keep it: any grade below an A lowers it. "
                        f"On a weighted scale, Honors, AP or IB classes can take you above 4.0; see the {a(WEIGHTED, 'Weighted GPA calculator')}."))
    else:
        target = next((t for lim, t in MILESTONE if g < lim), 3.95 if g < 3.95 else None)
        grades = [("A", 4.0), ("B+", 3.3), ("B", 3.0)]
        usable = [(n, v) for n, v in grades if v > target][:2]
        rows = []
        for done in (8, 16, 24):
            row = [str(done)]
            for _, v in usable:
                row.append(str(math.ceil(done * (target - g) / (v - target) - 1e-9)))
            rows.append(row)
        head = ["Classes so far"] + [f"Classes of {n}'s needed" for n, _ in usable]
        ts = f"{target:.2f}".rstrip("0") if target == 3.95 else f"{target:.1f}"
        blocks.append(p(f"The more classes you have finished, the more it takes to move your GPA. This table shows how many more "
                        f"classes you would need at one grade to bring a {gs} up to a {ts}, assuming every class counts the same."))
        blocks.append(table(head, rows, f"From a {gs} to a {ts}, unweighted, equal-credit classes. Your school's credits may differ."))
        blocks.append(p(f"To use your own classes, credits and target, try the {a(RAISE, 'Raise GPA calculator')}."))
    if tips:
        blocks += tips
    return blocks


# ---------------------------------------------------------------- reuse from the current page
def parse(content):
    return [(m.group(1), m.group(0)) for m in re.finditer(r"<!-- wp:([\w/-]+)[^>]*-->\n.*?\n<!-- /wp:\1 -->", content, re.S)]


def text(html):
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>|<!--.*?-->", " ", html, flags=re.S)).strip()


def build(slug, content, fig):
    g = int(slug[0]) + int(slug[2]) / 10
    gs = f"{slug[0]}.{slug[2]}"
    letter, pct = fig
    blocks = parse(content)
    intro = blocks[0][1]
    tbl = next(b for n, b in blocks if n == "table" and "gpa-scale-table" in b)
    i_tbl = [b for _, b in blocks].index(tbl)
    after = blocks[i_tbl + 1][1] if i_tbl + 1 < len(blocks) else ""
    weighted_note = after if ("Weighted or unweighted?" in after or "higher than 4.0" in after) else ""
    i_faq = next((i for i, (n, b) in enumerate(blocks) if n == "heading" and re.search(r"frequently asked", b, re.I)), len(blocks))
    faq = [b for _, b in blocks[i_faq:]]
    # Freshman-Senior notes. 3.5+ pages keep their own (a "Freshman:" label block followed by its paragraph);
    # below 2.6 the old notes contain wrong statements, so they are replaced with new ones.
    years = []
    if g >= 3.5:
        for i, (n, b) in enumerate(blocks[:i_faq]):
            m = re.fullmatch(r"(Freshman|Sophomore|Junior|Senior):?", text(b))
            if m and i + 1 < len(blocks):
                years += [h(3, m.group(1)), blocks[i + 1][1]]
    elif g < 2.6 and any(re.match(r"(Freshman|Sophomore|Junior|Senior):", text(b)) for _, b in blocks[:i_faq]):
        years = year_notes(gs, g)
    # the page's own tips: the list right after a "How can I raise / improve" heading, plus its lead paragraph
    tips = []
    for i, (n, b) in enumerate(blocks[:i_faq]):
        if n == "heading" and re.search(r"raise|improve|boost", text(b), re.I):
            j = i + 1
            while j < i_faq and blocks[j][0] in ("paragraph", "list"):
                tips.append(blocks[j][1])
                j += 1
            break
    if tips:
        tips = [h(3, "What helps")] + tips
    else:
        tips = [h(3, "What helps"), ul(DEFAULT_TIPS[band(g)])]
    facts = ul([f"<strong>Letter grade:</strong> {letter}", f"<strong>Percentage:</strong> about {pct}",
                f"<strong>National average:</strong> 3.11 for 2019 high school graduates ({NAEP})",
                f"<strong>Scale:</strong> unweighted 4.0"])
    out = [intro, facts, tbl] + ([weighted_note] if weighted_note else [])
    out += is_it_good(gs, g, letter, pct)
    out += for_college(gs, g)
    out += years
    out += raise_section(gs, g, tips)
    out += faq
    return "\n\n".join(out) + "\n"


# Small text fixes inside kept blocks: slug -> {old: new}, each must match exactly once.
PAGE_FIXES = {
    "2-2-gpa": {"A 2.2 GPA signifies a 'C' average": "A 2.2 GPA signifies a 'C+' average"},
    "1-1-gpa": {"despite initial academ</p>": "despite initial academic struggles.</p>"},
}


def main():
    pages = json.load(open(sys.argv[1]))
    out = Path(sys.argv[2])
    out.mkdir(parents=True, exist_ok=True)
    figs = figures()
    # 4.0 already has its own modern structure (how to get / keep a 4.0), so it is left as it is.
    for slug in sys.argv[3:] or [x for x in sorted(pages, reverse=True) if x != "4-0-gpa"]:
        html = build(slug, pages[slug], figs[slug])
        for a_, b_ in PAGE_FIXES.get(slug, {}).items():
            assert html.count(a_) == 1, (slug, a_)
            html = html.replace(a_, b_)
        (out / f"{slug}.html").write_text(html)
        print(slug, len(text(pages[slug]).split()), "->", len(text(html).split()), "words")


if __name__ == "__main__":
    main()
