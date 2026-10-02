#!/usr/bin/env bash
# Ship the Phase 3 admissions templates (the /admissions/ hub and the college pages) without undoing a later theme
# deploy.
#
#   bash scripts/admissions/deploy_phase3.sh [--dry-run]
#
# Runs `scripts/deploy_theme.sh 52f5804 --only <the seven files below>` only while every one of those files on the
# live site is a version 52f5804 already contains (any earlier commit of that file on this branch, which merges the
# design branch) or isn't there yet (admissions.css). A live file this branch has never had, e.g. a newer
# functions.php from a later design deploy, stops it without changing the site: deploying over it would undo that
# deploy. The other theme files (the design overhaul's CSS) stay as they are live. After a deploy it clears Rank Math's
# sitemap cache, which a theme deploy doesn't, so the sitemaps rebuild with the new code (Phase 4 leaves the pages
# under review out of them).
set -euo pipefail

COMMIT="52f5804"
FILES="admissions.css,archive-colleges.php,template-parts/college-db-archive.php,single-colleges.php,college-data.php,functions.php,database-ajax.js"
HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
THEME="applications/xwnzegvpyy/public_html/wp-content/themes/generatepress-child"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
LIVE="$(mktemp)"; trap 'rm -f "$LIVE"' EXIT

git -C "$REPO" cat-file -e "$COMMIT^{commit}" 2>/dev/null || { echo "$COMMIT isn't in this clone: run git pull first."; exit 1; }
stop=0
for f in ${FILES//,/ }; do
  path="child-theme/generatepress-child/$f"
  status=0
  "${SSH[@]}" "if [ -f $THEME/$f ]; then cat $THEME/$f; else exit 3; fi" > "$LIVE" || status=$?
  if [[ $status -eq 3 ]]; then
    echo "  $f: not on the site yet"
    continue
  elif [[ $status -ne 0 ]]; then
    echo "Could not read the live $f: not deploying."
    exit 1
  fi
  live_blob="$(git -C "$REPO" hash-object "$LIVE")"
  known=0
  for c in $(git -C "$REPO" rev-list --full-history "$COMMIT" -- "$path"); do
    if [[ "$(git -C "$REPO" rev-parse -q --verify "$c:$path" 2>/dev/null)" == "$live_blob" ]]; then
      known=1
      [[ "$(git -C "$REPO" rev-parse "$COMMIT:$path")" == "$live_blob" ]] && echo "  $f: already this version" \
        || echo "  $f: live is $(git -C "$REPO" rev-parse --short "$c")'s, which $COMMIT includes"
      break
    fi
  done
  if [[ $known -eq 0 ]]; then
    echo "  $f: the live file has changes $COMMIT doesn't have (a later deploy?)"
    stop=1
  fi
done
if [[ $stop -eq 1 ]]; then
  echo "Not deploying, since that would undo them. Say so in the admissions thread: the branch needs the newer"
  echo "theme merged first."
  exit 1
fi
bash "$REPO/scripts/deploy_theme.sh" "$COMMIT" --only "$FILES" "$@"
[[ " $* " == *" --dry-run "* ]] || bash "$REPO/scripts/admissions/phase4_dates_live.sh" clear
