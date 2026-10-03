<?php
/**
 * Every published college page's title and description as the theme builds them, for review before a deploy:
 *   wp eval-file scripts/admissions/meta/export_meta.php <out.csv>
 * Columns: slug, tier, template, short_name, title, title_len, description, description_len, og_matches, flags.
 * Flags: title_over_60, description_under_140, description_over_158, duplicate_title, duplicate_description,
 * has_year (a 4-digit year or "fall"/"20xx–xx" in either).
 */
$out = $args[0];
$ids = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
update_meta_cache( 'post', $ids );
$rows = array();
$seen = array( 't' => array(), 'd' => array() );
foreach ( $ids as $id ) {
	$GLOBALS['post'] = get_post( $id );
	setup_postdata( $GLOBALS['post'] );
	$x     = gpa_college_seo_facts( $id );
	$title = gpa_college_seo_build_title( $id );
	$desc  = gpa_college_seo_build_description( $id );
	$og    = gpa_college_og_title( '' ) === $title && gpa_college_og_description( '' ) === $desc;
	$rows[] = array( get_post_field( 'post_name', $id ), get_post_meta( $id, 'admissions_tier', true ), $x['template'], $x['short'], $title, mb_strlen( $title ), $desc, mb_strlen( $desc ), $og ? 'yes' : 'NO' );
	$seen['t'][ strtolower( $title ) ][] = count( $rows ) - 1;
	$seen['d'][ strtolower( $desc ) ][]  = count( $rows ) - 1;
}
$fh = fopen( $out, 'w' );
fputcsv( $fh, array( 'slug', 'tier', 'template', 'short_name', 'title', 'title_len', 'description', 'description_len', 'og_matches', 'flags' ) );
$n = array();
foreach ( $rows as $i => $r ) {
	$flags = array();
	if ( $r[5] > 60 ) { $flags[] = 'title_over_60'; }
	if ( $r[7] < 140 ) { $flags[] = 'description_under_140'; }
	if ( $r[7] > 158 ) { $flags[] = 'description_over_158'; }
	if ( count( $seen['t'][ strtolower( $r[4] ) ] ) > 1 ) { $flags[] = 'duplicate_title'; }
	if ( count( $seen['d'][ strtolower( $r[6] ) ] ) > 1 ) { $flags[] = 'duplicate_description'; }
	if ( preg_match( '/\b(19|20)\d\d\b|\bfall (19|20)/i', $r[4] . ' ' . $r[6] ) ) { $flags[] = 'has_year'; }
	foreach ( $flags as $f ) { $n[ $f ] = ( isset( $n[ $f ] ) ? $n[ $f ] : 0 ) + 1; }
	$n[ 'template ' . $r[2] ] = ( isset( $n[ 'template ' . $r[2] ] ) ? $n[ 'template ' . $r[2] ] : 0 ) + 1;
	$r[] = implode( ' ', $flags );
	fputcsv( $fh, $r );
}
fclose( $fh );
ksort( $n );
foreach ( $n as $k => $v ) { WP_CLI::log( "$k: $v" ); }
