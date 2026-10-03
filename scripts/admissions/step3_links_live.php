<?php
/**
 * Admissions step 3, on the server: each college page's official admissions link for template v2
 * (college_admissions_url, and college_admissions_url_kind = admissions or website). scripts/admissions/step3_links_live.sh
 * uploads data/admissions/audit/step3_admissions_links.csv (ipeds_unitid, college_admissions_url,
 * college_admissions_url_kind; made by scripts/admissions/step3_links.py pick from the links check) and pipes this file
 * to `wp eval-file -` with one of:
 *
 *   plan   <links.csv>            dry run: how many published college pages would get or change the fields
 *   apply  <links.csv> <log.tsv>  write them, logging each page's old values first
 *   revert <log.tsv>              put every logged value back, removing a field where there was none
 *
 * Only those two fields change, and only on pages with an IPEDS ID. A page whose college has no checked link loses a
 * stale one. No revision, no save_post, no change to the page's modified date. Template v1 doesn't read the fields.
 */

const STEP3_FIELDS = array( 'college_admissions_url', 'college_admissions_url_kind' );

// ipeds_unitid => [url, kind], from the CSV.
function step3_links_map( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	if ( array( 'ipeds_unitid', 'college_admissions_url', 'college_admissions_url_kind' ) !== fgetcsv( $fh ) ) {
		WP_CLI::error( "$file: expected the columns ipeds_unitid,college_admissions_url,college_admissions_url_kind" );
	}
	$map = array();
	while ( false !== ( $row = fgetcsv( $fh ) ) ) {
		if ( 3 === count( $row ) && ctype_digit( $row[0] ) && preg_match( '#^https?://[^\s@]+$#', $row[1] ) && in_array( $row[2], array( 'admissions', 'website' ), true ) ) {
			$map[ $row[0] ] = array( $row[1], $row[2] );
		}
	}
	fclose( $fh );
	return $map;
}

// The published college pages whose fields would change: [ID, slug, had url, old url, had kind, old kind, new url, new kind].
function step3_links_changes( $map ) {
	$ids     = get_posts( array( 'post_type' => 'colleges', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
	$changes = array();
	$counts  = array( 'pages' => count( $ids ), 'no IPEDS ID' => 0, 'no checked link' => 0, 'already right' => 0 );
	foreach ( array_chunk( $ids, 500 ) as $chunk ) {
		update_meta_cache( 'post', $chunk );
		foreach ( $chunk as $id ) {
			$unitid = trim( (string) get_post_meta( $id, 'ipeds_unitid', true ) );
			if ( ! ctype_digit( $unitid ) ) {
				++$counts['no IPEDS ID'];
				continue;
			}
			$new = isset( $map[ $unitid ] ) ? $map[ $unitid ] : array( '', '' );
			$old = array();
			foreach ( STEP3_FIELDS as $key ) {
				$had   = metadata_exists( 'post', $id, $key );
				$old[] = $had ? 1 : 0;
				$old[] = $had ? (string) get_post_meta( $id, $key, true ) : '';
			}
			if ( ! isset( $map[ $unitid ] ) ) {
				++$counts['no checked link'];
				if ( ! $old[0] && ! $old[2] ) {
					continue;
				}
			} elseif ( $old[0] && $old[2] && $old[1] === $new[0] && $old[3] === $new[1] ) {
				++$counts['already right'];
				continue;
			}
			$changes[] = array_merge( array( $id, get_post_field( 'post_name', $id ) ), $old, $new );
		}
	}
	$parts = array();
	foreach ( $counts as $what => $n ) {
		$parts[] = "$what $n";
	}
	WP_CLI::log( 'published college pages: ' . implode( ', ', $parts ) . '; to write ' . count( $changes ) );
	return $changes;
}

function step3_links_plan( $file ) {
	foreach ( array_slice( step3_links_changes( step3_links_map( $file ) ), 0, 5 ) as $c ) {
		WP_CLI::log( "  e.g. {$c[1]} (post {$c[0]}): " . ( $c[2] ? "\"{$c[3]}\"" : 'none' ) . ' -> ' . ( '' !== $c[6] ? "{$c[6]} ({$c[7]})" : 'none' ) );
	}
}

function step3_links_apply( $file, $log ) {
	$changes = step3_links_changes( step3_links_map( $file ) );
	$fh      = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$n = 0;
	foreach ( $changes as $c ) {
		fwrite( $fh, implode( "\t", $c ) . "\n" );
		fflush( $fh );
		foreach ( STEP3_FIELDS as $i => $key ) {
			if ( '' === $c[6 + $i] ) {
				delete_post_meta( $c[0], $key );
			} else {
				update_post_meta( $c[0], $key, $c[6 + $i] );
			}
		}
		++$n;
	}
	fclose( $fh );
	WP_CLI::log( "wrote the admissions link on $n pages; log $log" );
}

function step3_links_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( $lines as $line ) {
		$c = array_pad( explode( "\t", $line ), 8, '' );
		if ( ! ctype_digit( $c[0] ) || ! in_array( $c[2], array( '0', '1' ), true ) || ! in_array( $c[4], array( '0', '1' ), true ) ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		foreach ( STEP3_FIELDS as $i => $key ) {
			if ( '1' === $c[2 + 2 * $i] ) {
				update_post_meta( (int) $c[0], $key, $c[3 + 2 * $i] );
			} else {
				delete_post_meta( (int) $c[0], $key );
			}
		}
		++$n;
	}
	WP_CLI::log( "put back the admissions link on $n pages from $log" );
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
