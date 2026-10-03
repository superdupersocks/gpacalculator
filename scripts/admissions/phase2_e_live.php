<?php
/**
 * Admissions Phase 2, checkpoint E, on the server: the fresh federal data (IPEDS 2024–25 provisional release, plus
 * College Scorecard's average SAT estimate, labeled as such) goes into each confidently matched college post. Every
 * column of data/admissions/audit/phase2_e_import.csv except slug and post_title is a post meta key, which the
 * theme's college-data.php reads with its source and year. scripts/admissions/phase2_e_live.sh pipes this file to
 * `wp eval-file -` with one of:
 *
 *   plan   <import.csv>            dry run: per field, how many posts it would change, and every row it would skip
 *   apply  <import.csv> <log.tsv>  write the fields, logging what each changed field held before
 *   revert <log.tsv>               put every logged field back as it was, newest first
 *
 * A row is skipped, and reported, unless exactly one published college post has its slug and still has the title
 * the import was built from. A field is written only when its value changes: an empty value empties a field that has
 * one and never creates one. Running apply twice changes nothing the second time.
 */

function e_rows( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	if ( ! $head || array_slice( $head, 0, 3 ) !== array( 'slug', 'post_title', 'ipeds_unitid' ) ) {
		WP_CLI::error( "$file doesn't start with slug, post_title, ipeds_unitid" );
	}
	foreach ( array_slice( $head, 2 ) as $key ) {
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
			WP_CLI::error( "unexpected column name in $file: $key" );
		}
	}
	$rows = array();
	while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		if ( count( $r ) !== count( $head ) ) {
			WP_CLI::error( "row with " . count( $r ) . " columns (the header has " . count( $head ) . "): {$r[0]}" );
		}
		$rows[] = array_combine( $head, $r );
	}
	fclose( $fh );
	if ( ! $rows ) {
		WP_CLI::error( "no rows in $file" );
	}
	return array( array_slice( $head, 2 ), $rows );
}

// The one published college post with this slug and title, or 0 and why not.
function e_post( $slug, $title ) {
	global $wpdb;
	$found = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_status, post_title FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$slug
		)
	);
	if ( count( $found ) !== 1 ) {
		return array( 0, count( $found ) . ' college posts with this slug' );
	}
	if ( 'publish' !== $found[0]->post_status ) {
		return array( 0, 'post is ' . $found[0]->post_status );
	}
	if ( $found[0]->post_title !== $title ) {
		return array( 0, 'title is now "' . $found[0]->post_title . '"' );
	}
	return array( (int) $found[0]->ID, '' );
}

// The fields of one row that would change on its post: field => [ exists, old value, new value ].
function e_changes( $id, $fields, $row ) {
	$out = array();
	foreach ( $fields as $f ) {
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

function e_plan( $file ) {
	list( $fields, $rows ) = e_rows( $file );
	$ready   = 0;
	$same    = 0;
	$skipped = 0;
	$counts  = array();
	foreach ( $rows as $row ) {
		list( $id, $why ) = e_post( $row['slug'], $row['post_title'] );
		if ( ! $id ) {
			WP_CLI::log( "SKIP {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = e_changes( $id, $fields, $row );
		foreach ( $changes as $f => $c ) {
			$kind = '' === $c[2] ? 'emptied' : ( $c[0] && '' !== $c[1] ? 'replaced' : 'added' );
			if ( ! isset( $counts[ $f ] ) ) {
				$counts[ $f ] = array( 'added' => 0, 'replaced' => 0, 'emptied' => 0 );
			}
			++$counts[ $f ][ $kind ];
		}
		if ( $changes ) {
			++$ready;
		} else {
			++$same;
		}
		wp_cache_delete( $id, 'post_meta' );
	}
	foreach ( $fields as $f ) {
		if ( isset( $counts[ $f ] ) ) {
			$c = $counts[ $f ];
			WP_CLI::log( sprintf( '%-66s added %5d  replaced %5d  emptied %5d', $f, $c['added'], $c['replaced'], $c['emptied'] ) );
		}
	}
	WP_CLI::log( "$ready posts would change, $same already match, $skipped skipped" );
}

function e_apply( $file, $log ) {
	list( $fields, $rows ) = e_rows( $file );
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$posts   = 0;
	$written = 0;
	$skipped = 0;
	foreach ( $rows as $row ) {
		list( $id, $why ) = e_post( $row['slug'], $row['post_title'] );
		if ( ! $id ) {
			WP_CLI::warning( "skipped {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = e_changes( $id, $fields, $row );
		foreach ( $changes as $f => $c ) {
			$was = array(
				'exists' => $c[0],
				'value'  => $c[1],
			);
			fwrite( $fh, implode( "\t", array( $row['slug'], $id, $f, wp_json_encode( $was ) ) ) . "\n" );
			fflush( $fh );
			update_post_meta( $id, $f, wp_slash( $c[2] ) );
			++$written;
		}
		if ( $changes ) {
			++$posts;
		}
		wp_cache_delete( $id, 'post_meta' );
	}
	fclose( $fh );
	WP_CLI::log( "updated $posts posts ($written fields), skipped $skipped; log $log" );
}

function e_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $slug, $id, $f, $was ) = array_pad( explode( "\t", $line, 4 ), 4, '' );
		$was = json_decode( $was, true );
		if ( ! is_array( $was ) || ! (int) $id || ! preg_match( '/^[a-z][a-z0-9_]*$/', $f ) ) {
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

$e_args = isset( $args ) ? $args : array();
switch ( $e_args[0] ?? '' ) {
	case 'plan':
		e_plan( $e_args[1] ?? '' );
		break;
	case 'apply':
		e_apply( $e_args[1] ?? '', $e_args[2] ?? '' );
		break;
	case 'revert':
		e_revert( $e_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan <import.csv>, apply <import.csv> <log.tsv>, or revert <log.tsv>' );
}
