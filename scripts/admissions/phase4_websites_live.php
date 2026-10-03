<?php
/**
 * Admissions Phase 4, on the server: each college page's own website (college_website), which the page's structured
 * data gives as the college's url while our page stays the WebPage about it. scripts/admissions/phase4_websites_live.sh
 * uploads data/admissions/audit/phase4_websites.csv (ipeds_unitid, college_website; made by
 * scripts/admissions/phase4_websites.py from IPEDS HD2024 WEBADDR) and pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <websites.csv>            dry run: how many published college pages would get or change the field
 *   apply  <websites.csv> <log.tsv>  write it, logging each page's old value first
 *   revert <log.tsv>                 put every logged value back, removing the field where there was none
 *
 * Only college_website changes, and only on pages with an IPEDS ID (the pages under review have none). No revision,
 * no save_post, no change to the page's modified date.
 */

// ipeds_unitid => address, from the CSV.
function websites_map( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	if ( array( 'ipeds_unitid', 'college_website' ) !== fgetcsv( $fh ) ) {
		WP_CLI::error( "$file: expected the columns ipeds_unitid,college_website" );
	}
	$map = array();
	while ( false !== ( $row = fgetcsv( $fh ) ) ) {
		if ( 2 === count( $row ) && ctype_digit( $row[0] ) && preg_match( '#^https?://[^\s@]+$#', $row[1] ) ) {
			$map[ $row[0] ] = $row[1];
		}
	}
	fclose( $fh );
	return $map;
}

// The published college pages whose field would change: [ID, slug, had the field, old value, new value].
function websites_changes( $map ) {
	$ids     = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	$changes = array();
	$counts  = array( 'pages' => count( $ids ), 'no IPEDS ID' => 0, 'no website in IPEDS' => 0, 'already right' => 0 );
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		update_meta_cache( 'post', $chunk );
		foreach ( $chunk as $id ) {
			$unitid = trim( (string) get_post_meta( $id, 'ipeds_unitid', true ) );
			if ( ! ctype_digit( $unitid ) ) {
				++$counts['no IPEDS ID'];
				continue;
			}
			if ( ! isset( $map[ $unitid ] ) ) {
				++$counts['no website in IPEDS'];
				continue;
			}
			$had = metadata_exists( 'post', $id, 'college_website' );
			$old = $had ? (string) get_post_meta( $id, 'college_website', true ) : '';
			if ( $had && $old === $map[ $unitid ] ) {
				++$counts['already right'];
				continue;
			}
			$changes[] = array( $id, get_post_field( 'post_name', $id ), $had ? 1 : 0, $old, $map[ $unitid ] );
		}
	}
	$parts = array();
	foreach ( $counts as $what => $n ) {
		$parts[] = "$what $n";
	}
	WP_CLI::log( 'published college pages: ' . implode( ', ', $parts ) . '; to write ' . count( $changes ) );
	return $changes;
}

function websites_plan( $file ) {
	foreach ( array_slice( websites_changes( websites_map( $file ) ), 0, 5 ) as $c ) {
		WP_CLI::log( "  e.g. {$c[1]} (post {$c[0]}): " . ( $c[2] ? "\"{$c[3]}\"" : 'none' ) . " -> {$c[4]}" );
	}
}

function websites_apply( $file, $log ) {
	$changes = websites_changes( websites_map( $file ) );
	$fh      = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$n = 0;
	foreach ( $changes as $c ) {
		fwrite( $fh, implode( "\t", $c ) . "\n" );
		fflush( $fh );
		if ( false === update_post_meta( $c[0], 'college_website', $c[4] ) ) {
			WP_CLI::warning( "{$c[1]}: not changed" );
			continue;
		}
		++$n;
	}
	fclose( $fh );
	WP_CLI::log( "wrote college_website on $n pages; log $log" );
}

function websites_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( $lines as $line ) {
		list( $id, $slug, $had, $old ) = array_pad( explode( "\t", $line ), 5, '' );
		if ( ! ctype_digit( $id ) || ! in_array( $had, array( '0', '1' ), true ) ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		if ( '1' === $had ) {
			update_post_meta( (int) $id, 'college_website', $old );
		} else {
			delete_post_meta( (int) $id, 'college_website' );
		}
		++$n;
	}
	WP_CLI::log( "put back college_website on $n pages from $log" );
}

$cmd = isset( $args[0] ) ? $args[0] : '';
if ( 'plan' === $cmd && isset( $args[1] ) ) {
	websites_plan( $args[1] );
} elseif ( 'apply' === $cmd && isset( $args[1], $args[2] ) ) {
	websites_apply( $args[1], $args[2] );
} elseif ( 'revert' === $cmd && isset( $args[1] ) ) {
	websites_revert( $args[1] );
} else {
	WP_CLI::error( 'usage: plan <websites.csv> | apply <websites.csv> <log.tsv> | revert <log.tsv>' );
}
