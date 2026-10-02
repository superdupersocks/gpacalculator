#!/usr/bin/env bash
# Put back what scripts/design/backup.sh saved. One command per rollback:
#
#   bash scripts/design/restore.sh <name> theme        child theme files exactly as backed up (files added since are removed)
#   bash scripts/design/restore.sh <name> simplecss    Simple CSS output folder + its options
#   bash scripts/design/restore.sh <name> customizer   Customizer/GeneratePress settings, Additional CSS, site icon
#   bash scripts/design/restore.sh <name> widgets      footer widgets and menu locations
#   bash scripts/design/restore.sh <name> db           the full database export (undoes every DB change since)
#   bash scripts/design/restore.sh <name> all          theme + simplecss + customizer + widgets (not db)
#
# Each part first saves the current state to ~/backups/<name>-undo-<time>/ so a restore can itself be undone.
# Then purges Breeze. Purge Cloudflare afterwards (dashboard: Caching > Purge Everything).
set -euo pipefail
NAME="${1:?backup name, e.g. design-20261002-0830}"; shift
[[ $# -gt 0 ]] || { sed -n '2,11p' "$0"; exit 1; }
PARTS="$*"; [[ "$PARTS" == "all" ]] && PARTS="theme simplecss customizer widgets"

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
THEME="wp-content/themes/generatepress-child"

ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST" bash -s "$NAME" "$APP" "$THEME" "$PARTS" <<'REMOTE'
set -euo pipefail
NAME="$1"; APP="$2"; THEME="$3"; PARTS="$4"; B="$HOME/backups/$NAME"
test -d "$B" || { echo "no backup $B"; exit 1; }
UNDO="$HOME/backups/$NAME-undo-$(date -u +%Y%m%d-%H%M%S)"; mkdir -p "$UNDO/options"
cd "$APP"
setopt() { [ -s "$B/options/$1.json" ] && { wp option get "$1" --format=json > "$UNDO/options/$1.json" 2>/dev/null || true; wp option update "$1" --format=json < "$B/options/$1.json" >/dev/null; echo "restored option $1"; }; return 0; }
for part in $PARTS; do
  case "$part" in
    theme)
      tar czf "$UNDO/theme.tar.gz" --exclude='*.zip' -C "$THEME" .
      T="$(mktemp -d)"; tar xzf "$B/theme.tar.gz" -C "$T"
      rsync -a --delete --exclude='*.zip' "$T/" "$THEME/"; rm -rf "$T"
      for f in $(find "$THEME" -name '*.php'); do php -l "$f" >/dev/null || { echo "PHP ERROR in $f"; exit 1; }; done
      echo "restored theme" ;;
    simplecss)
      [ -d wp-content/uploads/so-css ] && cp -a wp-content/uploads/so-css "$UNDO/so-css"
      [ -d "$B/so-css" ] && { rm -rf wp-content/uploads/so-css; cp -a "$B/so-css" wp-content/uploads/so-css; echo "restored so-css folder"; }
      setopt so_css_css; setopt so_css_custom_selectors ;;
    customizer)
      for o in theme_mods_generatepress-child theme_mods_generatepress generate_settings site_icon rank-math-options-general rank-math-options-titles; do setopt "$o"; done
      wp post list --post_type=custom_css --post_status=any --fields=ID,post_name,post_content --format=json > "$UNDO/custom_css.json"
      python3 - "$B/custom_css.json" <<'PY' | while IFS=$'\t' read -r id file; do wp post update "$id" "$file" >/dev/null && echo "restored Additional CSS post $id"; done
import json, sys, tempfile
for p in json.load(open(sys.argv[1])):
    f = tempfile.NamedTemporaryFile('w', delete=False, suffix='.css'); f.write(p['post_content']); f.close()
    print(f"{p['ID']}\t{f.name}")
PY
      ;;
    widgets)
      setopt sidebars_widgets; setopt nav_menu_locations
      for f in "$B"/options/widget_*.json; do setopt "$(basename "$f" .json)"; done ;;
    db)
      wp db export - | gzip > "$UNDO/db.sql.gz"
      gunzip -c "$B/db.sql.gz" | wp db import -
      echo "restored database" ;;
    *) echo "unknown part: $part"; exit 1 ;;
  esac
done
wp breeze purge --cache=all
echo "Done. Undo this restore with the files in $UNDO"
REMOTE
echo "Now purge Cloudflare (Caching > Purge Everything)."
