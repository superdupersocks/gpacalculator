"""Run the PHP tests for the plugin's calculator asset loader (needs php on PATH)."""
import shutil
import subprocess
import sys
from pathlib import Path

if not shutil.which("php"):
    print("loader_qa: php not installed")
    sys.exit(1)
sys.exit(subprocess.run(["php", str(Path(__file__).parent / "php" / "loader_test.php")]).returncode)
