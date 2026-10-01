#!/usr/bin/env bash
# Build installable zips into dist/.
#   calc-assets-core-vX.zip      always: drop-in for generatepress-child/calc-assets/core (additive, safe)
#   generatepress-child.zip      once the live theme (style.css + functions.php) is in the repo
#   gpacalculator-manager.zip    once the live plugin main file is in the repo
# The full theme/plugin zips replace what's on the site, so they are only built from the
# imported live source, and only when every shortcode in shortcodes.lock is still registered.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO=$(pwd)
THEME=child-theme/generatepress-child
PLUGIN=plugin/gpacalculator-manager
DIST=$REPO/dist
VER=$(sed -n "s/^export const CORE_VERSION = '\(.*\)';/\1/p" $THEME/calc-assets/core/calc-core.js)

rm -rf "$DIST" && mkdir -p "$DIST"
python3 tests/check_shortcodes.py

if command -v php >/dev/null; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "PHP syntax error: $f"; exit 1; }; done \
    < <(find plugin child-theme -name '*.php' -print0)
fi

(cd $THEME && zip -qrX "$DIST/calc-assets-core-v$VER.zip" calc-assets/core -x '*.DS_Store')
echo "built dist/calc-assets-core-v$VER.zip"

if [[ -f $THEME/style.css && -f $THEME/functions.php ]]; then
  (cd child-theme && zip -qrX "$DIST/generatepress-child.zip" generatepress-child \
     -x 'generatepress-child/calc-assets/_starter/*' -x '*/README.md' -x '*.DS_Store')
  echo "built dist/generatepress-child.zip"
else
  echo "skipped generatepress-child.zip: live theme not imported yet (needs style.css + functions.php)"
fi

if grep -lq "Plugin Name:" $PLUGIN/*.php 2>/dev/null; then
  (cd plugin && zip -qrX "$DIST/gpacalculator-manager.zip" gpacalculator-manager -x '*/README.md' -x '*.DS_Store')
  echo "built dist/gpacalculator-manager.zip"
else
  echo "skipped gpacalculator-manager.zip: live plugin not imported yet"
fi
