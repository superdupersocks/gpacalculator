#!/usr/bin/env bash
# Admissions Phase 3: put how each college's first-year students' high school GPAs were spread (its own Common Data
# Set, C11; data/admissions/audit/phase3_gpa_bands.csv, made by scripts/admissions/phase3_gpa_bands.py) into its
# post's cds_gpa_band_* fields. The college page shows them under the average GPA it already cites from the same file.
#
#   bash scripts/admissions/phase3_bands_live.sh plan          dry run on the server: the post each row would update
#   bash scripts/admissions/phase3_bands_live.sh apply         write the fields, print the log name
#   bash scripts/admissions/phase3_bands_live.sh revert <log>  put every logged field back as it was
#
# Each apply writes its log (the old value of every field it writes) to ~/backups/ on the server and
# ~/gpacalculator-backups/ on this Mac. Take a database backup first and log each run in docs/LIVE_CHANGELOG.md.
# Needs the Phase 3 templates live (scripts/admissions/deploy_phase3.sh), which show the fields.
# Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ROWS="$REPO/data/admissions/audit/phase3_gpa_bands.csv"
PHP="$REPO/scripts/admissions/phase3_bands_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-bands-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ROWS" "$HOST:backups/$NAME-bands.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-bands.csv ~/backups/$NAME-log.tsv" < "$PHP"
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
