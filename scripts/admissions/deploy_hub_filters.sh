#!/usr/bin/env bash
# Ship the hub filter fix (623589d and 5b5018f) and the sitemap tie-break (c5061f1), functions.php only, without
# undoing a later theme deploy.
#
#   bash scripts/admissions/deploy_hub_filters.sh [--dry-run]
#
# Runs `scripts/deploy_theme.sh c5061f1 --only functions.php` only while the live functions.php is still af793b4's
# (the 14:41 deploy) or 5b5018f's. If c5061f1's is already live, or a later theme deploy replaced the file with a
# version that has both filter fixes and the sitemap tie-break, there is nothing to do. A later version with the
# filter fixes but without the tie-break (the design overhaul's, until its branch takes c5061f1) stops without
# changing the site, since deploying over it would undo that deploy. Anything else stops too.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
FILE="applications/xwnzegvpyy/public_html/wp-content/themes/generatepress-child/functions.php"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
LIVE="$(mktemp)"; trap 'rm -f "$LIVE"' EXIT

"${SSH[@]}" "cat $FILE" > "$LIVE"
[[ -s "$LIVE" ]] || { echo "could not read the live functions.php: not deploying"; exit 1; }
same() { git -C "$REPO" show "$1:child-theme/generatepress-child/functions.php" | cmp -s - "$LIVE"; }
filters() {
  grep -qF "array( 0.01, 10 - 0.01 ), 'compare' => 'BETWEEN'" "$LIVE" &&
    grep -qF "array( 1, 1199 ), 'compare' => 'BETWEEN'" "$LIVE"
}
tiebreak() { grep -qF "ORDER BY p.post_modified DESC, p.ID DESC LIMIT" "$LIVE"; }

if same c5061f1; then
  echo "c5061f1's functions.php is already live: nothing to do."
elif same af793b4 || same 5b5018f; then
  exec bash "$REPO/scripts/deploy_theme.sh" c5061f1 --only functions.php "$@"
elif filters && tiebreak; then
  echo "A later theme deploy already shipped the filter fix and the sitemap tie-break: nothing to do."
elif filters; then
  echo "A later theme deploy shipped the filter fix but not the sitemap tie-break: not deploying, since that would"
  echo "undo it. Its branch needs c5061f1's functions.php change. Say so in the admissions thread."
  exit 1
else
  echo "The live functions.php is neither af793b4's, 5b5018f's nor c5061f1's and lacks the filter fix: not deploying."
  echo "Say so in the admissions thread."
  exit 1
fi
