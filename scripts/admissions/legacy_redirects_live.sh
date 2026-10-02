#!/usr/bin/env bash
# Admissions Phase 2: redirect the old /admission/<slug>/ addresses that no longer reach their college, from
# data/admissions/redirects/legacy_redirect_map.csv (made by scripts/admissions/legacy_redirects.py): one Rank Math rule
# per old slug for admission/<slug> and admissions/<slug>, a 301 to the college's page or 410 Gone.
#
#   bash scripts/admissions/legacy_redirects_live.sh plan          dry run on the server: what each row would add
#   bash scripts/admissions/legacy_redirects_live.sh apply         add the rules, print the log name
#   bash scripts/admissions/legacy_redirects_live.sh check         request both addresses of every row, compare
#   bash scripts/admissions/legacy_redirects_live.sh revert <log>  delete the rules the logged run added
#
# Each apply writes its log (one rule ID per row) to ~/backups/ on the server and ~/gpacalculator-backups/ on this Mac.
# Take a database backup first and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as
# scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
MAP="$REPO/data/admissions/redirects/legacy_redirect_map.csv"
PHP="$REPO/scripts/admissions/legacy_redirects_live.php"
SITE="https://gpacalculator.net"

case "${1:-}" in
  plan|apply)
    NAME="admissions-legacy-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$MAP" "$HOST:backups/$NAME-map.csv"
    if [[ "$1" == plan ]]; then
      "${SSH[@]}" "cd $APP && wp eval-file - plan ~/backups/$NAME-map.csv" < "$PHP"
    else
      "${SSH[@]}" "cd $APP && wp eval-file - apply ~/backups/$NAME-map.csv ~/backups/$NAME-log.tsv" < "$PHP"
      "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
      mkdir -p "$LOCAL"
      "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
      echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv"
    fi ;;
  check)
    bad=0; n=0
    while IFS=, read -r slug action target _; do
      for path in "/admission/$slug/" "/admissions/$slug/"; do
        got="$(curl -s -o /dev/null -L --max-redirs 5 -w '%{http_code} %{url_effective}' "$SITE$path")"
        if [[ "$action" == 410 ]]; then want="410"; ok=$([[ "${got%% *}" == 410 ]] && echo y || echo n)
        else want="200 $target"; ok=$([[ "$got" == "200 $target" ]] && echo y || echo n); fi
        n=$((n + 1))
        if [[ "$ok" == n ]]; then bad=$((bad + 1)); echo "WRONG $path: got $got, want $want"; fi
        sleep 0.3
      done
    done < <(tail -n +2 "$MAP")
    echo "$n addresses checked, $bad wrong" ;;
  revert)
    LOG="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$LOG && wp eval-file - revert ~/backups/$LOG" < "$PHP"
    "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all" ;;
  *)
    sed -n '2,13p' "$0"; exit 1 ;;
esac
