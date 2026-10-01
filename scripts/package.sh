#!/usr/bin/env bash
# Build installable zips into dist/.
# gpacalculator-manager is the one plugin for every calculator (theme, Calc Plugin and Grades & GPA
# Plugin calculators merge into it); the theme keeps site design + brand tokens. legacy/ is never shipped.
#   gpacalculator-manager-core-vX.zip      always: additive files for wp-content/plugins/
#                                          (calc-assets/core + the engine in includes/)
#   generatepress-child-tokens-vX.zip      always: additive files for wp-content/themes/
#                                          (brand-tokens.css + inc/brand-tokens.php)
#   generatepress-child.zip                once the live theme (style.css + functions.php) is in the repo
#   gpacalculator-manager.zip              once the live plugin main file is in the repo
# The full theme/plugin zips replace what's on the site, so they are only built from the
# imported live source, and only when every shortcode in shortcodes.lock is still registered.
set -euo pipefail
cd "$(dirname "$0")/.."
REPO=$(pwd)
THEME=child-theme/generatepress-child
PLUGIN=plugin/gpacalculator-manager
DIST=$REPO/dist
VER=$(sed -n "s/^export const CORE_VERSION = '\(.*\)';/\1/p" $PLUGIN/assets/calc-assets/core/calc-core.js)

rm -rf "$DIST" && mkdir -p "$DIST"
python3 tests/check_shortcodes.py

if command -v php >/dev/null; then
  while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "PHP syntax error: $f"; exit 1; }; done \
    < <(find plugin child-theme -name '*.php' -print0)
fi

(cd plugin && zip -qrX "$DIST/gpacalculator-manager-core-v$VER.zip" \
   gpacalculator-manager/assets/calc-assets/core \
   gpacalculator-manager/includes/{bootstrap,calculator-registry,calculator-assets,shortcodes,calculators}.php \
   -x '*.DS_Store')
echo "built dist/gpacalculator-manager-core-v$VER.zip"
(cd child-theme && zip -qrX "$DIST/generatepress-child-tokens-v$VER.zip" \
   generatepress-child/brand-tokens.css generatepress-child/inc/brand-tokens.php)
echo "built dist/generatepress-child-tokens-v$VER.zip"

if [[ -f $THEME/style.css && -f $THEME/functions.php ]]; then
  grep -q "inc/brand-tokens.php" $THEME/functions.php || echo "warning: functions.php doesn't load inc/brand-tokens.php yet"
  (cd child-theme && zip -qrX "$DIST/generatepress-child.zip" generatepress-child -x '*/README.md' -x '*.DS_Store')
  echo "built dist/generatepress-child.zip"
else
  echo "skipped generatepress-child.zip: live theme not imported yet (needs style.css + functions.php)"
fi

if grep -lq "Plugin Name:" $PLUGIN/*.php 2>/dev/null; then
  grep -lq "includes/bootstrap.php" $PLUGIN/*.php || echo "warning: main plugin file doesn't load includes/bootstrap.php yet"
  (cd plugin && zip -qrX "$DIST/gpacalculator-manager.zip" gpacalculator-manager \
     -x 'gpacalculator-manager/assets/calc-assets/_starter/*' -x '*/README.md' -x '*.DS_Store')
  echo "built dist/gpacalculator-manager.zip"
else
  echo "skipped gpacalculator-manager.zip: live plugin not imported yet"
fi
