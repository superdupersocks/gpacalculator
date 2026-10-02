<?php
/**
 * Admissions Phase 4, on the server: each college page's modified date, which the colleges sitemap gives search engines
 * as its lastmod. Phase 2 and 3 changed every page (new figures, the new template at 22:32 UTC on 2 October 2026, then
 * the GPA spread and the names), but the imports wrote fields without touching the posts' dates, so 2,996 of the 3,086
 * sitemap addresses still said 19 April 2026. scripts/admissions/phase4_dates_live.sh pipes this file to
 * `wp eval-file -` with one of:
 *
 *   plan   <since>            dry run: how many published college posts have an older modified date, and how
 *                             Rank Math stores its sitemap cache
 *   apply  <since> <log.tsv>  move those dates to <since> (UTC, 2026-10-02T22:32:00), logging each post's old
 *                             dates first, then clear Rank Math's sitemap cache
 *   revert <log.tsv>          put every logged date back, then clear the cache again
 *   clear                     clear the sitemap cache alone, e.g. after a theme deploy changes what the sitemap lists
 *
 * Only post_modified and post_modified_gmt change; a date already at or after <since> stays. No revision, no
 * save_post: the page's content doesn't change.
 */

// The published college posts modified before $since (UTC): [ID, slug, post_modified, post_modified_gmt].
function dates_rows( $since ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_name, post_modified, post_modified_gmt FROM {$wpdb->posts}
			 WHERE post_type = 'colleges' AND post_status = 'publish' AND post_modified_gmt < %s ORDER BY ID",
			$since
		)
	);
}

// "2026-10-02T22:32:00" (UTC; the T keeps it one shell word) as MySQL writes it.
function dates_since( $since ) {
	$since = str_replace( 'T', ' ', (string) $since );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since ) ) {
		WP_CLI::error( "give the date as YYYY-MM-DDTHH:MM:SS (UTC), not \"$since\"" );
	}
	return $since;
}

// What the sitemap cache is, so a stale one can be found: Rank Math's cache class, its files and its database rows.
function dates_cache_report() {
	global $wpdb;
	$class = '\RankMath\Sitemap\Cache';
	WP_CLI::log( 'Rank Math ' . ( defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : 'not loaded' ) . '; '
		. $class . '::invalidate_storage ' . ( method_exists( $class, 'invalidate_storage' ) ? 'exists' : 'MISSING' ) );
	$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'rank-math';
	$files = is_dir( $dir ) ? glob( $dir . '/*' ) : array();
	WP_CLI::log( count( $files ) . " files in $dir" );
	foreach ( array_slice( $files, 0, 8 ) as $f ) {
		WP_CLI::log( '  ' . basename( $f ) . ' ' . gmdate( 'Y-m-d H:i', filemtime( $f ) ) . ' UTC' );
	}
	$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%rank_math%sitemap%' OR option_name LIKE '%rank_math_sm%' LIMIT 20" );
	WP_CLI::log( count( $rows ) . ' sitemap rows in the options table' . ( $rows ? ': ' . implode( ', ', $rows ) : '' ) );
}

function dates_clear_cache() {
	$class = '\RankMath\Sitemap\Cache';
	if ( method_exists( $class, 'invalidate_storage' ) ) {
		call_user_func( array( $class, 'invalidate_storage' ) );
		WP_CLI::log( 'cleared the Rank Math sitemap cache' );
	} else {
		WP_CLI::warning( "$class::invalidate_storage isn't there: clear the sitemap cache by hand (Rank Math > Sitemap Settings > Save Changes)" );
	}
}

function dates_plan( $since ) {
	$rows = dates_rows( dates_since( $since ) );
	WP_CLI::log( count( $rows ) . " published college posts were last modified before $since UTC and would move to it" );
	$by_day = array();
	foreach ( $rows as $r ) {
		$day            = substr( $r->post_modified_gmt, 0, 10 );
		$by_day[ $day ] = ( $by_day[ $day ] ?? 0 ) + 1;
	}
	ksort( $by_day );
	foreach ( $by_day as $day => $n ) {
		WP_CLI::log( "  now $day: $n" );
	}
	foreach ( array_slice( $rows, 0, 3 ) as $r ) {
		WP_CLI::log( "  e.g. {$r->post_name} (post {$r->ID}): {$r->post_modified_gmt} UTC" );
	}
	dates_cache_report();
}

function dates_apply( $since, $log ) {
	global $wpdb;
	$since = dates_since( $since );
	$fh    = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$local = get_date_from_gmt( $since );
	$n     = 0;
	foreach ( dates_rows( $since ) as $r ) {
		fwrite( $fh, implode( "\t", array( $r->ID, $r->post_name, $r->post_modified, $r->post_modified_gmt ) ) . "\n" );
		fflush( $fh );
		$ok = $wpdb->update(
			$wpdb->posts,
			array( 'post_modified' => $local, 'post_modified_gmt' => $since ),
			array( 'ID' => (int) $r->ID, 'post_type' => 'colleges' )
		);
		if ( false === $ok ) {
			WP_CLI::warning( "{$r->post_name}: not changed: " . $wpdb->last_error );
			continue;
		}
		clean_post_cache( (int) $r->ID );
		++$n;
	}
	fclose( $fh );
	dates_clear_cache();
	WP_CLI::log( "moved $n modified dates to $since UTC ($local site time); log $log" );
}

function dates_revert( $log ) {
	global $wpdb;
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( $lines as $line ) {
		list( $id, $slug, $mod, $gmt ) = array_pad( explode( "\t", $line ), 4, '' );
		if ( ! ctype_digit( $id ) || '' === $gmt ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $mod, 'post_modified_gmt' => $gmt ), array( 'ID' => (int) $id, 'post_type' => 'colleges' ) );
		clean_post_cache( (int) $id );
		++$n;
	}
	dates_clear_cache();
	WP_CLI::log( "put back $n modified dates from $log" );
}

$cmd = isset( $args[0] ) ? $args[0] : '';
if ( 'plan' === $cmd && isset( $args[1] ) ) {
	dates_plan( $args[1] );
} elseif ( 'apply' === $cmd && isset( $args[1], $args[2] ) ) {
	dates_apply( $args[1], $args[2] );
} elseif ( 'revert' === $cmd && isset( $args[1] ) ) {
	dates_revert( $args[1] );
} elseif ( 'clear' === $cmd ) {
	dates_clear_cache();
} else {
	WP_CLI::error( 'usage: plan <since> | apply <since> <log.tsv> | revert <log.tsv> | clear' );
}
