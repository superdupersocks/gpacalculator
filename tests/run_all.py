"""Run every QA check: the shortcode guard, then each tests/*_qa.py suite."""
import subprocess
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
checks = [HERE / "check_shortcodes.py"] + sorted(HERE.glob("*_qa.py"))
failed = []
for c in checks:
    print(f"== {c.name}", flush=True)
    if subprocess.run([sys.executable, str(c)], cwd=HERE).returncode:
        failed.append(c.name)
print("\nALL PASSED" if not failed else f"\nFAILED: {', '.join(failed)}")
sys.exit(1 if failed else 0)
