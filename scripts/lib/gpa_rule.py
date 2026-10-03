"""Site-wide GPA letter/percentage rule (same as gpa_scale_figures() in gpa-shortcodes.php and gpa-scale-tools.js).

Chart: A+ 97–100 and A 93–96 both 4.0. Letter: nearest chart letter, both on a tie ("B+/A−"). Percentage: linear
between the midpoints of the chart letters' ranges, shown with "≈"; a GPA that is a chart value shows that range.
"""
import math

POINTS = [(4.0, "A", 94.5, "93–100%"), (3.7, "A−", 91, "90–92%"), (3.3, "B+", 88, "87–89%"), (3.0, "B", 84.5, "83–86%"),
          (2.7, "B−", 81, "80–82%"), (2.3, "C+", 78, "77–79%"), (2.0, "C", 74.5, "73–76%"), (1.7, "C−", 71, "70–72%"),
          (1.3, "D+", 68, "67–69%"), (1.0, "D", 64.5, "63–66%"), (0.7, "D−", 61, "60–62%"), (0.0, "F", 50, "Below 60%")]


def figures(gpa):
    g = round(float(gpa), 2)
    if g > 4.0:
        return ("Weighted", "")
    for p in POINTS:
        if abs(p[0] - g) < 0.001:
            return (p[1], p[3])
    for (hi, hl, hp, _), (lo, ll, lp, _) in zip(POINTS, POINTS[1:]):
        if lo < g < hi:
            dh, dl = hi - g, g - lo
            letter = f"{ll}/{hl}" if abs(dh - dl) < 0.001 else (hl if dh < dl else ll)
            return (letter, f"≈{math.floor(lp + (hp - lp) * (g - lo) / (hi - lo) + 0.5)}%")
    return ("F", "Below 60%")
