#!/usr/bin/env bash
# Admissions Phase 2, checkpoints C and D: unpublish the posts in data/admissions/audit/phase2_cd_actions.csv (closed
# and merged colleges for C, duplicate pages for D) and redirect their addresses (410 Gone, or a 301 to the page named).
# For D, the page that stays first takes the fields listed in data/admissions/audit/phase2_d_consolidate.csv.
#
#   bash scripts/admissions/phase2_cd_live.sh plan <checkpoint>   dry run on the server: what each row would do
#   bash scripts/admissions/phase2_cd_live.sh apply <checkpoint>  copy D's fields, unpublish (kept as drafts), redirect
#   bash scripts/admissions/phase2_cd_live.sh check <checkpoint>  request both addresses of every row, compare
#   bash scripts/admissions/phase2_cd_live.sh revert <log>        undo the logged run: fields, posts and redirects
#
# C and D's list comes from scripts/admissions/phase2_cd_actions.py. S (pages held for a college that had no page here,
# redirected to the pages phase2_s_live.sh adds) and M (branch campuses redirected to their parent college's page) are
# in data/admissions/audit/phase2_s_actions.csv, from scripts/admissions/phase2_s_pages.py. R (closed colleges, and
# redirects to existing pages, from the identity review), P (redirects to S's pages) and N (redirects to the pages
# phase2_s_live.sh adds for N) are in phase2_r_actions.csv, from scripts/admissions/phase2_r_review.py. Each apply
# writes its log (post and redirect IDs) to ~/backups/ on the server and ~/gpacalculator-backups/ on this Mac. Take a
# database backup first and log each run in docs/LIVE_CHANGELOG.md. Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
CONSOLIDATE="$REPO/data/admissions/audit/phase2_d_consolidate.csv"
PHP="$REPO/scripts/admissions/phase2_cd_live.php"
SITE="https://gpacalculator.net"

checkpoint() {
  case "${1:-}" in C|D|S|M|R|P|N) echo "$1" ;; *) echo "checkpoint must be C, D, S, M, R, P or N" >&2; exit 1 ;; esac
}

actions() {
  case "$1" in
    S|M) echo "$REPO/data/admissions/audit/phase2_s_actions.csv" ;;
    R|P|N) echo "$REPO/data/admissions/audit/phase2_r_actions.csv" ;;
    *) echo "$REPO/data/admissions/audit/phase2_cd_actions.csv" ;;
  esac
}

case "${1:-}" in
  plan|apply)
    CP="$(checkpoint "${2:-}")"
    NAME="admissions-$(echo "$CP" | tr 'CDSMRPN' 'cdsmrpn')-$(date -u +%Y%m%d-%H%M%S)"
    "${SCP[@]}" "$(actions "$CP")" "$HOST:backups/$NAME-actions.csv"
    "${SCP[@]}" "$CONSOLIDATE" "$HOST:backups/$NAME-consolidate.csv"
    "${SSH[@]}" "cd $APP && wp eval-file - $1 $CP ~/backups/$NAME-actions.csv ~/backups/$NAME-log.tsv ~/backups/$NAME-consolidate.csv" < "$PHP"
    if [[ "$1" == apply ]]; then
      "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all"
      mkdir -p "$LOCAL"
      "${SCP[@]}" "$HOST:backups/$NAME-log.tsv" "$LOCAL/"
      echo "log $NAME-log.tsv in ~/backups/ on the server and $LOCAL/; undo: bash $0 revert $NAME-log.tsv"
    fi ;;
  check)
    CP="$(checkpoint "${2:-}")"
    bad=0; n=0
    while IFS=, read -r cp slug action target _; do
      [[ "$cp" == "$CP" ]] || continue
      for path in "/admissions/$slug/" "/admission/$slug/"; do
        got="$(curl -s -o /dev/null -L --max-redirs 5 -w '%{http_code} %{url_effective}' "$SITE$path")"
        if [[ "$action" == retire ]]; then want="410"; ok=$([[ "${got%% *}" == 410 ]] && echo y || echo n)
        else want="200 $target"; ok=$([[ "$got" == "200 $target" ]] && echo y || echo n); fi
        n=$((n + 1))
        if [[ "$ok" == n ]]; then bad=$((bad + 1)); echo "WRONG $path: got $got, want $want"; fi
        sleep 0.3
      done
    done < <(tail -n +2 "$(actions "$CP")")
    echo "$n addresses checked, $bad wrong" ;;
  revert)
    LOG="${2:?the log name printed by apply}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$LOG && wp eval-file - revert ~/backups/$LOG" < "$PHP"
    "${SSH[@]}" "cd $APP && wp cache flush && wp breeze purge --cache=all" ;;
  *)
    sed -n '2,18p' "$0"; exit 1 ;;
esac
