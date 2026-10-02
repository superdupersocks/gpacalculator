#!/usr/bin/env bash
# Ship the hub filter fix (623589d and 5b5018f, functions.php only) without undoing a later theme deploy.
#
#   bash scripts/admissions/deploy_hub_filters.sh [--dry-run]
#
# Runs `scripts/deploy_theme.sh 5b5018f --only functions.php` only while the live functions.php is still af793b4's
# (the 14:41 deploy). If 5b5018f's is already live, or a later theme deploy (such as the design overhaul's, which
# includes the fix) replaced the file with a version that has both filter fixes, there is nothing to do. Anything
# else stops without changing the site.
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

if same 5b5018f; then
  echo "5b5018f's functions.php is already live: nothing to do."
elif same af793b4; then
  exec bash "$REPO/scripts/deploy_theme.sh" 5b5018f --only functions.php "$@"
elif grep -qF "array( 0.01, 10 - 0.01 ), 'compare' => 'BETWEEN'" "$LIVE" &&
     grep -qF "array( 1, 1199 ), 'compare' => 'BETWEEN'" "$LIVE"; then
  echo "A later theme deploy already shipped the filter fix (the live functions.php has both): nothing to do."
else
  echo "The live functions.php is neither af793b4's nor 5b5018f's and lacks the filter fix: not deploying."
  echo "Say so in the admissions thread."
  exit 1
fi
