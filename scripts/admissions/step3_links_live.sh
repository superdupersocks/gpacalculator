#!/usr/bin/env bash
# Admissions step 3: put each college's official admissions link (data/admissions/audit/step3_admissions_links.csv from
# scripts/admissions/step3_links.py pick, checked from GitHub by admissions-links.yml) on its page as
# college_admissions_url and college_admissions_url_kind, which template v2 shows under "Before you apply". Only those
# fields change, on published college pages with an IPEDS ID; template v1 doesn't read them.
#
#   bash scripts/admissions/step3_links_live.sh plan          dry run on the server: how many pages would change
#   bash scripts/admissions/step3_links_live.sh apply         write the fields, print the log name
#   bash scripts/admissions/step3_links_live.sh revert <log>  put every logged value back
#
# Each apply writes its log (each page's old values) to ~/backups/ on the server and ~/gpacalculator-backups/ on this
# Mac. Take a database backup first and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as
# scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ROWS="$REPO/data/admissions/audit/step3_admissions_links.csv"
PHP="$REPO/scripts/admissions/step3_links_live.php"

case "${1:-}" in
  plan|apply)
    NAME="admissions-links-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$ROWS" "$HOST:backups/$NAME-links.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 ~/backups/$NAME-links.csv ~/backups/$NAME-log.tsv" < "$PHP"
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
