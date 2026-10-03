#!/bin/bash
# College calculator go-live (docs/COLLEGE_GOLIVE_RUNBOOK.md on claude/calculator-unification-0oc2fc), run by Digant.
#   bash scripts/college_golive.sh upload   backups (plugin tarball + DB, copies on the Mac), page 22 subtitle, plugin
#                                           upload from the unification branch, php -l. All switches stay off.
#   bash scripts/college_golive.sh subtitle page 22 subtitle only (backs up the page first; revision kept)
#   bash scripts/college_golive.sh switch   turn the new calculator on for College only, purge Breeze
#   bash scripts/college_golive.sh off      turn it off again (old calculator back), purge Breeze
#   bash scripts/college_golive.sh restore <ts>  put the old plugin folder back from its tarball (after "off")
# Run the theme tokens deploy first:  bash scripts/deploy_theme.sh 69e4381 --only gpa-design-tokens.css
set -euo pipefail
SSH=(ssh -i "$HOME/.ssh/gpacalculator_cloudways" -o IdentitiesOnly=yes master_rfzfmbbwze@67.205.161.226)
APP=applications/xwnzegvpyy/public_html
PLUG=$APP/wp-content/plugins/gpacalculator-manager
MACBK="$HOME/gpacalculator-backups"
BRANCH=claude/calculator-unification-0oc2fc

case "${1:-}" in
  upload)
    TS=$(date -u +%Y%m%d-%H%M)
    echo "== backups ($TS)"
    "${SSH[@]}" "set -e; mkdir -p ~/backups; tar czf ~/backups/gpacalculator-manager-pre-v2-$TS.tar.gz -C $APP/wp-content/plugins gpacalculator-manager; \
      cd $APP && wp db export - | gzip > ~/backups/gpacalculator-$TS-pre-college-v2.sql.gz; \
      wp post get 22 --field=post_content > ~/backups/page-22-before-college-v2.html; ls -l ~/backups/*$TS* ~/backups/page-22-before-college-v2.html"
    mkdir -p "$MACBK"
    scp -i "$HOME/.ssh/gpacalculator_cloudways" -o IdentitiesOnly=yes \
      "master_rfzfmbbwze@67.205.161.226:~/backups/{gpacalculator-manager-pre-v2-$TS.tar.gz,gpacalculator-$TS-pre-college-v2.sql.gz}" "$MACBK/"
    echo "Mac copies in $MACBK"

    "$0" subtitle

    echo "== plugin upload from $BRANCH"
    TMP=$(mktemp -d)
    git -C "$(dirname "$0")/.." fetch -q origin "$BRANCH"
    git -C "$(dirname "$0")/.." archive "origin/$BRANCH" plugin/gpacalculator-manager | tar x -C "$TMP"
    rsync -rci --exclude 'assets/calc-assets/_starter/' -e "ssh -i $HOME/.ssh/gpacalculator_cloudways -o IdentitiesOnly=yes" \
      "$TMP/plugin/gpacalculator-manager/" "master_rfzfmbbwze@67.205.161.226:$PLUG/"
    rm -rf "$TMP"
    "${SSH[@]}" "set -e; cd $PLUG && find . -name '*.php' -exec php -l {} \; | grep -v 'No syntax errors' || true; \
      cd ~/$APP && wp option get gpcm_calc_v2_on --format=json 2>/dev/null || echo 'gpcm_calc_v2_on not set (all off)'; wp breeze purge --cache=all"
    echo "Uploaded. Revert: bash scripts/college_golive.sh restore $TS"
    ;;
  subtitle)
    "${SSH[@]}" "mkdir -p ~/backups; cd $APP && wp post get 22 --field=post_content > ~/backups/page-22-before-college-v2.html"
    "${SSH[@]}" "cd $APP && wp eval '
      \$p = get_post( 22 );
      \$o = \"Calculate semester and cumulative GPA on a 4.0 scale, weighted by credit hours.\";
      \$n = \"Semester and cumulative GPA on a 4.0 scale.\";
      if ( 1 !== substr_count( \$p->post_content, \$o ) ) { echo \"subtitle: old text not found once, left as is\n\"; return; }
      \$tpl = get_page_template_slug( 22 );
      if ( \$tpl ) { add_filter( \"theme_page_templates\", function ( \$t ) use ( \$tpl ) { \$t[ \$tpl ] = \$tpl; return \$t; } ); }
      \$r = wp_update_post( array( \"ID\" => 22, \"post_content\" => wp_slash( str_replace( \$o, \$n, \$p->post_content ) ) ), true );
      echo is_wp_error( \$r ) ? \"subtitle: SAVE FAILED \" . \$r->get_error_message() . \"\n\" : \"subtitle: saved (revision kept)\n\";'"
    "${SSH[@]}" "cd $APP && wp breeze purge --cache=all"
    ;;
  switch)
    "${SSH[@]}" "cd $APP && wp option update gpcm_calc_v2_on '[\"college\"]' --format=json && wp breeze purge --cache=all"
    echo "College is on. Undo: bash scripts/college_golive.sh off"
    ;;
  off)
    "${SSH[@]}" "cd $APP && wp option update gpcm_calc_v2_on '[]' --format=json && wp breeze purge --cache=all"
    ;;
  restore)
    TS="${2:?timestamp printed by upload, e.g. 20261003-0840}"
    "${SSH[@]}" "set -e; test -f ~/backups/gpacalculator-manager-pre-v2-$TS.tar.gz; cd $APP/wp-content/plugins && \
      mv gpacalculator-manager ~/backups/gpacalculator-manager-v2-removed-$TS && tar xzf ~/backups/gpacalculator-manager-pre-v2-$TS.tar.gz && \
      cd ~/$APP && wp breeze purge --cache=all && echo restored"
    ;;
  *) sed -n 2,9p "$0"; exit 1 ;;
esac
