"""Import the live theme and plugins into the repo.

Usage:
  python3 scripts/import_live.py --theme generatepress-child.zip --plugin gpacalculator-manager.zip \
      [--calc-plugin calc-plugin.zip] [--grades-plugin grades-gpa-plugin.zip]

Each argument can be a zip or a folder.
- theme  -> child-theme/generatepress-child
- plugin -> plugin/gpacalculator-manager (the one plugin everything is merged into)
- calc-plugin / grades-plugin -> legacy/<name>: source to port from, never shipped

Repo-owned files (engine, shared core, starter, brand tokens) are kept unless the live copy
has its own version. Then every shortcode found is recorded in shortcodes.lock with its source.

The theme's calc-assets/ folder is imported as-is: calculators move into the plugin one at a
time (copy into plugin/.../assets/calc-assets, add to includes/calculators.php), and the old
copy stays as the fallback until the move is verified on the live page.
"""
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
TARGETS = {
    "theme": REPO / "child-theme" / "generatepress-child",
    "plugin": REPO / "plugin" / "gpacalculator-manager",
    "calc-plugin": REPO / "legacy" / "calc-plugin",
    "grades-plugin": REPO / "legacy" / "grades-gpa-plugin",
}
REPO_OWNED = {
    "theme": ["brand-tokens.css", "inc/brand-tokens.php", "README.md"],
    "plugin": ["assets/calc-assets/core", "assets/calc-assets/_starter", "includes/bootstrap.php",
               "includes/calculator-registry.php", "includes/calculator-assets.php", "includes/shortcodes.php",
               "includes/calculators.php", "README.md"],
    "calc-plugin": [],
    "grades-plugin": [],
}


def unpack(src, tmp):
    src = Path(src)
    if src.is_dir():
        return src
    out = Path(tmp) / src.stem
    with zipfile.ZipFile(src) as z:
        z.extractall(out)
    return out


def find_root(path, kind):
    """Folder holding style.css with 'Template:' (theme) or a PHP file with 'Plugin Name:' (plugin)."""
    if kind == "theme":
        cands = [p.parent for p in path.rglob("style.css") if "Template:" in p.read_text(errors="ignore")[:2000]]
    else:  # any plugin
        cands = [p.parent for p in path.rglob("*.php") if "Plugin Name:" in p.read_text(errors="ignore")[:4000]]
    if not cands:
        sys.exit(f"Could not find the {kind} root in {path}")
    return min(cands, key=lambda p: len(p.parts))


def copy(src, dst, kind):
    keep = {}
    for rel in REPO_OWNED[kind]:
        p = dst / rel
        if p.exists() and not (src / rel).exists():
            keep[rel] = p
    with tempfile.TemporaryDirectory() as hold:
        for rel, p in keep.items():
            (Path(hold) / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(p), str(Path(hold) / rel))
        if dst.exists():
            shutil.rmtree(dst)
        shutil.copytree(src, dst, ignore=shutil.ignore_patterns(".DS_Store", "__MACOSX", "*.log", "node_modules"))
        for rel in keep:
            (dst / rel).parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(Path(hold) / rel), str(dst / rel))


def main():
    import argparse
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    for kind in TARGETS:
        ap.add_argument(f"--{kind}", metavar="ZIP_OR_DIR")
    args = vars(ap.parse_args())
    if not any(args.values()):
        ap.error("nothing to import")
    with tempfile.TemporaryDirectory() as tmp:
        for kind, target in TARGETS.items():
            src = args[kind.replace("-", "_")]
            if not src:
                continue
            root = find_root(unpack(src, tmp), "theme" if kind == "theme" else "plugin")
            copy(root, target, kind)
            n = sum(1 for p in target.rglob("*") if p.is_file())
            print(f"{kind}: imported {n} files from {root}")
    subprocess.run([sys.executable, str(REPO / "tests" / "check_shortcodes.py"), "--update"], check=True)


if __name__ == "__main__":
    main()
