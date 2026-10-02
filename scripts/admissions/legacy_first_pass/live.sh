#!/usr/bin/env bash
# Old /admission/<slug> addresses with no post under that slug: one Rank Math rule each, from
# data/admissions/redirects/legacy_actions.csv (301 to the college's current page, or 410 for a college IPEDS lists
# with a closing date). legacy_held.csv lists the addresses left unchanged and why.
#
#   bash scripts/admissions/legacy_redirects_live.sh plan           dry run on the server
#   bash scripts/admissions/legacy_redirects_live.sh apply          add the rules, print the log name
#   bash scripts/admissions/legacy_redirects_live.sh revert <log>   delete the logged rules
#
# Take a database backup first and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as
# scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
ACTIONS="$REPO/scripts/admissions/legacy_first_pass/actions.csv"
PHP="$REPO/scripts/admissions/legacy_first_pass/live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-legacy-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ACTIONS" "$HOST:backups/$NAME-actions.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-actions.csv ~/backups/$NAME-log.tsv" < "$PHP"
    if [[ "$1" == apply ]]; then
      "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
      mkdir -p "$LOCAL"
      "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
      echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv"
    fi ;;
  revert)
    LOG="${2:?the log name, e.g. admissions-legacy-20261002-070000-log.tsv}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$LOG && wp eval-file - revert ~/backups/$LOG && wp cache flush && wp breeze purge --cache=all" < "$PHP" ;;
  *)
    sed -n '2,11p' "$0"; exit 1 ;;
esac
