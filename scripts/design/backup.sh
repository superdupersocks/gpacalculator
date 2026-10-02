#!/usr/bin/env bash
# Design system overhaul, phase 0: back up everything the overhaul can touch, on the server and on this Mac.
#
#   bash scripts/design/backup.sh            take a backup (prints its name, e.g. design-20261002-0830)
#   bash scripts/design/restore.sh <name> …  put parts of it back (see restore.sh)
#
# Saves to ~/backups/<name>/ on the server (outside the web root) and copies it to ~/gpacalculator-backups/<name>/:
#   theme.tar.gz      the whole live child theme (zips excluded)
#   so-css/           the Simple CSS plugin's output folder (wp-content/uploads/so-css)
#   options/*.json    Customizer and GeneratePress settings, Simple CSS option, Rank Math general/titles options,
#                     site icon, widgets and footer menus (sidebars_widgets, widget_*), nav menu locations
#   custom_css.json   WordPress "Additional CSS" posts
#   db.sql.gz         full database export
#   SHA256SUMS
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
THEME="wp-content/themes/generatepress-child"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
NAME="design-$(date -u +%Y%m%d-%H%M)"

"${SSH[@]}" bash -s "$NAME" "$APP" "$THEME" <<'REMOTE'
set -euo pipefail
NAME="$1"; APP="$2"; THEME="$3"; OUT="$HOME/backups/$NAME"
mkdir -p "$OUT/options"
cd "$APP"
tar czf "$OUT/theme.tar.gz" --exclude='*.zip' -C "$THEME" .
if [ -d wp-content/uploads/so-css ]; then cp -a wp-content/uploads/so-css "$OUT/so-css"; fi
for opt in theme_mods_generatepress-child theme_mods_generatepress generate_settings so_css_css so_css_custom_selectors \
           rank-math-options-general rank-math-options-titles site_icon sidebars_widgets nav_menu_locations; do
  wp option get "$opt" --format=json > "$OUT/options/$opt.json" 2>/dev/null || echo "(no option $opt)"
done
for opt in $(wp option list --search='widget_*' --field=option_name); do
  wp option get "$opt" --format=json > "$OUT/options/$opt.json"
done
wp post list --post_type=custom_css --post_status=any --fields=ID,post_name,post_content --format=json > "$OUT/custom_css.json"
wp db export - | gzip > "$OUT/db.sql.gz"
gunzip -t "$OUT/db.sql.gz"
( cd "$OUT" && find . -type f ! -name SHA256SUMS -exec sha256sum {} + > SHA256SUMS )
du -sh "$OUT"; ls -la "$OUT" "$OUT/options"
REMOTE

mkdir -p "$HOME/gpacalculator-backups"
scp -r -q -i "$KEY" -o IdentitiesOnly=yes "$HOST:backups/$NAME" "$HOME/gpacalculator-backups/"
( cd "$HOME/gpacalculator-backups/$NAME" && shasum -a 256 -c SHA256SUMS >/dev/null && echo "copy on this Mac verified: ~/gpacalculator-backups/$NAME" )
echo "Backup: $NAME"
