#!/usr/bin/env bash
# Deploy the child theme from a repo commit to the live site, with a backup and a one-command revert.
#
#   bash scripts/deploy_theme.sh <commit> [--dry-run]   deploy child-theme/generatepress-child at <commit>
#   bash scripts/deploy_theme.sh --revert <backup-name>  restore a backup made by a previous deploy
#   bash scripts/deploy_theme.sh --list                  list theme backups on the server
#
# Each deploy first saves the live theme to ~/backups/theme-<timestamp>-<commit>.tar.gz on the server (outside
# the web root), rsyncs the commit's theme over it (zips excluded, nothing deleted), runs php -l on every PHP
# file, purges the Breeze cache and prints the URLs to check. Add the deploy to docs/LIVE_CHANGELOG.md.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
THEME="wp-content/themes/generatepress-child"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
REPO="$(cd "$(dirname "$0")/.." && pwd)"

case "${1:-}" in
  --list)
    "${SSH[@]}" "ls -lt ~/backups/theme-*.tar.gz 2>/dev/null | head -20"
    exit 0 ;;
  --revert)
    NAME="${2:?backup name, e.g. theme-20261001-2130-1e70338}"
    "${SSH[@]}" "set -e; cd $APP && test -f ~/backups/$NAME.tar.gz && tar xzf ~/backups/$NAME.tar.gz -C $THEME && \
      for f in \$(find $THEME -name '*.php'); do php -l \$f >/dev/null || { echo \"PHP ERROR in \$f\"; exit 1; }; done && \
      wp breeze purge --cache=all && echo reverted to $NAME"
    exit 0 ;;
  ""|-*)
    sed -n '2,10p' "$0"; exit 1 ;;
esac

COMMIT="$(git -C "$REPO" rev-parse --short "$1")"
DRY=""; [[ "${2:-}" == "--dry-run" ]] && DRY="--dry-run"
STAMP="$(date -u +%Y%m%d-%H%M)"
NAME="theme-$STAMP-$COMMIT"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT

git -C "$REPO" archive "$COMMIT" child-theme/generatepress-child | tar x -C "$WORK"
SRC="$WORK/child-theme/generatepress-child/"

echo "Changes that $COMMIT would make on the live theme:"
rsync -rlcn --itemize-changes --exclude='*.zip' --exclude='README.md' -e "ssh -i $KEY -o IdentitiesOnly=yes" "$SRC" "$HOST:$APP/$THEME/"
[[ -n "$DRY" ]] && { echo "(dry run: nothing changed)"; exit 0; }

"${SSH[@]}" "set -e; cd $APP/$THEME && tar czf ~/backups/$NAME.tar.gz --exclude='*.zip' . && echo backup ~/backups/$NAME.tar.gz"
rsync -rlc --exclude='*.zip' --exclude='README.md' -e "ssh -i $KEY -o IdentitiesOnly=yes" "$SRC" "$HOST:$APP/$THEME/"
if ! "${SSH[@]}" "cd $APP && for f in \$(find $THEME -name '*.php'); do php -l \$f >/dev/null || { echo \"PHP ERROR in \$f\"; exit 1; }; done"; then
  echo "PHP syntax error after deploy: reverting"; "$0" --revert "$NAME"; exit 1
fi
"${SSH[@]}" "cd $APP && wp breeze purge --cache=all"
echo "Deployed $COMMIT. Revert with: bash scripts/deploy_theme.sh --revert $NAME"
echo "Now check: https://gpacalculator.net/ , a /gpa-scale/ page, a calculator page (loads, calculator works, ads show)."
