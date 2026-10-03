#!/usr/bin/env bash
# Admissions Phase 2, step B2: put each college's own Common Data Set GPA (data/admissions/audit/phase2_b2_gpa.csv,
# made by scripts/admissions/phase2_b2_gpa.py) into its post's cds_gpa fields, which the theme shows labeled "as
# reported by the college" with the year, and cites.
#
#   bash scripts/admissions/phase2_b2_live.sh plan          dry run on the server: the post each row would update
#   bash scripts/admissions/phase2_b2_live.sh apply         write the fields, print the log name
#   bash scripts/admissions/phase2_b2_live.sh revert <log>  put every logged field back as it was
#
# Each apply writes its log (the old value of every field it writes) to ~/backups/ on the server and
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
ROWS="$REPO/data/admissions/audit/phase2_b2_gpa.csv"
PHP="$REPO/scripts/admissions/phase2_b2_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-b2-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ROWS" "$HOST:backups/$NAME-gpa.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-gpa.csv ~/backups/$NAME-log.tsv" < "$PHP"
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
