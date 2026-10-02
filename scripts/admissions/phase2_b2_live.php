<?php
/**
 * Admissions Phase 2, step B2, on the server: the average high school GPA each college reported on its own Common
 * Data Set goes into the college post's own fields (cds_gpa, cds_gpa_year, cds_gpa_submit_pct, cds_gpa_basis,
 * cds_gpa_source_url), which the theme labels "as reported by the college" with the year and cites in the FAQ.
 * scripts/admissions/phase2_b2_live.sh pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <gpa.csv>            dry run: the post each row would update and what its changing fields hold now
 *   apply  <gpa.csv> <log.tsv>  write the fields that change, logging what each held before it is written
 *   revert <log.tsv>            put every logged field back as it was, newest first
 *
 * A row is skipped, and reported, unless exactly one published college post has its slug. A field is written only
 * when its value changes, so running apply again with a longer list writes only the new and changed rows.
 */

const B2_FIELDS = array( 'cds_gpa', 'cds_gpa_year', 'cds_gpa_submit_pct', 'cds_gpa_basis', 'cds_gpa_source_url' );

function b2_rows( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
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

// The one published college post with this slug, or 0 and why not.
function b2_post( $slug ) {
	global $wpdb;
	$found = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$slug
		)
	);
	if ( count( $found ) !== 1 ) {
		return array( 0, count( $found ) . ' college posts with this slug' );
	}
	if ( 'publish' !== $found[0]->post_status ) {
		return array( 0, 'post is ' . $found[0]->post_status );
	}
	return array( (int) $found[0]->ID, '' );
}

// The fields of one row that would change on its post: field => [ exists, old value, new value ].
function b2_changes( $id, $row ) {
	$out = array();
	foreach ( B2_FIELDS as $f ) {
		$exists = metadata_exists( 'post', $id, $f );
		$old    = (string) get_post_meta( $id, $f, true );
		$new    = (string) $row[ $f ];
		if ( ( $exists && $old === $new ) || ( ! $exists && '' === $new ) ) {
			continue;
		}
		$out[ $f ] = array( $exists, $old, $new );
	}
	return $out;
}

function b2_plan( $file ) {
	$ready   = 0;
	$same    = 0;
	$skipped = 0;
	foreach ( b2_rows( $file ) as $row ) {
		list( $id, $why ) = b2_post( $row['slug'] );
		if ( ! $id ) {
			WP_CLI::log( "SKIP {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = b2_changes( $id, $row );
		if ( ! $changes ) {
			++$same;
			continue;
		}
		$now = array();
		foreach ( $changes as $f => $c ) {
			if ( '' !== $c[1] ) {
				$now[] = "$f={$c[1]}";
			}
		}
		++$ready;
		WP_CLI::log( "ok   {$row['slug']} (post $id): GPA {$row['cds_gpa']}, {$row['cds_gpa_year']}" . ( $now ? ' [now: ' . implode( ', ', $now ) . ']' : '' ) );
	}
	WP_CLI::log( "$ready rows would change, $same already match, $skipped skipped" );
}

function b2_apply( $file, $log ) {
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$done    = 0;
	$same    = 0;
	$skipped = 0;
	foreach ( b2_rows( $file ) as $row ) {
		list( $id, $why ) = b2_post( $row['slug'] );
		if ( ! $id ) {
			WP_CLI::warning( "skipped {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = b2_changes( $id, $row );
		foreach ( $changes as $f => $c ) {
			$was = array(
				'exists' => $c[0],
				'value'  => $c[1],
			);
			fwrite( $fh, implode( "\t", array( $row['slug'], $id, $f, wp_json_encode( $was ) ) ) . "\n" );
			fflush( $fh );
			update_post_meta( $id, $f, wp_slash( $c[2] ) );
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

function b2_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $slug, $id, $f, $was ) = array_pad( explode( "\t", $line, 4 ), 4, '' );
		$was = json_decode( $was, true );
		if ( ! is_array( $was ) || ! in_array( $f, B2_FIELDS, true ) ) {
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
	WP_CLI::log( "restored $n fields" );
}

$b2_args = isset( $args ) ? $args : array();
switch ( $b2_args[0] ?? '' ) {
	case 'plan':
		b2_plan( $b2_args[1] ?? '' );
		break;
	case 'apply':
		b2_apply( $b2_args[1] ?? '', $b2_args[2] ?? '' );
		break;
	case 'revert':
		b2_revert( $b2_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan <gpa.csv>, apply <gpa.csv> <log.tsv>, or revert <log.tsv>' );
}
