# Must-use plugins (wp-content/mu-plugins/)

WordPress always loads these and no theme deploy touches them, so they hold rules that must survive a theme file
coming from any branch.

| File | Why |
|---|---|
| `gpa-rankmath-details.php` | Rank Math reads its schema blocks inside core Details blocks. Without it the collapsed "On this page" list (Details around the Rank Math TOC) loses its SiteNavigationElement schema. functions.php may carry the same filter; both together are harmless. |

Install or update (Digant runs it; back up first):

    scp -i ~/.ssh/gpacalculator_cloudways -o IdentitiesOnly=yes mu-plugins/gpa-rankmath-details.php master_rfzfmbbwze@67.205.161.226:applications/xwnzegvpyy/public_html/wp-content/mu-plugins/

Remove: delete the file from wp-content/mu-plugins/ on the server. Check after either: `python3 scripts/qa/check_toc_schema.py`.
