#!/usr/bin/env python3
"""Phase 2 of the design overhaul: replace hard-coded colors in theme stylesheets with var(--gpa-*) tokens.

    python3 scripts/design/migrate_colors.py FILE.css [...]      rewrite in place, print what changed
    python3 scripts/design/migrate_colors.py --check FILE.css     list colors still hard-coded (exit 1 if any)

Hex colors map to a palette token, or to a role token when the CSS property makes the role clear (text color,
background, border). rgba()/rgb() become color-mix() over a palette token. Purple maps to indigo (the accent).
Lines inside the brand palette block of gpa-design-tokens.css are never touched.
"""
import re, sys

# palette token for every hex the theme uses (upper-case, 6 digits)
PALETTE = {
    "FFFFFF": "white", "000000": "black",
    "F9FAFB": "gray-25", "F7F7FB": "gray-50", "F3F4F6": "gray-100", "E5E7EB": "gray-200", "D1D5DB": "gray-300",
    "9CA3AF": "gray-400", "6B7280": "gray-500", "475569": "gray-600", "4B5563": "gray-600", "374151": "gray-700",
    "1F2937": "gray-800", "111827": "gray-900",
    # slate folds into the grays (differences are not visible)
    "F8FAFC": "gray-25", "F1F5F9": "gray-100", "E2E8F0": "gray-200", "CBD5E1": "gray-300", "94A3B8": "slate-400",
    "64748B": "slate-500", "334155": "slate-700", "1E293B": "gray-800", "0F172A": "gray-900",
    "EFF6FF": "blue-50", "DBEAFE": "blue-100", "BFDBFE": "blue-200", "93C5FD": "blue-300", "60A5FA": "blue-400",
    "3B82F6": "blue-500", "2563EB": "blue-600", "1D4ED8": "blue-700", "1E40AF": "blue-800", "1E3A8A": "blue-900",
    "1E3A5F": "blue-900", "172554": "blue-950",
    "EEF2FF": "indigo-50", "E0E7FF": "indigo-100", "C7D2FE": "indigo-200", "6366F1": "indigo-500",
    "4338CA": "indigo-700", "3730A3": "indigo-800", "312E81": "indigo-900",
    # purple is retired: it maps onto indigo
    "FAF5FF": "indigo-50", "F5F3FF": "indigo-50", "F3E8FF": "indigo-50", "EDE9FE": "indigo-100",
    "E9D5FF": "indigo-200", "DDD6FE": "indigo-200", "8B5CF6": "indigo-500", "A78BFA": "indigo-500",
    "9333EA": "indigo-700", "7C3AED": "indigo-700", "6D28D9": "indigo-700", "5B21B6": "indigo-800",
    "6B21A8": "indigo-900", "581C87": "indigo-900", "4F46E5": "indigo-700",
    "F0FDF4": "green-50", "DCFCE7": "green-100", "D1FAE5": "green-100", "BBF7D0": "green-200", "4ADE80": "green-400",
    "22C55E": "green-500", "16A34A": "green-600", "059669": "green-600", "15803D": "green-700", "166534": "green-800",
    "065F46": "green-800", "14532D": "green-900",
    "CCFBF1": "teal-100", "99F6E4": "teal-200", "0D9488": "teal-600", "0F766E": "teal-700", "115E59": "teal-800",
    "FCE7F3": "pink-100", "DB2777": "pink-600", "BE185D": "pink-700", "9D174D": "pink-800", "E11D48": "rose-600",
    "FFFBEB": "amber-50", "FEF3C7": "amber-100", "FDE68A": "amber-200", "FBBF24": "amber-400", "F59E0B": "amber-500",
    "D97706": "amber-600", "B45309": "amber-700", "92400E": "amber-800", "78350F": "amber-900",
    "FFF7ED": "orange-50", "FFEDD5": "orange-100", "FED7AA": "orange-200", "EA580C": "orange-600",
    "C2410C": "orange-700", "9A3412": "orange-800",
    "FEF2F2": "red-50", "FEE2E2": "red-100", "FECACA": "red-200", "EF4444": "red-500", "DC2626": "red-600",
    "B91C1C": "red-700", "991B1B": "red-800",
}
# role token when the property says what the color is for
ROLES = {
    "text": {"111827": "text-strong", "1F2937": "text-strong", "0F172A": "text-strong", "1E293B": "text-strong",
             "374151": "text", "4B5563": "text-secondary", "475569": "text-secondary", "6B7280": "text-muted",
             "2563EB": "primary", "1D4ED8": "primary-hover", "1E3A8A": "heading", "7C3AED": "accent",
             "9333EA": "accent", "6D28D9": "accent", "4338CA": "accent"},
    "bg": {"FFFFFF": "surface", "F9FAFB": "surface-subtle", "F8FAFC": "surface-subtle", "EFF6FF": "tint-blue",
           "EEF2FF": "tint-indigo", "FAF5FF": "tint-indigo", "F5F3FF": "tint-indigo", "F3E8FF": "tint-indigo",
           "2563EB": "primary", "1D4ED8": "primary-hover", "7C3AED": "accent", "9333EA": "accent",
           "6D28D9": "accent", "4338CA": "accent", "F7F7FB": "page-bg"},
    "border": {"E5E7EB": "border", "E2E8F0": "border", "D1D5DB": "border-strong", "CBD5E1": "border-strong",
               "2563EB": "primary", "F3F4F6": "divider", "F1F5F9": "divider"},
}
PROP_KIND = [(re.compile(r"^(color|fill|stroke|-webkit-text-fill-color|caret-color|text-decoration-color)$"), "text"),
             (re.compile(r"^background(-color)?$"), "bg"),
             (re.compile(r"^(border|outline)(-(top|right|bottom|left))?(-color)?$"), "border")]

HEX = re.compile(r"#([0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b")
RGB = re.compile(r"rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\s*(?:[,/]\s*([\d.]+%?)\s*)?\)")


def norm(h):
    h = h.upper()
    if len(h) == 3:
        h = "".join(c * 2 for c in h)
    return h[:6], (h[6:] if len(h) == 8 else "")


def prop_at(text, pos):
    start = max(text.rfind(";", 0, pos), text.rfind("{", 0, pos), text.rfind("}", 0, pos)) + 1
    decl = text[start:pos]
    m = re.match(r"\s*(?:/\*.*?\*/\s*)*([-a-zA-Z]+)\s*:", decl, re.S)
    return m.group(1).lower() if m else ""


def token_for(hex6, prop):
    for rx, kind in PROP_KIND:
        if rx.match(prop) and hex6 in ROLES[kind]:
            return ROLES[kind][hex6]
    return PALETTE.get(hex6)


def protected_ranges(text):
    """The brand palette block in gpa-design-tokens.css and comments are left alone."""
    out = [(m.start(), m.end()) for m in re.finditer(r"/\*.*?\*/", text, re.S)]
    m = re.search(r"/\* 1\. BRAND PALETTE.*?/\* 2\. SITE ROLES \*/", text, re.S)
    if m:
        out.append((m.start(), m.end()))
    m = re.search(r"--gpa-hero-bg:.*?;", text, re.S)
    if m:
        out.append((m.start(), m.end()))
    m = re.search(r"--gpa-calc-shadow:.*?;|--gpa-footer-line:.*?;", text, re.S)
    for m in re.finditer(r"--gpa-(calc-shadow|footer-line|calc-shadow-pop):[^;]*;", text):
        out.append((m.start(), m.end()))
    return out


def migrate(text):
    prot = protected_ranges(text)
    inside = lambda i: any(a <= i < b for a, b in prot)  # noqa: E731
    changes, missing = [], []

    def hex_sub(m):
        if inside(m.start()):
            return m.group(0)
        hex6, alpha = norm(m.group(1))
        prop = prop_at(text, m.start())
        tok = token_for(hex6, prop)
        if not tok:
            missing.append(m.group(0)); return m.group(0)
        rep = f"var(--gpa-{tok})"
        if alpha:
            rep = f"color-mix(in srgb, {rep} {round(int(alpha, 16) / 255 * 100)}%, transparent)"
        changes.append(f"{m.group(0)} -> {rep}")
        return rep

    def rgb_sub(m):
        if inside(m.start()):
            return m.group(0)
        r, g, b, a = m.groups()
        hex6 = f"{int(r):02X}{int(g):02X}{int(b):02X}"
        tok = PALETTE.get(hex6)
        if not tok:
            missing.append(m.group(0)); return m.group(0)
        rep = f"var(--gpa-{tok})"
        if a is not None:
            pct = float(a[:-1]) if a.endswith("%") else float(a) * 100
            if pct < 100:
                rep = f"color-mix(in srgb, {rep} {pct:g}%, transparent)"
        changes.append(f"{m.group(0)} -> {rep}")
        return rep

    text = HEX.sub(hex_sub, text)
    prot = protected_ranges(text)
    text = RGB.sub(rgb_sub, text)
    return text, changes, missing


def remaining(text):
    prot = protected_ranges(text)
    return [m.group(0) for rx in (HEX, RGB) for m in rx.finditer(text)
            if not any(a <= m.start() < b for a, b in prot)]


def main(argv):
    check = argv and argv[0] == "--check"
    files = argv[1:] if check else argv
    bad = 0
    for f in files:
        text = open(f, encoding="utf-8").read()
        if check:
            left = remaining(text)
            bad += len(left)
            print(f"{f}: {len(left)} hard-coded colors" + (f" ({', '.join(sorted(set(left))[:20])})" if left else ""))
            continue
        new, changes, missing = migrate(text)
        open(f, "w", encoding="utf-8").write(new)
        print(f"{f}: {len(changes)} replaced, {len(missing)} left {sorted(set(missing))}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
