"""Split a gpcm_university_profiles export (WP-CLI JSON) into data/gpcm-university-profiles/<id>.json."""
import json
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "data" / "gpcm-university-profiles"

profiles = json.loads(Path(sys.argv[1]).read_text())
for pid, profile in profiles.items():
    (OUT / f"{pid}.json").write_text(json.dumps(profile, indent=2, ensure_ascii=False) + "\n")
print(f"{len(profiles)} profiles written to {OUT}")
