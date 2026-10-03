#!/usr/bin/env bash
# Admissions step 2 (Digant's plan of 2026-10-03): write each college page's tier and take the pages the tiering
# recommends out of search (Rank Math's No Index for the page, which also leaves it out of the sitemap), from
# data/admissions/tiering/tiers.csv (scripts/admissions/tiering.py).
#
#   bash scripts/admissions/tiering_live.sh plan            dry run on the server
#   bash scripts/admissions/tiering_live.sh apply           write them, print the log name
#   bash scripts/admissions/tiering_live.sh revert <log>    put every logged value back
#
# Each apply writes its log (every value as it was) to ~/backups/ on the server and ~/gpacalculator-backups/ on this
# Mac, then clears the page and sitemap caches. Take a database backup first and log each run in
# docs/LIVE_CHANGELOG.md. Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="$REPO/scripts/admissions/tiering_live.php"
TIERS="$REPO/data/admissions/tiering/tiers.csv"
NAME="admissions-tiers-$(date -u +%Y%m%d-%H%M%S)"

run() { "${SSH[@]}" "cd $APP && wp eval-file - $*" < "$PHP"; }
after() {
  "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
  bash "$REPO/scripts/admissions/phase4_dates_live.sh" clear
}

case "${1:-}" in
  plan)
    "${SCP[@]}" "$TIERS" "$HOST:backups/$NAME-tiers.csv"
    run plan "~/backups/$NAME-tiers.csv" ;;
  apply)
    "${SCP[@]}" "$TIERS" "$HOST:backups/$NAME-tiers.csv"
    run apply "~/backups/$NAME-tiers.csv" "~/backups/$NAME-log.tsv"
    after
    mkdir -p "$LOCAL"
    "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
    echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv" ;;
  revert)
    OLD="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$OLD"
    run revert "~/backups/$OLD"
    after ;;
  *)
    sed -n '2,12p' "$0"; exit 1 ;;
esac
