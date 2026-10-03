"""Guard: every shortcode the live site uses must keep working.

shortcodes.lock lists every shortcode found in the imported live code, with where it came
from ("tag  # source"). A shortcode counts as served when the consolidated plugin or theme
registers it: add_shortcode() in plugin/ or child-theme/, or a 'shortcodes' entry in the
calculator manifest (plugin/gpacalculator-manager/includes/calculators.php).

- theme / gpacalculator-manager shortcodes must always be served: missing ones fail.
- calcs-plugin ("Calculators") shortcodes are served by the old plugin until ported:
  missing ones are reported as "keep <plugin> active", and the plugin is reported as safe to
  deactivate once all of its shortcodes are served.

--update adds new shortcodes found in plugin/, child-theme/ and legacy/ (never removes any).
"""
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
LOCK = REPO / "shortcodes.lock"
MANIFEST = REPO / "plugin" / "gpacalculator-manager" / "includes" / "calculators.php"
ADD = re.compile(r"add_shortcode\s*\(\s*['\"]([^'\"]+)['\"]")
LEGACY = {"calcs-plugin", "CollegeDB.disabled"}
# Plugins whose shortcodes the engine serves wholesale through an adapter (it reads the plugin's
# own saved shortcode list), so every tag they registered counts as served.
ADAPTERS = {"calcs-plugin": REPO / "plugin" / "gpacalculator-manager" / "includes" / "legacy-calcs-plugin.php"}
# The Calculators plugin's Export Settings output from the live site (its saved shortcode list).
CALCS_EXPORT = REPO / "tests" / "fixtures" / "calcs-plugin-export.json"
CALC_ASSETS = REPO / "plugin" / "gpacalculator-manager" / "assets" / "calc-assets"


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
    adapted = {name for name, f in ADAPTERS.items() if f.exists()}
    missing = {t: s for t, s in lock.items() if t not in have and s not in adapted}
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
            how = " (via its saved shortcode list)" if name in adapted else ""
            print(f"  {name}: all {len(tags)} shortcodes served by the engine{how}; safe to deactivate once the plugin is live")
    hard.update(check_export(lock))
    if not lock:
        print("  (lock is empty until the live plugins and theme are imported)")
    return 1 if hard else 0


def check_export(lock):
    """Every exported Calculators-plugin shortcode is locked, and each calc-assets file it loads
    has a plugin copy (otherwise it would keep loading from the theme after deactivation)."""
    if not CALCS_EXPORT.exists():
        return {}
    bad = {}
    entries = json.loads(CALCS_EXPORT.read_text())
    for e in entries:
        tag = e["shortcode"].strip(" []")
        if tag not in lock:
            print(f"  NOT LOCKED [{tag}] (Calculators plugin export)")
            bad[tag] = "calcs-plugin"
        for key in ("js_path", "css_path"):
            m = re.search(r"/calc-assets/([A-Za-z0-9._-]+\.(?:js|css))(?:[?#]|$)", e.get(key, ""))
            if m and not (CALC_ASSETS / m.group(1)).is_file():
                print(f"  [{tag}]: {m.group(1)} has no plugin copy")
                bad[tag] = "calcs-plugin"
    print(f"  calcs-plugin export: {len(entries)} shortcodes, all locked with plugin copies" if not bad else "")
    return bad


if __name__ == "__main__":
    sys.exit(main())
