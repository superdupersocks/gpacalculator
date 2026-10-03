#!/usr/bin/env bash
# College short names: write data/admissions/meta/short_names.csv (scripts/admissions/meta/short_names.py) to each
# college page's short_name field, which the theme's titles, descriptions and og tags use. Only that field changes.
#
#   bash scripts/admissions/meta/short_names_live.sh plan          dry run on the server: how many pages would change
#   bash scripts/admissions/meta/short_names_live.sh apply         write the field, print the log name
#   bash scripts/admissions/meta/short_names_live.sh revert <log>  put every logged value back
#
# Each apply writes its log (each page's old value) to ~/backups/ on the server and ~/gpacalculator-backups/ on this
# Mac. Take a database backup first and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as
# scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
ROWS="$REPO/data/admissions/meta/short_names.csv"
PHP="$REPO/scripts/admissions/meta/short_names_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-shortnames-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ROWS" "$HOST:backups/$NAME-short.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-short.csv ~/backups/$NAME-log.tsv" < "$PHP"
    if [[ "$1" == apply ]]; then
      "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
      mkdir -p "$LOCAL"
      "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
      echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv"
    fi ;;
  revert)
    LOG="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$LOG && wp eval-file - revert ~/backups/$LOG" < "$PHP"
    "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all" ;;
  *)
    sed -n '2,13p' "$0"; exit 1 ;;
esac
