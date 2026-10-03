<?php
/**
 * Admissions step 2 (Digant's plan of 2026-10-03), on the server: each college page's tier, and noindex for the pages
 * the tiering takes out of search. scripts/admissions/tiering_live.sh pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <tiers.csv>              dry run: how many pages get each tier and how many go out of search
 *   apply  <tiers.csv> <log.tsv>    write them, logging each value as it was first
 *   revert <log.tsv>                put every logged value back, newest first
 *
 * tiers.csv (data/admissions/tiering/tiers.csv, from scripts/admissions/tiering.py) has one row per page: its slug,
 * tier (A, B or C) and recommendation (index or noindex). Each page gets the post meta admissions_tier (the new
 * template's flag reads it). A noindex page gets Rank Math's own robots setting for the page (rank_math_robots,
 * the page's Advanced tab), "No Index" with the rest of what the page had, so the page leaves search and Rank Math
 * leaves it out of the sitemap; an index page's robots setting stays as it is unless it says No Index, which the
 * tiering turns back to Index. Only those two meta values change: no revision and no new modified date. A row is
 * skipped, and reported, unless exactly one published college page has its slug and that page has at most one value
 * of each.
 */

function tl_csv( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	foreach ( array( 'slug', 'tier', 'recommendation' ) as $col ) {
		if ( ! in_array( $col, (array) $head, true ) ) {
			WP_CLI::error( "$file has no $col column" );
		}
	}
	$rows = array();
	while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		if ( count( $r ) === count( $head ) ) {
			$rows[] = array_combine( $head, $r );
		}
	}
	fclose( $fh );
	if ( ! $rows ) {
		WP_CLI::error( "no rows in $file" );
	}
	return $rows;
}

// The one published college page with this slug, or 0.
function tl_post( $slug ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'colleges' AND post_status = 'publish'", $slug ) );
	return 1 === count( $ids ) ? (int) $ids[0] : 0;
}

// The robots setting a page should have: its own entries with "noindex" or "index" first, as Rank Math's Advanced tab
// saves them.
function tl_robots( $now, $noindex ) {
	$keep = array_values( array_diff( (array) $now, array( 'index', 'noindex' ) ) );
	return array_merge( array( $noindex ? 'noindex' : 'index' ), $keep );
}

// What changes for every row: [changes, skipped]; a change is [post ID, slug, meta key, old value or null, new value].
function tl_changes( array $rows ) {
	$out  = array();
	$skip = array();
	foreach ( $rows as $r ) {
		$slug = $r['slug'];
		if ( ! in_array( $r['tier'], array( 'A', 'B', 'C' ), true ) || ! in_array( $r['recommendation'], array( 'index', 'noindex' ), true ) ) {
			$skip[] = "$slug: tier '{$r['tier']}', recommendation '{$r['recommendation']}'";
			continue;
		}
		$id = tl_post( $slug );
		if ( ! $id ) {
			$skip[] = "$slug: not exactly one published college page";
			continue;
		}
		foreach ( array( 'admissions_tier', 'rank_math_robots' ) as $key ) {
			if ( count( get_post_meta( $id, $key ) ) > 1 ) {
				$skip[] = "$slug: more than one $key value";
				continue 2;
			}
		}
		$tier = metadata_exists( 'post', $id, 'admissions_tier' ) ? get_post_meta( $id, 'admissions_tier', true ) : null;
		if ( $tier !== $r['tier'] ) {
			$out[] = array( $id, $slug, 'admissions_tier', $tier, $r['tier'] );
		}
		$has    = metadata_exists( 'post', $id, 'rank_math_robots' );
		$robots = $has ? get_post_meta( $id, 'rank_math_robots', true ) : null;
		$want   = tl_robots( $robots ? (array) $robots : array(), 'noindex' === $r['recommendation'] );
		if ( 'noindex' === $r['recommendation'] || in_array( 'noindex', (array) $robots, true ) ) {
			if ( array_values( (array) $robots ) !== $want ) {
				$out[] = array( $id, $slug, 'rank_math_robots', $robots, $want );
			}
		}
	}
	return array( $out, $skip );
}

function tl_plan( $file ) {
	list( $changes, $skip ) = tl_changes( tl_csv( $file ) );
	$n                      = array();
	$examples               = array();
	foreach ( $changes as $c ) {
		$key       = 'admissions_tier' === $c[2] ? "tier {$c[4]}" : ( in_array( 'noindex', $c[4], true ) ? 'noindex' : 'noindex lifted' );
		$n[ $key ] = ( $n[ $key ] ?? 0 ) + 1;
		$line      = "{$c[1]} ({$c[0]}) {$c[2]}: " . wp_json_encode( $c[3] ) . ' -> ' . wp_json_encode( $c[4] );
		if ( 'noindex lifted' === $key ) {
			WP_CLI::log( "LIFT $line" );
		} elseif ( null !== $c[3] && count( $examples ) < 10 ) {
			$examples[] = $line;
		}
	}
	ksort( $n );
	foreach ( $examples as $e ) {
		WP_CLI::log( "had a value: $e" );
	}
	foreach ( $skip as $s ) {
		WP_CLI::log( "SKIP $s" );
	}
	$parts = array();
	foreach ( $n as $k => $v ) {
		$parts[] = "$k: $v";
	}
	WP_CLI::log( count( $changes ) . ' values to write (' . implode( ', ', $parts ) . '), ' . count( $skip ) . ' skipped' );
}

function tl_apply( $file, $log ) {
	list( $changes, $skip ) = tl_changes( tl_csv( $file ) );
	$fh                     = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	foreach ( $changes as $c ) {
		// post ID, meta key, the value before (base64 of its JSON; "-" when the page had none)
		fwrite( $fh, implode( "\t", array( 'meta', $c[0], $c[2], null === $c[3] ? '-' : base64_encode( wp_json_encode( $c[3] ) ) ) ) . "\n" );
		fflush( $fh );
		update_post_meta( $c[0], $c[2], $c[4] );
	}
	fclose( $fh );
	foreach ( $skip as $s ) {
		WP_CLI::warning( "skipped $s" );
	}
	WP_CLI::log( 'wrote ' . count( $changes ) . ' values, ' . count( $skip ) . " skipped; log $log" );
}

function tl_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		$f = explode( "\t", $line );
		if ( 'meta' !== $f[0] || count( $f ) !== 4 ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		if ( '-' === $f[3] ) {
			delete_post_meta( (int) $f[1], $f[2] );
		} else {
			update_post_meta( (int) $f[1], $f[2], json_decode( base64_decode( $f[3] ), true ) );
		}
		++$n;
	}
	WP_CLI::log( "put back $n values" );
}

$tl_args = isset( $args ) ? $args : array();
switch ( $tl_args[0] ?? '' ) {
	case 'plan':
		tl_plan( $tl_args[1] ?? '' );
		break;
	case 'apply':
		tl_apply( $tl_args[1] ?? '', $tl_args[2] ?? '' );
		break;
	case 'revert':
		tl_revert( $tl_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: wp eval-file - plan <tiers.csv> | apply <tiers.csv> <log.tsv> | revert <log.tsv>' );
}
