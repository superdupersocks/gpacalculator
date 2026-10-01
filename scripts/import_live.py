"""Import the live child theme and plugin into the repo.

Usage:
  python3 scripts/import_live.py <generatepress-child.zip|dir> [<gpacalculator-manager.zip|dir>]

Copies the live files over child-theme/generatepress-child and plugin/gpacalculator-manager,
keeping repo-owned files (calc-assets/core, calc-assets/_starter) unless the live copy has
its own version. Then records every registered shortcode in shortcodes.lock.
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
}
REPO_OWNED = ["calc-assets/core", "calc-assets/_starter", "README.md"]


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
    else:
        cands = [p.parent for p in path.rglob("*.php") if "Plugin Name:" in p.read_text(errors="ignore")[:4000]]
    if not cands:
        sys.exit(f"Could not find the {kind} root in {path}")
    return min(cands, key=lambda p: len(p.parts))


def copy(src, dst):
    keep = {}
    for rel in REPO_OWNED:
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
    if len(sys.argv) < 2:
        sys.exit(__doc__)
    with tempfile.TemporaryDirectory() as tmp:
        for kind, arg in zip(("theme", "plugin"), sys.argv[1:3]):
            root = find_root(unpack(arg, tmp), kind)
            copy(root, TARGETS[kind])
            n = sum(1 for _ in TARGETS[kind].rglob("*") if _.is_file())
            print(f"{kind}: imported {n} files from {root}")
    subprocess.run([sys.executable, str(REPO / "tests" / "check_shortcodes.py"), "--update"], check=True)


if __name__ == "__main__":
    main()
