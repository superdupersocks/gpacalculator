<?php
/**
 * Remove the pill paragraphs above section headings (p.rx-eyebrow) from one page. Section numbers replace them.
 * WP-CLI: GPC_ID=<page id> wp eval-file remove_pills.php   (dry run); add GPC_SAVE=1 to save.
 * Gate: the page's rendered text is unchanged apart from the removed pills. Saved with wp_update_post, so a
 * revision keeps the old version.
 */
$id   = (int) getenv( 'GPC_ID' );
$save = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();

$post = get_post( $id );
if ( ! $post ) { echo "no post $id\n"; return; }
$old = $post->post_content;

function rp_text( $html ) {
	$t = html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' );
	return preg_replace( '#\s+#u', '', $t );
}

/* Drop core/paragraph blocks whose className has rx-eyebrow, at any depth. */
function rp_walk( $list, &$removed ) {
	$out = array();
	foreach ( $list as $b ) {
		$cls = isset( $b['attrs']['className'] ) ? ' ' . $b['attrs']['className'] . ' ' : '';
		if ( 'core/paragraph' === $b['blockName'] && false !== strpos( $cls, ' rx-eyebrow ' ) ) {
			$removed[] = rp_text( $b['innerHTML'] );
			continue;
		}
		if ( $b['innerBlocks'] ) {
			$kids    = rp_walk( $b['innerBlocks'], $removed );
			$strings = array();
			foreach ( $b['innerContent'] as $c ) { if ( null !== $c ) { $strings[] = $c; } }
			$first = array_shift( $strings );
			$last  = array_pop( $strings );
			// innerContent: opening markup, one null per child, closing markup
			$b['innerContent'] = array_merge( array( $first ), array_fill( 0, count( $kids ), null ), array( null === $last ? '' : $last ) );
			$b['innerBlocks']  = $kids;
		}
		$out[] = $b;
	}
	return $out;
}

$removed = array();
$new     = serialize_blocks( rp_walk( parse_blocks( $old ), $removed ) );

$a = rp_text( do_blocks( $old ) );
foreach ( $removed as $r ) { $p = strpos( $a, $r ); if ( false !== $p ) { $a = substr_replace( $a, '', $p, strlen( $r ) ); } }
$b = rp_text( do_blocks( $new ) );
printf( "page %d %s: pills removed %d, raw markup left with rx-eyebrow: %d\n", $id, $post->post_name, count( $removed ), substr_count( $new, 'rx-eyebrow' ) );
if ( ! $removed ) { echo "nothing to do\n"; return; }
if ( $a !== $b ) {
	$p = 0;
	while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) { $p++; }
	echo "SKIP: text differs at $p\n  old: " . substr( $a, max( 0, $p - 60 ), 160 ) . "\n  new: " . substr( $b, max( 0, $p - 60 ), 160 ) . "\n";
	return;
}
echo "text check: same\n";
if ( $save ) {
	$res = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
	echo is_wp_error( $res ) ? 'SAVE FAILED: ' . $res->get_error_message() . "\n" : "saved (revision kept)\n";
} else {
	echo "dry run: set GPC_SAVE=1 to save\n";
}
