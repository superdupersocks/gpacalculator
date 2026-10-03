<?php
/**
 * Swap a page's hand-built example table for the [gpa_example] component (component library).
 * WP-CLI env:
 *   GPC_ID     page id
 *   GPC_MATCH  text that only the example table contains (its header, e.g. "Unweighted Points")
 *   GPC_SC     the shortcode to put in its place, e.g. [gpa_example type="weighted" rows="…"]
 *   GPC_RESULT 1 = also replace the old .rx-result paragraph that follows the table
 *   GPC_SAVE   1 = save (a revision keeps the old version); otherwise a dry run
 * Gate: every row result and the totals in the component (quality points or points, total, total credits/weight)
 * must equal a number in the old table/result, compared as numbers.
 */
$id    = (int) getenv( 'GPC_ID' );
$match = (string) getenv( 'GPC_MATCH' );
$sc    = (string) getenv( 'GPC_SC' );
$res   = '1' === getenv( 'GPC_RESULT' );
$save  = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();

$post = get_post( $id );
if ( ! $post || '' === $match || '' === $sc ) { echo "need GPC_ID, GPC_MATCH, GPC_SC\n"; return; }
$old = $post->post_content;

// The <table> holding the match, widened to its block (<!-- wp:table … --> … <!-- /wp:table -->) or <figure>.
$t0 = false; $t1 = false;
if ( preg_match_all( '#<table\b.*?</table>#s', $old, $tm, PREG_OFFSET_CAPTURE ) ) {
	foreach ( $tm[0] as $t ) {
		if ( false !== strpos( wp_strip_all_tags( $t[0] ), $match ) ) { $t0 = $t[1]; $t1 = $t[1] + strlen( $t[0] ); break; }
	}
}
if ( false === $t0 ) { echo "page $id: no table containing '$match'\n"; return; }
$start = $t0; $end = $t1;
$f0 = strrpos( substr( $old, 0, $t0 ), '<figure' );
// widen to the <figure> only when it wraps just this table (opening tag right before it, nothing else in between)
if ( false !== $f0 && $t0 - $f0 < 200 && '' === trim( preg_replace( '#^<figure[^>]*>#', '', substr( $old, $f0, $t0 - $f0 ) ) ) ) {
	$start = $f0; $end = strpos( $old, '</figure>', $t1 ) + 9;
}
$b0 = strrpos( substr( $old, 0, $start ), '<!-- wp:table' );
if ( false !== $b0 && '' === trim( preg_replace( '#<!-- wp:table[^>]*-->#', '', substr( $old, $b0, $start - $b0 ) ) ) ) {
	$start = $b0;
	$close = strpos( $old, '<!-- /wp:table -->', $end );
	if ( false !== $close && '' === trim( substr( $old, $end, $close - $end ) ) ) { $end = $close + 18; }
}
$old_part = substr( $old, $start, $end - $start );
if ( $res && preg_match( '#^\s*<!-- wp:paragraph (?:(?!-->).)*?"rx-result"(?:(?!-->).)*? -->\s*<p[^>]*rx-result[^>]*>.*?</p>\s*<!-- /wp:paragraph -->#s', substr( $old, $end ), $rm ) ) {
	$old_part .= $rm[0];
	$end      += strlen( $rm[0] );
}

preg_match_all( '#\d+(?:\.\d+)?#', html_entity_decode( preg_replace( '#<[^>]+>#', ' ', $old_part ) ), $nm ); // a space per tag, so adjacent cells don't merge
$old_nums = array_map( 'floatval', $nm[0] );
$html = do_shortcode( $sc );
if ( false === strpos( $html, 'gpa-ex' ) ) { echo "page $id: the shortcode rendered nothing (check rows)\n"; return; }
preg_match_all( '#class="gpa-ex__qp">([\d.]+)<#', $html, $q );
preg_match_all( '#gpa-ex__value">([\d.]+)%?<#', $html, $v );
$check = array_merge( $q[1], array_slice( $v[1], 0, 2 ) );
$missing = array();
foreach ( $check as $n ) {
	$f = (float) $n; $ok = false;
	foreach ( $old_nums as $o ) { if ( abs( $o - $f ) < 0.001 ) { $ok = true; break; } }
	if ( ! $ok ) { $missing[] = $n; }
}
printf( "page %d %s: replacing %d chars; component checks %s; result value %s\n", $id, $post->post_name, strlen( $old_part ), implode( ' ', $check ), end( $v[1] ) );
if ( $missing ) { echo 'SKIP: not in the old example: ' . implode( ' ', $missing ) . "\n"; return; }
echo "numbers check: same\n";
$new = substr( $old, 0, $start ) . "<!-- wp:shortcode -->\n" . $sc . "\n<!-- /wp:shortcode -->" . substr( $old, $end );
if ( $save ) {
	// Keep the page's own template valid for this save (some theme templates aren't listed in WP-CLI context).
	$tpl = get_page_template_slug( $id );
	if ( $tpl ) { add_filter( 'theme_page_templates', function ( $t ) use ( $tpl ) { $t[ $tpl ] = $tpl; return $t; } ); }
	$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
	echo is_wp_error( $r ) ? 'SAVE FAILED: ' . $r->get_error_message() . "\n" : "saved (revision kept)\n";
} else {
	echo "dry run: set GPC_SAVE=1 to save\n";
}
