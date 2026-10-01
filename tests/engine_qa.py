"""Run the PHP tests for the plugin's calculator engine (tests/php/*_test.php; needs php)."""
import shutil
import subprocess
import sys
from pathlib import Path

if not shutil.which("php"):
    print("engine_qa: php not installed")
    sys.exit(1)
failed = 0
for t in sorted((Path(__file__).parent / "php").glob("*_test.php")):
    failed |= subprocess.run(["php", str(t)]).returncode
sys.exit(1 if failed else 0)
