<?php
// Run: ssh <server> "cd applications/xwnzegvpyy/public_html && wp eval-file -" < scripts/export_colleges.php > colleges.jsonl
// Read-only export of published `colleges` posts (served at /admissions/<slug>/) as JSON lines.
$skip = '/^(_edit_|_wp_|ilj_|rank_math_internal_links_processed$|rank_math_analytic_object_id$)/';
$ids = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ) );
$field_keys = array();
foreach ( $ids as $id ) {
	$p    = get_post( $id );
	$raw  = get_post_meta( $id );
	$meta = array();
	$seo  = array();
	foreach ( $raw as $k => $vals ) {
		if ( preg_match( $skip, $k ) ) continue;
		$v = maybe_unserialize( $vals[0] );
		if ( '_' === $k[0] && isset( $raw[ substr( $k, 1 ) ] ) && is_string( $v ) && 0 === strpos( $v, 'field_' ) ) {
			$field_keys[ substr( $k, 1 ) ][ $v ] = true;
			continue;
		}
		if ( 0 === strpos( $k, 'rank_math_' ) ) { $seo[ $k ] = $v; continue; }
		$meta[ $k ] = $v;
	}
	ksort( $meta );
	$thumb = get_post_thumbnail_id( $id );
	echo wp_json_encode( array(
		'id' => $id, 'slug' => $p->post_name, 'title' => $p->post_title,
		'url' => get_permalink( $id ), 'status' => $p->post_status,
		'parent' => $p->post_parent, 'menu_order' => $p->menu_order,
		'date' => $p->post_date_gmt, 'modified' => $p->post_modified_gmt,
		'content' => $p->post_content, 'excerpt' => $p->post_excerpt,
		'featured_image' => $thumb ? wp_get_attachment_url( $thumb ) : null,
		'fields' => $meta, 'seo' => $seo,
	), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}
$fk = array();
foreach ( $field_keys as $name => $keys ) $fk[ $name ] = array_keys( $keys );
ksort( $fk );
echo wp_json_encode( array( '__field_keys' => $fk, '__trash_count' => (int) wp_count_posts( 'colleges' )->trash ), JSON_UNESCAPED_SLASHES ) . "\n";
