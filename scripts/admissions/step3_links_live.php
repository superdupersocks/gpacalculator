<?php
/**
 * Admissions step 3, on the server: each college page's official admissions link for template v2
 * (college_admissions_url, and college_admissions_url_kind = admissions or website) and its IPEDS street address and
 * ZIP code for the CollegeOrUniversity schema (college_street, college_zip). scripts/admissions/step3_links_live.sh
 * uploads data/admissions/audit/step3_admissions_links.csv (ipeds_unitid and those four; made by
 * scripts/admissions/step3_links.py pick) and pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <links.csv>            dry run: how many published college pages would get or change the fields
 *   apply  <links.csv> <log.tsv>  write them, logging each page's old values first
 *   revert <log.tsv>              put every logged value back, removing a field where there was none
 *
 * Only those four fields change, and only on pages with an IPEDS ID. A field the file leaves empty for a page's college
 * is removed if the page has one (a stale link). No revision, no save_post, no change to the page's modified date.
 * Template v1 doesn't read the fields.
 */

const STEP3_FIELDS = array( 'college_admissions_url', 'college_admissions_url_kind', 'college_street', 'college_zip' );

// ipeds_unitid => [url, kind, street, zip], from the CSV.
function step3_links_map( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	if ( array_merge( array( 'ipeds_unitid' ), STEP3_FIELDS ) !== fgetcsv( $fh ) ) {
		WP_CLI::error( "$file: expected the columns ipeds_unitid," . implode( ',', STEP3_FIELDS ) );
	}
	$map = array();
	while ( false !== ( $row = fgetcsv( $fh ) ) ) {
		if ( 5 !== count( $row ) || ! ctype_digit( $row[0] ) ) {
			continue;
		}
		$link = ( '' === $row[1] && '' === $row[2] )
			|| ( preg_match( '#^https?://[^\s@]+$#', $row[1] ) && in_array( $row[2], array( 'admissions', 'website' ), true ) );
		$addr = ( '' === $row[3] && '' === $row[4] )
			|| ( '' !== trim( $row[3] ) && strlen( $row[3] ) <= 200 && false === strpbrk( $row[3], "<>\t\n" ) && preg_match( '/^\d{5}(-\d{4})?$/', $row[4] ) );
		if ( $link && $addr ) {
			$map[ $row[0] ] = array_slice( $row, 1 );
		}
	}
	fclose( $fh );
	return $map;
}

// The published college pages whose fields would change: [ID, slug, then had (1/0) and old value for each field, then
// each new value].
function step3_links_changes( $map ) {
	$ids     = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	$changes = array();
	$counts  = array( 'pages' => count( $ids ), 'no IPEDS ID' => 0, 'not in the file' => 0, 'already right' => 0 );
	$empty   = array_fill( 0, count( STEP3_FIELDS ), '' );
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		update_meta_cache( 'post', $chunk );
		foreach ( $chunk as $id ) {
			$unitid = trim( (string) get_post_meta( $id, 'ipeds_unitid', true ) );
			if ( ! ctype_digit( $unitid ) ) {
				++$counts['no IPEDS ID'];
				continue;
			}
			if ( ! isset( $map[ $unitid ] ) ) {
				++$counts['not in the file'];
			}
			$new  = isset( $map[ $unitid ] ) ? $map[ $unitid ] : $empty;
			$old  = array();
			$same = true;
			foreach ( STEP3_FIELDS as $i => $key ) {
				$had   = metadata_exists( 'post', $id, $key );
				$value = $had ? (string) get_post_meta( $id, $key, true ) : '';
				$old[] = $had ? 1 : 0;
				$old[] = $value;
				// Right as it is: the field holds the new value, or is absent where the new value is empty
				$same = $same && ( $had ? ( '' !== $new[ $i ] && $value === $new[ $i ] ) : '' === $new[ $i ] );
			}
			if ( $same ) {
				++$counts['already right'];
				continue;
			}
			$changes[] = array_merge( array( $id, get_post_field( 'post_name', $id ) ), $old, $new );
		}
	}
	$parts = array();
	foreach ( $counts as $what => $count ) {
		$parts[] = "$what $count";
	}
	WP_CLI::log( 'published college pages: ' . implode( ', ', $parts ) . '; to write ' . count( $changes ) );
	return $changes;
}

// Where a change row's new values start
function step3_new_at() {
	return 2 + 2 * count( STEP3_FIELDS );
}

function step3_links_plan( $file ) {
	$k = step3_new_at();
	foreach ( array_slice( step3_links_changes( step3_links_map( $file ) ), 0, 5 ) as $c ) {
		WP_CLI::log( "  e.g. {$c[1]} (post {$c[0]}): link " . ( $c[2] ? "\"{$c[3]}\"" : 'none' ) . ' -> ' . ( '' !== $c[ $k ] ? "{$c[$k]} ({$c[$k + 1]})" : 'none' )
			. '; address ' . ( '' !== $c[ $k + 2 ] ? "{$c[$k + 2]}, {$c[$k + 3]}" : 'none' ) );
	}
}

function step3_links_apply( $file, $log ) {
	$changes = step3_links_changes( step3_links_map( $file ) );
	$fh      = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$k = step3_new_at();
	$n = 0;
	foreach ( $changes as $c ) {
		fwrite( $fh, implode( "\t", $c ) . "\n" );
		fflush( $fh );
		foreach ( STEP3_FIELDS as $i => $key ) {
			if ( '' === $c[ $k + $i ] ) {
				delete_post_meta( $c[0], $key );
			} else {
				update_post_meta( $c[0], $key, $c[ $k + $i ] );
			}
		}
		++$n;
	}
	fclose( $fh );
	WP_CLI::log( "wrote the admissions link and address fields on $n pages; log $log" );
}

function step3_links_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( $lines as $line ) {
		$c  = array_pad( explode( "\t", $line ), step3_new_at() + count( STEP3_FIELDS ), '' );
		$ok = ctype_digit( $c[0] );
		foreach ( STEP3_FIELDS as $i => $key ) {
			$ok = $ok && in_array( $c[ 2 + 2 * $i ], array( '0', '1' ), true );
		}
		if ( ! $ok ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		foreach ( STEP3_FIELDS as $i => $key ) {
			if ( '1' === $c[ 2 + 2 * $i ] ) {
				update_post_meta( (int) $c[0], $key, $c[ 3 + 2 * $i ] );
			} else {
				delete_post_meta( (int) $c[0], $key );
			}
		}
		++$n;
	}
	WP_CLI::log( "put back the admissions link and address fields on $n pages from $log" );
}

$cmd = isset( $args[0] ) ? $args[0] : '';
if ( 'plan' === $cmd && isset( $args[1] ) ) {
	step3_links_plan( $args[1] );
} elseif ( 'apply' === $cmd && isset( $args[1], $args[2] ) ) {
	step3_links_apply( $args[1], $args[2] );
} elseif ( 'revert' === $cmd && isset( $args[1] ) ) {
	step3_links_revert( $args[1] );
} else {
	WP_CLI::error( 'usage: plan <links.csv> | apply <links.csv> <log.tsv> | revert <log.tsv>' );
}
