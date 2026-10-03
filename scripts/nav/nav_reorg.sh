#!/usr/bin/env bash
# Header + footer reorganization (Digant, 2026-10-03). Menus only; the theme part (site-nav.php/.css/.js and the
# one require line in functions.php) deploys with scripts/deploy_theme.sh.
#
#   bash scripts/nav/nav_reorg.sh inspect          read only: menus, locations, footer widgets, copyright hook
#   bash scripts/nav/nav_reorg.sh plan             read only: every new link resolved, unlinked calculators listed
#   bash scripts/nav/nav_reorg.sh backup           database backup + menu backup (JSON); prints the backup name
#   bash scripts/nav/nav_reorg.sh apply <name>     build the new menus and switch header, footer and bottom bar
#   bash scripts/nav/nav_reorg.sh revert <name>    switch everything back to the old menus (they are never edited)
#   bash scripts/nav/nav_reorg.sh legal            add Terms + Data sources to the bottom bar once both are published
#
# Backups go to ~/backups/ on the server and ~/gpacalculator-backups/ on this Mac. Log every live run in
# docs/LIVE_CHANGELOG.md. Uses the same SSH key as scripts/deploy_theme.sh.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
SCP=(scp -q -i "$KEY" -o IdentitiesOnly=yes)
LOCAL="$HOME/gpacalculator-backups"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="$REPO/scripts/wp/nav_reorg.php"
PURGE="wp cache flush && wp breeze purge --cache=all"

case "${1:-}" in
  inspect|plan)
    "${SSH[@]}" "cd $APP && wp eval-file - $1" < "$PHP" ;;
  backup)
    NAME="nav-$(date -u +%Y%m%d-%H%M%S)"
    "${SSH[@]}" "mkdir -p ~/backups && cd $APP && wp db export - | gzip > ~/backups/$NAME.sql.gz && wp eval-file - backup ~/backups/$NAME-menus.json" < "$PHP"
    mkdir -p "$LOCAL"
    "${SCP[@]}" "$HOST:backups/$NAME.sql.gz" "$HOST:backups/$NAME-menus.json" "$LOCAL/"
    shasum -a 256 "$LOCAL/$NAME.sql.gz" "$LOCAL/$NAME-menus.json"
    "${SSH[@]}" "sha256sum ~/backups/$NAME.sql.gz ~/backups/$NAME-menus.json"
    echo "backup name: $NAME" ;;
  apply|revert)
    NAME="${2:?the backup name printed by backup}"
    "${SSH[@]}" "cd $APP && test -s ~/backups/$NAME-menus.json && wp eval-file - $1 ~/backups/$NAME-menus.json && $PURGE" < "$PHP"
    [[ "$1" == apply ]] && echo "undo: bash $0 revert $NAME" ;;
  legal)
    "${SSH[@]}" "cd $APP && wp eval-file - legal && $PURGE" < "$PHP" ;;
  *)
    sed -n '2,13p' "$0"; exit 1 ;;
esac
