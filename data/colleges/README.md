# College admissions records

The `colleges` post type (registered in the child theme's `functions.php`, served at
`/admissions/<slug>/` by `single-colleges.php` and `archive-colleges.php`), one JSON per published
college, exported from the live site on 2026-10-01: 3,586 published records. The 189 trashed records were
left out.

Each file has the post (`id`, `slug`, `title`, `url`, dates, `content`, `excerpt`) and `fields`: the
36 custom fields the templates read with `get_field()`. The fields are plain post meta. ACF is inactive
on the site and no ACF field groups exist in the database, so the theme ships a `get_field()` fallback
that reads `get_post_meta()`. `_fields.json` lists the ACF field keys the meta still references
(`field_college_*`), in case the field group is rebuilt.

The site reads these from its database, not from this folder. Refresh:

```
ssh master_rfzfmbbwze@67.205.161.226 'cd applications/xwnzegvpyy/public_html && wp eval-file -' < scripts/export_colleges.php > colleges.jsonl
python3 scripts/split_colleges.py colleges.jsonl
```

`data/college-db/college_db.json` is a separate, older dataset: the `gpa_college_db` table (1,529 rows)
that the legacy CollegeDB plugin's `[CollegeDB]` shortcode reads. Its links point to `/admission/<slug>`,
which 301-redirects to `/admissions/<slug>/`.
