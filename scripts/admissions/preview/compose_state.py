#!/usr/bin/env python3
"""The college posts as they stand live after Phase 2, rebuilt offline for template previews.

    python3 scripts/admissions/preview/compose_state.py OUT.json [--sql OUT.sql]

Starts from the October 1 export in data/colleges/ and replays every Phase 2 change in the order it went live (see
docs/LIVE_CHANGELOG.md), with the same rules as the live scripts in scripts/admissions/: C and D (06:07), B2 (06:27
and 17:44), B (14:46), E (14:49), S, M, P and N (17:30-17:37), R (17:52) and H (18:01). Redirects and 410s are not
rebuilt; a post they retired is left as a draft, as on the site. Prints the count of published colleges, which
should match the site (3,085 after H).

OUT.json maps each slug to {id, title, status, date, modified, fields}. --sql also writes the published posts and
their fields as INSERTs into wp_posts and wp_postmeta, for a local WordPress used only for previews. Changes
nothing on the site.
"""
import argparse
import csv
import glob
import json
import os

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "..")
AUDIT = os.path.join(ROOT, "data", "admissions", "audit")
SITE = "https://gpacalculator.net/admissions/"
B_FIELDS = ("average_gpa", "admission_standards", "applicant_competition", "img_url")
B2_FIELDS = ("cds_gpa", "cds_gpa_year", "cds_gpa_submit_pct", "cds_gpa_basis", "cds_gpa_source_url")


def rows(name):
    with open(os.path.join(AUDIT, name), newline="", encoding="utf-8") as f:
        return list(csv.DictReader(f))


class State:
    def __init__(self):
        self.posts = {}
        for path in glob.glob(os.path.join(ROOT, "data", "colleges", "*.json")):
            if os.path.basename(path).startswith("_"):
                continue
            with open(path, encoding="utf-8") as f:
                p = json.load(f)
            self.posts[p["slug"]] = {"id": p["id"], "title": p["title"], "status": p["status"], "date": p["date"],
                                     "modified": p["modified"], "fields": dict(p.get("fields") or {})}
        self.next_id = max(p["id"] for p in self.posts.values()) + 1000
        self.log = []

    def published(self, slug):
        p = self.posts.get(slug)
        return p if p and p["status"] == "publish" else None

    def write(self, post, fields):
        """E's and B2's rule: a field changes only when its value does; an empty value never creates one."""
        n = 0
        for k, new in fields.items():
            have = post["fields"]
            if (k in have and str(have[k]) == new) or (k not in have and new == ""):
                continue
            have[k] = new
            n += 1
        return n

    def import_e(self, name):
        with open(os.path.join(AUDIT, name), newline="", encoding="utf-8") as f:
            reader = csv.reader(f)
            head = next(reader)
            fields = head[2:]
            done = skipped = 0
            for r in reader:
                row = dict(zip(head, r))
                post = self.published(row["slug"])
                if not post or post["title"] != row["post_title"]:
                    skipped += 1
                    continue
                if self.write(post, {k: row[k] for k in fields}):
                    done += 1
        self.log.append(f"E {name}: {done} posts changed, {skipped} skipped")

    def b2(self):
        done = 0
        for row in rows("phase2_b2_gpa.csv"):
            post = self.published(row["slug"])
            if post and self.write(post, {k: row[k] for k in B2_FIELDS}):
                done += 1
        self.log.append(f"B2: {done} posts changed")

    def b(self):
        n = 0
        for post in self.posts.values():
            for k in B_FIELDS:
                if str(post["fields"].get(k) or "") != "":
                    post["fields"][k] = ""
                    n += 1
        self.log.append(f"B: {n} values blanked")

    def cd(self, checkpoint, actions):
        consolidate = {}
        for r in rows("phase2_d_consolidate.csv"):
            consolidate.setdefault(r["from_slug"], []).append((r["field"], r["value"]))
        done = copied = 0
        for row in rows(actions):
            if row["checkpoint"] != checkpoint:
                continue
            post = self.published(row["slug"])
            if not post:
                continue
            if row["action"] == "301" and row["target"].startswith(SITE):
                target = self.published(row["target"][len(SITE):].strip("/"))
                for field, value in consolidate.get(row["slug"], []) if target else []:
                    if str(target["fields"].get(field) or "").strip() in ("", "-"):
                        target["fields"][field] = value
                        copied += 1
            post["status"] = "draft"
            done += 1
        self.log.append(f"{checkpoint}: {done} posts unpublished, {copied} fields copied")

    def add_pages(self, new, pages):
        values = {r["slug"]: r for r in rows(pages)}
        added = 0
        for row in rows(new):
            if row["slug"] in self.posts:
                continue
            fields = {k: v for k, v in values[row["slug"]].items() if k not in ("slug", "post_title") and v != ""}
            self.posts[row["slug"]] = {"id": self.next_id, "title": row["post_title"], "status": "publish",
                                       "date": "2026-10-02 17:30:00", "modified": "2026-10-02 17:30:00",
                                       "fields": fields}
            self.next_id += 1
            added += 1
        self.log.append(f"new pages from {new}: {added}")


def sql_str(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "''") + "'"


def write_sql(state, path):
    with open(path, "w", encoding="utf-8") as f:
        f.write("DELETE FROM wp_postmeta WHERE post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'colleges');\n")
        f.write("DELETE FROM wp_posts WHERE post_type = 'colleges';\n")
        for slug, p in sorted(state.posts.items()):
            if p["status"] != "publish":
                continue
            f.write("INSERT INTO wp_posts (ID, post_author, post_date, post_date_gmt, post_content, post_title, "
                    "post_excerpt, post_status, comment_status, ping_status, post_name, to_ping, pinged, post_modified, "
                    "post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type) VALUES "
                    f"({p['id']}, 1, {sql_str(p['date'])}, {sql_str(p['date'])}, '', {sql_str(p['title'])}, '', "
                    f"'publish', 'closed', 'closed', {sql_str(slug)}, '', '', {sql_str(p['modified'])}, "
                    f"{sql_str(p['modified'])}, '', 0, {sql_str('http://localhost/?p=' + str(p['id']))}, 0, 'colleges');\n")
            meta = [f"({p['id']}, {sql_str(k)}, {sql_str(v if v is not None else '')})" for k, v in sorted(p["fields"].items())]
            if meta:
                f.write("INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES " + ", ".join(meta) + ";\n")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("out")
    ap.add_argument("--sql")
    a = ap.parse_args()
    s = State()
    s.cd("D", "phase2_cd_actions.csv")
    s.cd("C", "phase2_cd_actions.csv")
    s.b()
    s.import_e("phase2_e_import.csv")
    s.add_pages("phase2_s_new.csv", "phase2_s_pages.csv")
    s.import_e("phase2_s_pages.csv")
    s.cd("S", "phase2_s_actions.csv")
    s.cd("M", "phase2_s_actions.csv")
    s.cd("P", "phase2_r_actions.csv")
    s.add_pages("phase2_n_new.csv", "phase2_n_pages.csv")
    s.import_e("phase2_n_pages.csv")
    s.cd("N", "phase2_r_actions.csv")
    s.b2()
    s.import_e("phase2_r_import.csv")
    s.cd("R", "phase2_r_actions.csv")
    s.import_e("phase2_h_blank.csv")
    for line in s.log:
        print(line)
    print(f"{sum(1 for p in s.posts.values() if p['status'] == 'publish')} published colleges")
    with open(a.out, "w", encoding="utf-8") as f:
        json.dump(s.posts, f, indent=0, sort_keys=True)
    if a.sql:
        write_sql(s, a.sql)


if __name__ == "__main__":
    main()
