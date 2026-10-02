#!/usr/bin/env bash
# Admissions Phase 2, checkpoint B: blank the unsupported fields on every /admissions/ college post.
#
#   bash scripts/admissions/phase2_b_live.sh count          non-empty values per field (read-only)
#   bash scripts/admissions/phase2_b_live.sh export         save today's values: a TSV for the audit and an SQL file
#                                                         that puts them back, in ~/backups/ on the server and
#                                                         ~/gpacalculator-backups/ on this Mac
#   bash scripts/admissions/phase2_b_live.sh apply <name>   blank the fields; <name> is the export made just before
#   bash scripts/admissions/phase2_b_live.sh revert <name>  put an export's values back
#
# Fields: average_gpa (no college-published source), admission_standards and applicant_competition (no source),
# img_url (images hotlinked from collegesimply.imgix.net). Values are blanked, not deleted: the listing sorts and
# filters with meta_key queries, which drop posts that lack the row. Run from the repo on the Mac (it uses the
# same SSH key as scripts/deploy_theme.sh). Log each run in docs/LIVE_CHANGELOG.md.
# The revert SELECT spells 'UPD' 'ATE' apart: WP-CLI 2.12 treats any query containing UPDATE as a write and prints
# "Rows affected" instead of the rows.
set -euo pipefail

HOST="master_rfzfmbbwze@67.205.161.226"
KEY="$HOME/.ssh/gpacalculator_cloudways"
APP="applications/xwnzegvpyy/public_html"
SSH=(ssh -i "$KEY" -o IdentitiesOnly=yes "$HOST")
LOCAL="$HOME/gpacalculator-backups"
FIELDS="'average_gpa','admission_standards','applicant_competition','img_url'"
# shellcheck disable=SC2016  # $P is expanded on the server
WHERE='FROM ${P}posts p JOIN ${P}postmeta m ON m.post_id = p.ID WHERE p.post_type = '"'colleges'"' AND m.meta_key IN ('"$FIELDS"') AND m.meta_value <> '"''"

remote() {  # run a shell snippet in the WordPress folder, with $P set to the table prefix
  "${SSH[@]}" "set -euo pipefail; cd $APP; P=\$(wp db prefix); $1"
}

case "${1:-}" in
  count)
    remote "wp db query \"SELECT m.meta_key, COUNT(*) $WHERE GROUP BY m.meta_key\"" ;;
  export)
    NAME="admissions-b-$(date -u +%Y%m%d-%H%M)"
    remote "wp db query \"SELECT p.ID, p.post_name, p.post_status, m.meta_key, m.meta_value $WHERE ORDER BY p.post_name, m.meta_key\" > ~/backups/$NAME.tsv
      wp db query --skip-column-names \"SELECT CONCAT('UPD', 'ATE \${P}postmeta SET meta_value = ', QUOTE(m.meta_value), ' WHERE meta_id = ', m.meta_id, ';') $WHERE\" > ~/backups/$NAME-revert.sql
      V=\$(( \$(grep -c . ~/backups/$NAME.tsv) - 1 )); S=\$(grep -c '^UPDATE ' ~/backups/$NAME-revert.sql || true)
      echo rows: \$V values, \$S revert statements
      test \"\$V\" -eq \"\$S\" || { echo 'revert file does not cover every value: stop'; exit 1; }"
    mkdir -p "$LOCAL"
    scp -q -i "$KEY" -o IdentitiesOnly=yes "$HOST:backups/$NAME.tsv" "$HOST:backups/$NAME-revert.sql" "$LOCAL/"
    echo "export $NAME: ~/backups/ on the server and $LOCAL/" ;;
  apply)
    NAME="${2:?the export name, e.g. admissions-b-20261002-0600}"
    test -s "$LOCAL/$NAME-revert.sql" || { echo "no export $NAME in $LOCAL"; exit 1; }
    remote "test -s ~/backups/$NAME-revert.sql
      wp db query \"UPDATE \${P}postmeta m JOIN \${P}posts p ON p.ID = m.post_id SET m.meta_value = '' WHERE p.post_type = 'colleges' AND m.meta_key IN ($FIELDS) AND m.meta_value <> ''\"
      wp cache flush; wp breeze purge --cache=all
      wp db query \"SELECT COUNT(*) AS left_over $WHERE\"" ;;
  revert)
    NAME="${2:?the export name to restore}"
    remote "test -s ~/backups/$NAME-revert.sql; wp db query < ~/backups/$NAME-revert.sql; wp cache flush; wp breeze purge --cache=all
      wp db query \"SELECT m.meta_key, COUNT(*) $WHERE GROUP BY m.meta_key\"" ;;
  *)
    sed -n '2,13p' "$0"; exit 1 ;;
esac
