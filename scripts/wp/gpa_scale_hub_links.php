<?php
// /gpa-scale/ hub: link each Grade points value in the first table (the scale) to its /gpa-scale/<x-x>-gpa/ page.
$save = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();
$hub = get_page_by_path( 'gpa-scale' );
$c   = $hub->post_content;
$s   = strpos( $c, '<!-- wp:table' );
$e   = strpos( $c, '<!-- /wp:table -->', $s );
$tbl = substr( $c, $s, $e - $s );
$n   = 0;
$new_tbl = preg_replace_callback(
	'#(<tr><td[^>]*>[^<]*</td><td[^>]*>[^<]*</td><td([^>]*)>)([0-4])\.([0-9])(</td></tr>)#',
	function ( $m ) use ( &$n ) {
		$page = get_page_by_path( 'gpa-scale/' . $m[3] . '-' . $m[4] . '-gpa' );
		if ( ! $page || 'publish' !== $page->post_status ) { return $m[0]; }
		$n++;
		return $m[1] . '<a href="' . esc_url( get_permalink( $page ) ) . '">' . $m[3] . '.' . $m[4] . '</a>' . $m[5];
	},
	$tbl
);
$new = substr( $c, 0, $s ) . $new_tbl . substr( $c, $e );
$same = preg_replace( '#\s+#', '', wp_strip_all_tags( $c ) ) === preg_replace( '#\s+#', '', wp_strip_all_tags( $new ) );
echo "links added: $n, text unchanged: " . ( $same ? 'yes' : 'NO' ) . "\n";
if ( $save && $same && $n ) {
	$r = wp_update_post( array( 'ID' => $hub->ID, 'post_content' => wp_slash( $new ) ), true );
	echo is_wp_error( $r ) ? 'SAVE FAILED: ' . $r->get_error_message() . "\n" : "saved\n";
}
