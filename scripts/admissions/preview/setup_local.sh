#!/usr/bin/env bash
# A local WordPress for /admissions/ template previews (render.py --after), on a fresh cloud container.
#
#     bash scripts/admissions/preview/setup_local.sh [start|data]
#
# start (default): installs MariaDB if missing, WordPress core from GitHub's mirror (wordpress.org is blocked here),
# wp-cli from its GitHub release, links the stand-in parent theme and this checkout's child theme, loads the college
# posts as they stand live (compose_state.py, then scripts/admissions/preview/overlay.py for what went live after
# Phase 2), and serves it on http://localhost:8890. data: reloads the college posts only.
# Everything lives under $GPA_PREVIEW_DIR (default /tmp/gpa-preview); nothing touches the live site.
set -euo pipefail
cd "$(dirname "$0")/../../.."
REPO=$(pwd)
DIR=${GPA_PREVIEW_DIR:-/tmp/gpa-preview}
WP="$DIR/wp"
CLI="php $DIR/wp-cli.phar --allow-root --path=$WP"
mkdir -p "$DIR"

db_up() {
  if ! command -v mariadbd >/dev/null; then
    apt-get update -q >/dev/null && DEBIAN_FRONTEND=noninteractive apt-get install -y -q mariadb-server >/dev/null
  fi
  if ! mysql -e 'select 1' >/dev/null 2>&1; then
    (service mariadb start || (mysqld_safe >/dev/null 2>&1 &)) >/dev/null 2>&1
    for _ in $(seq 20); do mysql -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
  fi
  mysql -e "CREATE DATABASE IF NOT EXISTS gpa_preview; CREATE USER IF NOT EXISTS 'gpa'@'localhost' IDENTIFIED BY 'gpa';
            GRANT ALL ON gpa_preview.* TO 'gpa'@'localhost'; FLUSH PRIVILEGES;"
}

load_data() {
  python3 scripts/admissions/preview/compose_state.py "$DIR/state.json" --sql "$DIR/colleges.sql"
  python3 scripts/admissions/preview/overlay.py "$DIR/state.json" "$DIR/overlay.sql"
  mysql --default-character-set=utf8mb4 gpa_preview < "$DIR/colleges.sql"
  mysql --default-character-set=utf8mb4 gpa_preview < "$DIR/overlay.sql"
  $CLI cache flush >/dev/null
}

if [ "${1:-start}" = "data" ]; then
  db_up
  load_data
  exit 0
fi

db_up
[ -f "$DIR/wp-cli.phar" ] || curl -sSL -o "$DIR/wp-cli.phar" https://github.com/wp-cli/wp-cli/releases/download/v2.11.0/wp-cli-2.11.0.phar
[ -d "$WP" ] || git clone -q --depth 1 --branch 6.8.3 https://github.com/WordPress/WordPress.git "$WP"
if [ ! -f "$WP/wp-config.php" ]; then
  $CLI config create --dbname=gpa_preview --dbuser=gpa --dbpass=gpa --dbhost=localhost --skip-check >/dev/null
fi
if ! $CLI core is-installed 2>/dev/null; then
  $CLI core install --url=http://localhost:8890 --title=GPAcalculator --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.com --skip-email >/dev/null
fi
ln -sfn "$REPO/scripts/admissions/preview/wp/generatepress" "$WP/wp-content/themes/generatepress"
ln -sfn "$REPO/child-theme/generatepress-child" "$WP/wp-content/themes/generatepress-child"
$CLI option update siteurl http://localhost:8890 >/dev/null  # core install can guess a /wp subfolder
$CLI option update home http://localhost:8890 >/dev/null
$CLI theme activate generatepress-child >/dev/null
$CLI rewrite structure '/%postname%/' >/dev/null
load_data
$CLI rewrite flush >/dev/null
if ! curl -s -o /dev/null http://localhost:8890/; then
  (cd "$WP" && setsid nohup php -S localhost:8890 "$REPO/scripts/admissions/preview/wp/router.php" >"$DIR/server.log" 2>&1 </dev/null &)
  sleep 1
fi
echo "Preview WordPress on http://localhost:8890 ($DIR)"
