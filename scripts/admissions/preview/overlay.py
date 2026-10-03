#!/usr/bin/env python3
"""What went live after Phase 2, on top of compose_state.py's posts, for the local preview WordPress.

    python3 scripts/admissions/preview/overlay.py STATE.json OUT.sql

compose_state.py rebuilds the college posts through Phase 2's last checkpoint (H). This adds, by slug, what the live
scripts wrote since: Phase 3's names (title and former_name, phase3_names.csv) and GPA spreads (phase3_gpa_bands.csv),
the cleanup's renamed addresses (cleanup_qa/renames.csv, group "renamed"), step 2's tier (admissions_tier,
tiering/tiers.csv) and, for previews of step 3, each college's official admissions link
(audit/step3_admissions_links.csv, not live yet). Changes nothing on the site.
"""
import csv
import json
import os
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..")
DATA = os.path.join(ROOT, "data", "admissions")


def rows(*parts):
    path = os.path.join(DATA, *parts)
    if not os.path.exists(path):
        return []
    with open(path, newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


def q(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def main():
    state = json.load(open(sys.argv[1], encoding="utf-8"))
    ids = {slug: p["id"] for slug, p in state.items() if p["status"] == "publish"}
    unitid = {p["fields"].get("ipeds_unitid"): p["id"] for p in state.values()
              if p["status"] == "publish" and p["fields"].get("ipeds_unitid")}
    out, meta = [], {}

    def put(pid, key, value):
        meta[(pid, key)] = value

    for r in rows("audit", "phase3_names.csv"):
        if r["slug"] in ids:
            out.append(f"UPDATE wp_posts SET post_title = {q(r['new_title'])} WHERE ID = {ids[r['slug']]};")
            if r["former_name"]:
                put(ids[r["slug"]], "former_name", r["former_name"])
    for r in rows("audit", "phase3_gpa_bands.csv"):
        if r["slug"] in ids:
            for k, v in r.items():
                if k.startswith("cds_gpa_band_") and v != "":
                    put(ids[r["slug"]], k, v)
    for r in rows("tiering", "tiers.csv"):
        pid = ids.get(r["old_slug"] or r["slug"]) or ids.get(r["slug"])
        if pid:
            put(pid, "admissions_tier", r["tier"])
            if r["recommendation"] == "noindex":
                put(pid, "rank_math_robots", 'a:1:{i:0;s:7:"noindex";}')
    for r in rows("audit", "step3_admissions_links.csv"):
        pid = unitid.get(r["ipeds_unitid"])
        if pid:
            put(pid, "college_admissions_url", r["college_admissions_url"])
            put(pid, "college_admissions_url_kind", r["college_admissions_url_kind"])
    for r in rows("cleanup_qa", "renames.csv"):
        if r["group"] == "renamed" and r["slug"] in ids and r["proposed_slug"]:
            out.append(f"UPDATE wp_posts SET post_name = {q(r['proposed_slug'])} WHERE ID = {ids[r['slug']]};")

    for (pid, key) in meta:
        out.append(f"DELETE FROM wp_postmeta WHERE post_id = {pid} AND meta_key = {q(key)};")
    values = [f"({pid}, {q(key)}, {q(v)})" for (pid, key), v in meta.items()]
    for i in range(0, len(values), 500):
        out.append("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES " + ", ".join(values[i:i + 500]) + ";")
    with open(sys.argv[2], "w", encoding="utf-8") as f:
        f.write("\n".join(out) + "\n")
    print(f"overlay: {len(meta)} fields on {len({p for p, _ in meta})} posts")


if __name__ == "__main__":
    main()
