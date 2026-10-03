<?php
/**
 * Rank Math title / description for the /gpa-scale/<x-y>-gpa/ pages, from content/gpa-scale-meta-2026-10-03.json
 * (built from the site-wide rule; old values kept in the file for a revert). Inlined by:
 *   python3 -c "..." (see PROJECT.md); GPC_SAVE=1 saves, otherwise a dry run. GPC_REVERT=1 writes the old values back.
 */
$save   = '1' === getenv( 'GPC_SAVE' );
$revert = '1' === getenv( 'GPC_REVERT' );
foreach ( $meta as $id => $m ) {
	$t = $revert ? $m['old_title'] : $m['title'];
	$d = $revert ? $m['old_description'] : $m['description'];
	$cur_t = get_post_meta( $id, 'rank_math_title', true );
	$cur_d = get_post_meta( $id, 'rank_math_description', true );
	$from_ok = $revert ? ( $cur_t === $m['title'] && $cur_d === $m['description'] ) : ( $cur_t === $m['old_title'] && $cur_d === $m['old_description'] );
	if ( ! $from_ok ) { echo "{$m['slug']}: SKIP (current values are not the expected ones)\n"; continue; }
	if ( $save ) { update_post_meta( $id, 'rank_math_title', $t ); update_post_meta( $id, 'rank_math_description', $d ); }
	echo "{$m['slug']}: " . ( $save ? 'saved' : 'ready' ) . " | $t\n";
}
