#!/usr/bin/env bash
# Admissions step 3: which tiers get college template v2 (college-v2.php reads the option gpa_admissions_v2_tiers).
#
#   bash scripts/admissions/step3_switch.sh show       the tiers switched on now
#   bash scripts/admissions/step3_switch.sh set A      switch on tier A only (A,B for two tiers; A,B,C for all)
#   bash scripts/admissions/step3_switch.sh off        every page back to template v1 (deletes the option)
#
# Each set or off prints the value it replaced; clears the object and page caches. Log each change in
# docs/LIVE_CHANGELOG.md. Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")

show() { "${SSH[@]}" "cd $APP && (wp option get gpa_admissions_v2_tiers --format=json 2>/dev/null || echo 'not set (every page on v1)')"; }
purge() { "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"; }

case "${1:-}" in
  show) show ;;
  set)
    TIERS="${2:?tiers, e.g. A or A,B}"
    [[ "$TIERS" =~ ^[ABC](,[ABC]){0,2}$ ]] || { echo "tiers are A, B or C, comma-separated"; exit 1; }
    echo -n "was: "; show
    JSON="[\"${TIERS//,/\",\"}\"]"
    "${SSH[@]}" "cd $APP && wp option update gpa_admissions_v2_tiers '$JSON' --format=json --autoload=yes"
    purge
    echo -n "now: "; show ;;
  off)
    echo -n "was: "; show
    "${SSH[@]}" "cd $APP && wp option delete gpa_admissions_v2_tiers"
    purge ;;
  *) sed -n '2,9p' "$0"; exit 1 ;;
esac
