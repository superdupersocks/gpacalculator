<?php
/**
 * Admissions Phase 3, on the server: how each college's first-year students' high school GPAs were spread (Common
 * Data Set C11), from data/admissions/audit/phase3_gpa_bands.csv (made by scripts/admissions/phase3_gpa_bands.py),
 * into the post's cds_gpa_band_* fields. The college page shows them under the average GPA the page already cites,
 * from the same Common Data Set. scripts/admissions/phase3_bands_live.sh pipes this file to `wp eval-file -` with
 * one of:
 *
 *   plan   <bands.csv>            dry run: the post each row would update and its ranges
 *   apply  <bands.csv> <log.tsv>  write the fields that change, logging what each held before it is written
 *   revert <log.tsv>              put every logged field back as it was, newest first
 *
 * A row is skipped, and reported, unless exactly one published college post has its slug and that post shows the
 * same average GPA and Common Data Set year as the row (the ranges belong with that average, never another one).
 * A range the file leaves blank is not written (a blank is not 0%). A field is written only when its value changes.
 */

const BANDS_FIELDS = array(
	'cds_gpa_band_400',
	'cds_gpa_band_375',
	'cds_gpa_band_350',
	'cds_gpa_band_325',
	'cds_gpa_band_300',
	'cds_gpa_band_250',
	'cds_gpa_band_200',
	'cds_gpa_band_100',
	'cds_gpa_band_000',
);

function bands_rows( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	foreach ( array_merge( array( 'slug', 'cds_gpa', 'cds_gpa_year' ), BANDS_FIELDS ) as $col ) {
		if ( ! in_array( $col, $head, true ) ) {
			WP_CLI::error( "$file has no $col column" );
		}
	}
	$rows = array();
	while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		$rows[] = array_combine( $head, $r );
	}
	fclose( $fh );
	if ( ! $rows ) {
		WP_CLI::error( "no rows in $file" );
	}
	return $rows;
}

// The one published college post with this slug showing the row's average GPA and year, or 0 and why not.
function bands_post( $row ) {
	global $wpdb;
	$found = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$row['slug']
		)
	);
	if ( count( $found ) !== 1 ) {
		return array( 0, count( $found ) . ' college posts with this slug' );
	}
	if ( 'publish' !== $found[0]->post_status ) {
		return array( 0, 'post is ' . $found[0]->post_status );
	}
	$id   = (int) $found[0]->ID;
	$gpa  = trim( (string) get_post_meta( $id, 'cds_gpa', true ) );
	$year = trim( (string) get_post_meta( $id, 'cds_gpa_year', true ) );
	$url  = trim( (string) get_post_meta( $id, 'cds_gpa_source_url', true ) );
	if ( '' === $gpa || '' === $url ) {
		return array( 0, 'the page shows no cited GPA' );
	}
	if ( ! is_numeric( $gpa ) || abs( (float) $gpa - (float) $row['cds_gpa'] ) > 0.0001 || $year !== $row['cds_gpa_year'] ) {
		return array( 0, "the page's GPA is $gpa ($year), the row's {$row['cds_gpa']} ({$row['cds_gpa_year']})" );
	}
	return array( $id, '' );
}

// The fields of one row that would change on its post: field => [ exists, old value, new value ].
function bands_changes( $id, $row ) {
	$out = array();
	foreach ( BANDS_FIELDS as $f ) {
		$exists = metadata_exists( 'post', $id, $f );
		$old    = (string) get_post_meta( $id, $f, true );
		$new    = trim( (string) $row[ $f ] );
		if ( '' !== $new && ! is_numeric( $new ) ) {
			WP_CLI::error( "{$row['slug']}: $f is not a number: $new" );
		}
		if ( ( $exists && $old === $new ) || ( ! $exists && '' === $new ) ) {
			continue;
		}
		$out[ $f ] = array( $exists, $old, $new );
	}
	return $out;
}

function bands_summary( $row ) {
	$labels = array( '4.0', '3.75-3.99', '3.50-3.74', '3.25-3.49', '3.00-3.24', '2.50-2.99', '2.00-2.49', '1.00-1.99', 'below 1.0' );
	$parts  = array();
	foreach ( BANDS_FIELDS as $i => $f ) {
		if ( '' !== trim( (string) $row[ $f ] ) ) {
			$parts[] = $labels[ $i ] . ' ' . trim( $row[ $f ] ) . '%';
		}
	}
	return implode( ', ', $parts );
}

function bands_plan( $file ) {
	$ready   = 0;
	$same    = 0;
	$skipped = 0;
	foreach ( bands_rows( $file ) as $row ) {
		list( $id, $why ) = bands_post( $row );
		if ( ! $id ) {
			WP_CLI::log( "SKIP {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = bands_changes( $id, $row );
		if ( ! $changes ) {
			++$same;
			continue;
		}
		$now = array();
		foreach ( $changes as $f => $c ) {
			if ( $c[0] ) {
				$now[] = "$f={$c[1]}";
			}
		}
		++$ready;
		WP_CLI::log( "ok   {$row['slug']} (post $id): GPA {$row['cds_gpa']}, {$row['cds_gpa_year']}: " . bands_summary( $row ) . ( $now ? ' [now: ' . implode( ', ', $now ) . ']' : '' ) );
	}
	WP_CLI::log( "$ready rows would change, $same already match, $skipped skipped" );
}

function bands_apply( $file, $log ) {
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$done    = 0;
	$same    = 0;
	$skipped = 0;
	foreach ( bands_rows( $file ) as $row ) {
		list( $id, $why ) = bands_post( $row );
		if ( ! $id ) {
			WP_CLI::warning( "skipped {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = bands_changes( $id, $row );
		foreach ( $changes as $f => $c ) {
			$was = array(
				'exists' => $c[0],
				'value'  => $c[1],
			);
			fwrite( $fh, implode( "\t", array( $row['slug'], $id, $f, wp_json_encode( $was ) ) ) . "\n" );
			fflush( $fh );
			if ( '' === $c[2] ) {
				delete_post_meta( $id, $f );
			} else {
				update_post_meta( $id, $f, wp_slash( $c[2] ) );
			}
		}
		if ( $changes ) {
			++$done;
		} else {
			++$same;
		}
	}
	fclose( $fh );
	WP_CLI::log( "applied $done, already matched $same, skipped $skipped; log $log" );
}

function bands_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $slug, $id, $f, $was ) = array_pad( explode( "\t", $line, 4 ), 4, '' );
		$was = json_decode( $was, true );
		if ( ! is_array( $was ) || ! in_array( $f, BANDS_FIELDS, true ) ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		if ( $was['exists'] ) {
			update_post_meta( (int) $id, $f, wp_slash( (string) $was['value'] ) );
		} else {
			delete_post_meta( (int) $id, $f );
		}
		++$n;
	}
	WP_CLI::log( "reverted $n fields from $log" );
}

$cmd = isset( $args[0] ) ? $args[0] : '';
if ( 'plan' === $cmd && isset( $args[1] ) ) {
	bands_plan( $args[1] );
} elseif ( 'apply' === $cmd && isset( $args[1], $args[2] ) ) {
	bands_apply( $args[1], $args[2] );
} elseif ( 'revert' === $cmd && isset( $args[1] ) ) {
	bands_revert( $args[1] );
} else {
	WP_CLI::error( 'usage: plan <bands.csv> | apply <bands.csv> <log.tsv> | revert <log.tsv>' );
}
