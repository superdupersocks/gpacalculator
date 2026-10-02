#!/usr/bin/env bash
# Admissions Phase 2, after E: add the college pages in data/admissions/audit/phase2_s_new.csv (made by
# scripts/admissions/phase2_s_pages.py), each published with its fields from phase2_s_pages.csv, so the pages C and E
# held can redirect to them (scripts/admissions/phase2_cd_live.sh S).
#
#   bash scripts/admissions/phase2_s_live.sh plan          dry run on the server: each page it would add, or why not
#   bash scripts/admissions/phase2_s_live.sh apply         add the pages, print the log name
#   bash scripts/admissions/phase2_s_live.sh revert <log>  move the pages that run added to the trash
#
# Each apply writes its log (the slug and post ID of each page added) to ~/backups/ on the server and
# ~/gpacalculator-backups/ on this Mac. Take a database backup first and log each run in docs/LIVE_CHANGELOG.md.
# Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
NEW="$REPO/data/admissions/audit/phase2_s_new.csv"
PAGES="$REPO/data/admissions/audit/phase2_s_pages.csv"
PHP="$REPO/scripts/admissions/phase2_s_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-s-pages-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$NEW" "$HOST:backups/$NAME-new.csv"
    "${SCP[@]}" "$PAGES" "$HOST:backups/$NAME-pages.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-new.csv ~/backups/$NAME-pages.csv ~/backups/$NAME-log.tsv" < "$PHP"
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
    sed -n '2,12p' "$0"; exit 1 ;;
esac
