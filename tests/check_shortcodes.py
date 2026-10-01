"""Guard: every shortcode the live site registers must still be registered.

shortcodes.lock lists the shortcodes found in the imported live PHP. This check fails if
any of them is no longer registered by a PHP file in plugin/ or child-theme/.
Run with --update only when a shortcode is intentionally added (never to drop one).
"""
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
LOCK = REPO / "shortcodes.lock"
PATTERN = re.compile(r"add_shortcode\s*\(\s*['\"]([^'\"]+)['\"]")


def registered():
    found = {}
    for base in ("plugin", "child-theme"):
        for f in (REPO / base).rglob("*.php"):
            for name in PATTERN.findall(f.read_text(errors="ignore")):
                found.setdefault(name, str(f.relative_to(REPO)))
    return found


def locked():
    if not LOCK.exists():
        return []
    return [l.strip() for l in LOCK.read_text().splitlines() if l.strip() and not l.startswith("#")]


def main():
    found = registered()
    if "--update" in sys.argv:
        keep = sorted(set(locked()) | set(found))
        LOCK.write_text("# Shortcodes registered on gpacalculator.net. Never remove a line.\n" + "\n".join(keep) + "\n")
        print(f"shortcodes.lock: {len(keep)} shortcodes")
        return 0
    lock = locked()
    missing = [s for s in lock if s not in found]
    print(f"shortcodes: {len(found)} registered, {len(lock)} locked, {len(missing)} missing")
    for s in missing:
        print(f"  MISSING [{s}]")
    if not lock:
        print("  (lock is empty until the live plugin and theme PHP are imported)")
    return 1 if missing else 0


if __name__ == "__main__":
    sys.exit(main())
