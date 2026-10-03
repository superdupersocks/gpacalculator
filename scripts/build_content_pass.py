"""Build the WP-CLI script for the third /gpa-scale/ pass (see content_pass_gpa_scale.php).

    python3 scripts/build_content_pass.py PAGES_JSON [--save] > pass.php

PAGES_JSON is {slug: post_content} read from the site, used to find the exact text of each sentence that
pointed at the removed college search tool, so the PHP side can replace it byte for byte.
"""
import json
import re
import sys

HOME = "https://gpacalculator.net/"
RAISE = "https://gpacalculator.net/how-to-raise-gpa/"
WEIGHTED = "https://gpacalculator.net/weighted-gpa-calculator/"
SOURCE = "https://www.nationsreportcard.gov/hstsreport/"


def a(url, text):
    return f'<a href="{url}">{text}</a>'


# old sentence pattern (X = the page's GPA) -> replacement
REWRITES = [
    (r"You might not have made up your mind about your college choice for now, but you can make use of our search tool in the next section to check the colleges that catch your fantasy and also view how your present GPA can determine your admission chances\.",
     f"You may not have chosen colleges yet, and that's fine: use our {a(HOME, 'GPA calculator')} each term to see where your GPA is heading."),
    (r"You can also view your admission chances at any school of your choice with our search tool in the next category\.",
     f"To see which grades would move it higher from here, try our {a(RAISE, 'Raise GPA calculator')}."),
    (r"If you have some schools in mind that you will like to apply, you can search for them in the next segment and discover the prospects of your chances\.",
     f"If you have schools in mind, compare your GPA with their published ranges, and use the {a(RAISE, 'Raise GPA calculator')} to see what this year's grades can still change."),
    (r"If you want to know more about your chances of admissions where you want to submit your application or schools that have already received your application, you can search for it in the next segment and see your chances of securing the admission\.",
     f"If you are still finishing senior-year classes, the {a(HOME, 'GPA calculator')} shows how your final grades will change the GPA colleges see."),
    (r"If you(?:'|’)re currently interested in any standard school, you can use our search tool in the next section to look them up\.",
     f"If you already have schools in mind, look up their admission requirements and track your progress with our {a(HOME, 'GPA calculator')}."),
    (r"The tool in the next section can help you calculate how much credit you need to have before your senior year\.",
     f"Our {a(RAISE, 'Raise GPA calculator')} shows the grades you would need in your remaining credits to reach a target GPA before senior year."),
    (r"You can use our search tool in the next section to check out schools that interest you and find out what your chances are in being accepted there\.",
     f"Use the {a(RAISE, 'Raise GPA calculator')} to see what grades would bring your GPA up to the level the schools you like expect."),
    (r"If you want to view your chances into any of school of choice, go ahead and check your eligibility into these colleges in the next section\.",
     f"To see how much this year's grades can still move your GPA, try the {a(RAISE, 'Raise GPA calculator')}."),
]

CITE = f" (3.11 for the class of 2019, according to the {a(SOURCE, 'NAEP High School Transcript Study')})"


def weighted_note(gpa):
    if gpa == "4.0":
        return ("<strong>Can a GPA be higher than 4.0?</strong> Yes, on a weighted scale. Many schools add points for "
                "Honors, AP or IB classes (an A in an AP class can count as 5.0), so weighted GPAs above 4.0 are common. "
                f"On the unweighted scale in this table, 4.0 is the top. Work out both with our {a(WEIGHTED, 'Weighted GPA calculator')}.")
    return ("<strong>Weighted or unweighted?</strong> This table uses the standard unweighted 4.0 scale. If your school adds "
            "points for Honors, AP or IB classes, your weighted GPA can be higher than your unweighted one, so a "
            f"{gpa} weighted GPA usually stands for a lower unweighted average than a {gpa} unweighted. Check which one your "
            f"transcript shows, or work out both with our {a(WEIGHTED, 'Weighted GPA calculator')}.")


pages = json.load(open(sys.argv[1]))
sentences, weighted = {}, {}
for slug, html in pages.items():
    gpa = f"{slug[0]}.{slug[2]}"
    pairs = {}
    for pat, new in REWRITES:
        for m in re.finditer(pat, html):
            pairs[m.group(0)] = new
    if pairs:
        sentences[slug] = pairs
    if float(gpa) >= 3.5:
        weighted[slug] = weighted_note(gpa)

total = sum(len(v) for v in sentences.values())
print(f"{total} sentences on {len(sentences)} pages, {len(weighted)} weighted notes", file=sys.stderr)

php = open(__file__.replace("build_content_pass.py", "content_pass_gpa_scale.php")).read()
head = (
    "define( 'GPC_SAVE', " + ("true" if "--save" in sys.argv else "false") + " );\n"
    "define( 'CITE', " + repr(CITE) + " );\n"
    "$sentences = json_decode( " + repr(json.dumps(sentences, ensure_ascii=False)) + ", true );\n"
    "$weighted = json_decode( " + repr(json.dumps(weighted, ensure_ascii=False)) + ", true );\n"
)
marker = "// Filled in by the build script"
sys.stdout.write(php.replace(marker, head + marker, 1))
