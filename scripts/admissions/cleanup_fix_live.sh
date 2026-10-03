#!/usr/bin/env bash
# Admissions cleanup QA (Digant's plan of 2026-10-03, step 1): fix the redirects the QA flagged (chains, 404s, closed
# colleges with clicks) from data/admissions/cleanup_qa/fixes.csv, unpublish the WordPress pages left under
# /admissions/, and move renamed colleges to addresses with their current name from
# data/admissions/cleanup_qa/renames.csv. Both lists come from scripts/admissions/cleanup_qa.py.
#
#   bash scripts/admissions/cleanup_fix_live.sh plan-all                dry run of all three on the server
#   bash scripts/admissions/cleanup_fix_live.sh apply-all               all three into one log, print its name
#   bash scripts/admissions/cleanup_fix_live.sh plan | apply            the redirect fixes alone
#   bash scripts/admissions/cleanup_fix_live.sh plan-pages | apply-pages
#   bash scripts/admissions/cleanup_fix_live.sh plan-renames [group]    the new addresses alone (group: renamed, the
#   bash scripts/admissions/cleanup_fix_live.sh apply-renames [group]   default; optional; or all)
#   bash scripts/admissions/cleanup_fix_live.sh revert <log>            put everything in that log back
#
# Each apply writes its log (every rule, address and page status as it was) to ~/backups/ on the server and
# ~/gpacalculator-backups/ on this Mac, then clears the redirect, page and sitemap caches. Take a database backup first
# and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="$REPO/scripts/admissions/cleanup_fix_live.php"
FIXES="$REPO/data/admissions/cleanup_qa/fixes.csv"
RENAMES="$REPO/data/admissions/cleanup_qa/renames.csv"
GROUP="${2:-renamed}"

run() { "${SSH[@]}" "cd $APP && wp eval-file - $*" < "$PHP"; }
after() {
  "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
  bash "$REPO/scripts/admissions/phase4_dates_live.sh" clear
}
NAME="admissions-cleanup-$(date -u +%Y%m%d-%H%M%S)"
LOG="~/backups/$NAME-log.tsv"
send() {
  "${SCP[@]}" "$FIXES" "$HOST:backups/$NAME-fixes.csv"
  "${SCP[@]}" "$RENAMES" "$HOST:backups/$NAME-renames.csv"
}
keep() {
  after
  mkdir -p "$LOCAL"
  "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
  echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv"
}

case "${1:-}" in
  plan-all)
    send
    run plan "~/backups/$NAME-fixes.csv"
    run plan-pages
    run plan-renames "~/backups/$NAME-renames.csv" renamed ;;
  apply-all)
    send
    run apply "~/backups/$NAME-fixes.csv" "$LOG"
    run apply-pages "$LOG"
    run apply-renames "~/backups/$NAME-renames.csv" renamed "$LOG"
    keep ;;
  plan)
    send
    run plan "~/backups/$NAME-fixes.csv" ;;
  apply)
    send
    run apply "~/backups/$NAME-fixes.csv" "$LOG"
    keep ;;
  plan-pages)
    run plan-pages ;;
  apply-pages)
    run apply-pages "$LOG"
    keep ;;
  plan-renames)
    send
    run plan-renames "~/backups/$NAME-renames.csv" "$GROUP" ;;
  apply-renames)
    send
    run apply-renames "~/backups/$NAME-renames.csv" "$GROUP" "$LOG"
    keep ;;
  revert)
    OLD="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$OLD"
    run revert "~/backups/$OLD"
    after ;;
  *)
    sed -n '2,17p' "$0"; exit 1 ;;
esac
