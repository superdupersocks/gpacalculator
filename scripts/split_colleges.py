"""Split a colleges export (scripts/export_colleges.php output, JSON lines) into data/colleges/<slug>.json.

    ssh <server> 'cd applications/xwnzegvpyy/public_html && wp eval-file -' < scripts/export_colleges.php > colleges.jsonl
    python3 scripts/split_colleges.py colleges.jsonl
"""
import json
import sys
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / "data" / "colleges"

lines = [json.loads(line) for line in Path(sys.argv[1]).read_text().splitlines() if line.strip()]
summary = lines.pop()  # last line: ACF field keys + trash count
OUT.mkdir(parents=True, exist_ok=True)
for old in OUT.glob("*.json"):
    if not old.name.startswith("_"):
        old.unlink()
for college in lines:
    (OUT / f"{college['slug']}.json").write_text(json.dumps(college, indent=2, ensure_ascii=False) + "\n")
summary = {"published": len(lines), "trashed_not_exported": summary["__trash_count"],
           "acf_field_keys": summary["__field_keys"]}
(OUT / "_fields.json").write_text(json.dumps(summary, indent=2) + "\n")
print(f"{len(lines)} colleges written to {OUT}")
