"""Guard: every shortcode the live site uses must keep working.

shortcodes.lock lists every shortcode found in the imported live code, with where it came
from ("tag  # source"). A shortcode counts as served when the consolidated plugin or theme
registers it: add_shortcode() in plugin/ or child-theme/, or a 'shortcodes' entry in the
calculator manifest (plugin/gpacalculator-manager/includes/calculators.php).

- theme / gpacalculator-manager shortcodes must always be served: missing ones fail.
- calc-plugin / grades-gpa-plugin shortcodes are served by the old plugin until ported:
  missing ones are reported as "keep <plugin> active", and the plugin is reported as safe to
  deactivate once all of its shortcodes are served.

--update adds new shortcodes found in plugin/, child-theme/ and legacy/ (never removes any).
"""
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
LOCK = REPO / "shortcodes.lock"
MANIFEST = REPO / "plugin" / "gpacalculator-manager" / "includes" / "calculators.php"
ADD = re.compile(r"add_shortcode\s*\(\s*['\"]([^'\"]+)['\"]")
LEGACY = {"calc-plugin", "grades-gpa-plugin"}


def php_files(base):
    return [f for f in (REPO / base).rglob("*.php")] if (REPO / base).exists() else []


def manifest_tags():
    if not MANIFEST.exists():
        return set()
    src = re.sub(r"/\*.*?\*/", "", MANIFEST.read_text(), flags=re.S)
    tags = set()
    for block in re.findall(r"['\"]shortcodes['\"]\s*=>\s*(?:array\s*\(|\[)(.*?)[\)\]]", src, flags=re.S):
        tags.update(re.findall(r"['\"]([^'\"]+)['\"]", block))
    return tags


def served():
    tags = set(manifest_tags())
    for base in ("plugin", "child-theme"):
        for f in php_files(base):
            tags.update(ADD.findall(f.read_text(errors="ignore")))
    return tags


def found_with_source():
    out = {}
    for f in php_files("child-theme"):
        for t in ADD.findall(f.read_text(errors="ignore")):
            out.setdefault(t, "theme")
    for f in php_files("plugin"):
        for t in ADD.findall(f.read_text(errors="ignore")):
            out.setdefault(t, "gpacalculator-manager")
    for name in sorted(LEGACY):
        for f in php_files(f"legacy/{name}"):
            for t in ADD.findall(f.read_text(errors="ignore")):
                out.setdefault(t, name)
    return out


def locked():
    out = {}
    if LOCK.exists():
        for line in LOCK.read_text().splitlines():
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            tag, _, src = line.partition("#")
            out[tag.strip()] = src.strip() or "theme"
    return out


def main():
    if "--update" in sys.argv:
        lock = locked()
        for t, src in found_with_source().items():
            lock.setdefault(t, src)
        width = max([len(t) for t in lock] + [10])
        body = "\n".join(f"{t.ljust(width)}  # {lock[t]}" for t in sorted(lock, key=lambda t: (lock[t], t)))
        LOCK.write_text("# Shortcodes used on gpacalculator.net, with the code that registered them.\n"
                        "# Never remove a line. See tests/check_shortcodes.py.\n" + body + ("\n" if body else ""))
        print(f"shortcodes.lock: {len(lock)} shortcodes")
        return 0

    lock, have = locked(), served()
    missing = {t: s for t, s in lock.items() if t not in have}
    hard = {t: s for t, s in missing.items() if s not in LEGACY}
    print(f"shortcodes: {len(lock)} locked, {len(lock) - len(missing)} served by the plugin/theme")
    for t, s in sorted(hard.items()):
        print(f"  MISSING [{t}] (was registered by {s})")
    for name in sorted(LEGACY):
        tags = [t for t, s in lock.items() if s == name]
        if not tags:
            continue
        left = sorted(t for t in tags if t in missing)
        if left:
            print(f"  {name}: {len(tags) - len(left)}/{len(tags)} ported; keep it active for " + ", ".join(f"[{t}]" for t in left))
        else:
            print(f"  {name}: all {len(tags)} shortcodes ported; safe to deactivate")
    if not lock:
        print("  (lock is empty until the live plugins and theme are imported)")
    return 1 if hard else 0


if __name__ == "__main__":
    sys.exit(main())
