#!/usr/bin/env bash
# Admissions Phase 3: correct the college page titles that lost punctuation (Hardin Simmons University is
# Hardin-Simmons University) or still carry a former name (Calvin College is Calvin University), from
# data/admissions/audit/phase3_names.csv (scripts/admissions/phase3_names.py). A renamed college's old name goes into
# its post's former_name field, so the page says "(formerly ...)". Addresses don't change.
#
#   bash scripts/admissions/phase3_names_live.sh plan          dry run on the server: each title now and after
#   bash scripts/admissions/phase3_names_live.sh apply         change the titles, print the log name
#   bash scripts/admissions/phase3_names_live.sh revert <log>  put every logged title and former name back
#
# Each apply writes its log (the old value of every field it writes) to ~/backups/ on the server and
# ~/gpacalculator-backups/ on this Mac. Take a database backup first and log each run in docs/LIVE_CHANGELOG.md.
# Needs the Phase 3 templates live (scripts/admissions/deploy_phase3.sh), which show the former name.
# Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ROWS="$REPO/data/admissions/audit/phase3_names.csv"
PHP="$REPO/scripts/admissions/phase3_names_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-names-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ROWS" "$HOST:backups/$NAME-names.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-names.csv ~/backups/$NAME-log.tsv" < "$PHP"
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
    sed -n '2,14p' "$0"; exit 1 ;;
esac
