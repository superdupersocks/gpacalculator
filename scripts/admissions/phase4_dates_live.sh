#!/usr/bin/env bash
# Admissions Phase 4: give each college page the modified date of its last change, so the colleges sitemap's lastmod
# tells search engines the page changed. Phase 2 and 3 changed every page, the last of it from 22:32 UTC on 2 October
# 2026 (the new template), but the imports left the posts' dates alone: 2,996 sitemap addresses said 19 April 2026.
#
#   bash scripts/admissions/phase4_dates_live.sh plan          dry run on the server: how many dates would move, and
#                                                             how Rank Math stores its sitemap cache
#   bash scripts/admissions/phase4_dates_live.sh apply         move them, clear the sitemap cache, print the log name
#   bash scripts/admissions/phase4_dates_live.sh revert <log>  put every logged date back
#
# Each apply writes its log (each post's old dates) to ~/backups/ on the server and ~/gpacalculator-backups/ on this
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
PHP="$REPO/scripts/admissions/phase4_dates_live.php"
SINCE="2026-10-02T22:32:00"

case "${1:-}" in
  plan)
    "${SSH[@]}" "cd $APP && wp eval-file - plan $SINCE" < "$PHP" ;;
  apply)
    NAME="admissions-dates-$(date -u +%Y%m%d-%H%M%S)"
    "${SSH[@]}" "cd $APP && wp eval-file - apply $SINCE ~/backups/$NAME-log.tsv" < "$PHP"
    "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
    mkdir -p "$LOCAL"
    "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
    echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv" ;;
  revert)
    LOG="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$LOG && wp eval-file - revert ~/backups/$LOG" < "$PHP"
    "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all" ;;
  *)
    sed -n '2,12p' "$0"; exit 1 ;;
esac
