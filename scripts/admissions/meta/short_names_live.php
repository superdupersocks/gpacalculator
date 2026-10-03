<?php
/**
 * College short names, on the server: the short_name field titles and descriptions use (gpa_college_short_name() in
 * functions.php). scripts/admissions/meta/short_names_live.sh uploads data/admissions/meta/short_names.csv (made by
 * scripts/admissions/meta/short_names.py) and pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <short_names.csv>            dry run: how many published college pages would get or change the field
 *   apply  <short_names.csv> <log.tsv>  write it, logging each page's old value first
 *   revert <log.tsv>                    put every logged value back, removing the field where there was none
 *
 * Only rows whose source is "hand-checked", "IPEDS alias" or "name + state" are written; every other page uses the theme's fallback
 * (the full name without a leading "The"), so a page whose row is a fallback has the field removed if it has one.
 * Matched by slug and post ID. No revision, no save_post, no change to the page's modified date.
 */

function short_names_map( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh );
	if ( array( 'slug', 'post_id', 'unitid', 'tier', 'full_name', 'short_name', 'source' ) !== $head ) {
		WP_CLI::error( "$file: unexpected columns" );
	}
	$map = array();
	while ( false !== ( $row = fgetcsv( $fh ) ) ) {
		if ( 7 !== count( $row ) ) {
			continue;
		}
		$name = trim( $row[5] );
		$keep = in_array( $row[6], array( 'hand-checked', 'IPEDS alias', 'name + state' ), true );
		if ( $keep && ( '' === $name || mb_strlen( $name ) > 60 || false !== strpbrk( $name, "<>\t\n\"" ) ) ) {
			WP_CLI::error( "bad short name for {$row[0]}: $name" );
		}
		$map[ $row[0] ] = $keep ? $name : '';
	}
	fclose( $fh );
	return $map;
}

// [ID, slug, had, old, new] for each published college page whose field would change.
function short_names_changes( $map ) {
	$ids     = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1 ) );
	$changes = array();
	$counts  = array( 'pages' => count( $ids ), 'not in the file' => 0, 'already right' => 0, 'set' => 0, 'removed' => 0 );
	update_meta_cache( 'post', $ids );
	foreach ( $ids as $id ) {
		$slug = get_post_field( 'post_name', $id );
		if ( ! isset( $map[ $slug ] ) ) {
			++$counts['not in the file'];
			continue;
		}
		$had = metadata_exists( 'post', $id, 'short_name' );
		$old = $had ? (string) get_post_meta( $id, 'short_name', true ) : '';
		$new = $map[ $slug ];
		if ( ( $had && '' !== $new && $old === $new ) || ( ! $had && '' === $new ) ) {
			++$counts['already right'];
			continue;
		}
		++$counts[ '' === $new ? 'removed' : 'set' ];
		$changes[] = array( $id, $slug, $had ? 1 : 0, $old, $new );
	}
	return array( $changes, $counts );
}

$mode = isset( $args[0] ) ? $args[0] : '';
if ( 'plan' === $mode || 'apply' === $mode ) {
	list( $changes, $counts ) = short_names_changes( short_names_map( $args[1] ) );
	foreach ( $counts as $k => $v ) {
		WP_CLI::log( "$k: $v" );
	}
	foreach ( array_slice( $changes, 0, 10 ) as $c ) {
		WP_CLI::log( "  {$c[1]}: '{$c[3]}' -> '{$c[4]}'" );
	}
	if ( 'apply' === $mode ) {
		$log = fopen( $args[2], 'x' );
		if ( ! $log ) {
			WP_CLI::error( "can't create {$args[2]}" );
		}
		foreach ( $changes as $c ) {
			fputcsv( $log, array( $c[0], $c[1], $c[2], $c[3] ), "\t" );
		}
		fclose( $log );
		foreach ( $changes as $c ) {
			'' === $c[4] ? delete_post_meta( $c[0], 'short_name' ) : update_post_meta( $c[0], 'short_name', wp_slash( $c[4] ) );
		}
		WP_CLI::success( count( $changes ) . ' pages written; log ' . $args[2] );
	}
} elseif ( 'revert' === $mode ) {
	$fh = fopen( $args[1], 'r' );
	$n  = 0;
	while ( false !== ( $row = fgetcsv( $fh, 0, "\t" ) ) ) {
		'1' === $row[2] ? update_post_meta( (int) $row[0], 'short_name', wp_slash( $row[3] ) ) : delete_post_meta( (int) $row[0], 'short_name' );
		++$n;
	}
	WP_CLI::success( "$n pages put back" );
} else {
	WP_CLI::error( 'usage: plan <csv> | apply <csv> <log> | revert <log>' );
}
