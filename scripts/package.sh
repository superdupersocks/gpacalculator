#!/usr/bin/env bash
# Build installable zips into dist/ (upload in WP admin, "Replace current with uploaded"):
#   gpacalculator-manager-<ver>.zip   the one calculator plugin ("Grade + GPA"), engine included
#   generatepress-child-<ver>.zip     the child theme (site design + gpa-design-tokens.css)
# Both are built from the imported live source, and only when every shortcode in
# shortcodes.lock is still served. legacy/ (old plugins kept for reference) is never shipped.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO=$(pwd)
THEME=child-theme/generatepress-child
PLUGIN=plugin/gpacalculator-manager
DIST=$REPO/dist

rm -rf "$DIST" && mkdir -p "$DIST"
python3 tests/check_shortcodes.py

if command -v php >/dev/null; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "PHP syntax error: $f"; exit 1; }; done \
    < <(find plugin child-theme -name '*.php' -print0)
fi

if [[ -f $THEME/style.css && -f $THEME/functions.php ]]; then
  TVER=$(sed -n 's/^Version: *//p' $THEME/style.css | head -1)
  (cd child-theme && zip -qrX "$DIST/generatepress-child-$TVER.zip" generatepress-child -x '*/README.md' -x '*.DS_Store' -x '*.zip')
  echo "built dist/generatepress-child-$TVER.zip"
else
  echo "skipped generatepress-child.zip: live theme not imported yet (needs style.css + functions.php)"
fi

if grep -lq "Plugin Name:" $PLUGIN/*.php 2>/dev/null; then
  grep -lq "includes/bootstrap.php" $PLUGIN/*.php || echo "warning: main plugin file doesn't load includes/bootstrap.php yet"
  PVER=$(sed -n 's/^ \* Version: *//p' $PLUGIN/gpacalculator-manager.php | head -1)
  (cd plugin && zip -qrX "$DIST/gpacalculator-manager-$PVER.zip" gpacalculator-manager \
     -x 'gpacalculator-manager/assets/calc-assets/_starter/*' -x '*.DS_Store')
  echo "built dist/gpacalculator-manager-$PVER.zip"
else
  echo "skipped gpacalculator-manager.zip: live plugin not imported yet"
fi
