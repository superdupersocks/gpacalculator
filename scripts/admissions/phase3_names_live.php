<?php
/**
 * Admissions Phase 3, on the server: the college page titles that lost punctuation or still carry a former name, from
 * data/admissions/audit/phase3_names.csv (made by scripts/admissions/phase3_names.py). For a renamed college ("former
 * name" rows) the old name also goes into the post's former_name field: the page says "(formerly ...)" and the hub's
 * search still finds it. scripts/admissions/phase3_names_live.sh pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <names.csv>            dry run: each post's title now and after
 *   apply  <names.csv> <log.tsv>  change the titles, logging what each post held before it is written
 *   revert <log.tsv>              put every logged title and former_name back as it was, newest first
 *
 * A row is skipped, and reported, unless exactly one published college post has its slug, the post is the row's
 * college (same ipeds_unitid) and its title is the row's old title (or already the new one). The address (slug) never
 * changes, and a former_name the post already has is never overwritten.
 */

const NAMES_COLS = array( 'slug', 'unitid', 'old_title', 'new_title', 'kind', 'former_name' );

function names_rows( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	foreach ( NAMES_COLS as $col ) {
		if ( ! in_array( $col, (array) $head, true ) ) {
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

// A title as it reads: entities decoded (WordPress stores & as &amp;), spaces collapsed.
function names_clean( $s ) {
	return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
}

// The one published college post with this slug, if it is the row's college and still has the old or new title,
// or 0 and why not.
function names_post( $row ) {
	global $wpdb;
	$found = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_status, post_title FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$row['slug']
		)
	);
	if ( count( $found ) !== 1 ) {
		return array( 0, count( $found ) . ' college posts with this slug' );
	}
	if ( 'publish' !== $found[0]->post_status ) {
		return array( 0, 'post is ' . $found[0]->post_status );
	}
	$id  = (int) $found[0]->ID;
	$uid = trim( (string) get_post_meta( $id, 'ipeds_unitid', true ) );
	if ( $uid !== $row['unitid'] ) {
		return array( 0, "the page's IPEDS id is \"$uid\", the row's {$row['unitid']}" );
	}
	$title = names_clean( $found[0]->post_title );
	if ( $title !== names_clean( $row['old_title'] ) && $title !== names_clean( $row['new_title'] ) ) {
		return array( 0, "the title is now \"$title\"" );
	}
	return array( $id, '' );
}

// What one row would change on its post: field => [ exists, old value, new value ], for post_title and former_name.
function names_changes( $id, $row ) {
	$out = array();
	$raw = (string) get_post_field( 'post_title', $id, 'raw' );
	if ( names_clean( $raw ) !== names_clean( $row['new_title'] ) ) {
		$out['post_title'] = array( true, $raw, $row['new_title'] );
	}
	$former = trim( (string) $row['former_name'] );
	if ( 'former name' === $row['kind'] && '' !== $former ) {
		$exists = metadata_exists( 'post', $id, 'former_name' );
		$old    = (string) get_post_meta( $id, 'former_name', true );
		if ( '' === trim( $old ) ) {
			$out['former_name'] = array( $exists, $old, $former );
		} elseif ( $old !== $former ) {
			WP_CLI::warning( "{$row['slug']}: keeps the former name it has, \"$old\" (the row has \"$former\")" );
		}
	}
	return $out;
}

function names_plan( $file ) {
	$ready   = 0;
	$same    = 0;
	$skipped = 0;
	$kinds   = array();
	foreach ( names_rows( $file ) as $row ) {
		list( $id, $why ) = names_post( $row );
		if ( ! $id ) {
			WP_CLI::log( "SKIP {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = names_changes( $id, $row );
		if ( ! $changes ) {
			++$same;
			continue;
		}
		++$ready;
		$kinds[ $row['kind'] ] = ( $kinds[ $row['kind'] ] ?? 0 ) + 1;
		$was = isset( $changes['post_title'] ) ? names_clean( $changes['post_title'][1] ) : $row['new_title'];
		WP_CLI::log( "ok   {$row['slug']} (post $id, {$row['kind']}): \"$was\" -> \"{$row['new_title']}\"" . ( isset( $changes['former_name'] ) ? " (formerly {$changes['former_name'][2]})" : '' ) );
	}
	$by_kind = array();
	foreach ( $kinds as $k => $n ) {
		$by_kind[] = "$n $k";
	}
	WP_CLI::log( "$ready rows would change (" . implode( ', ', $by_kind ) . "), $same already match, $skipped skipped" );
}

function names_apply( $file, $log ) {
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$done    = 0;
	$same    = 0;
	$skipped = 0;
	foreach ( names_rows( $file ) as $row ) {
		list( $id, $why ) = names_post( $row );
		if ( ! $id ) {
			WP_CLI::warning( "skipped {$row['slug']}: $why" );
			++$skipped;
			continue;
		}
		$changes = names_changes( $id, $row );
		if ( ! $changes ) {
			++$same;
			continue;
		}
		foreach ( $changes as $f => $c ) {
			$was = array(
				'exists' => $c[0],
				'value'  => $c[1],
			);
			fwrite( $fh, implode( "\t", array( $row['slug'], $id, $f, wp_json_encode( $was ) ) ) . "\n" );
			fflush( $fh );
			if ( 'post_title' === $f ) {
				$res = wp_update_post(
					wp_slash(
						array(
							'ID'         => $id,
							'post_title' => $c[2],
							'post_name'  => $row['slug'],
						)
					),
					true
				);
				if ( is_wp_error( $res ) ) {
					WP_CLI::warning( "{$row['slug']}: title not changed: " . $res->get_error_message() );
				}
			} else {
				update_post_meta( $id, $f, wp_slash( $c[2] ) );
			}
		}
		if ( get_post_field( 'post_name', $id ) !== $row['slug'] ) {
			WP_CLI::warning( "{$row['slug']}: the address changed to " . get_post_field( 'post_name', $id ) . '; revert this run' );
		}
		++$done;
	}
	fclose( $fh );
	WP_CLI::log( "applied $done, already matched $same, skipped $skipped; log $log" );
}

function names_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $slug, $id, $f, $was ) = array_pad( explode( "\t", $line, 4 ), 4, '' );
		$was = json_decode( $was, true );
		if ( ! is_array( $was ) || ! in_array( $f, array( 'post_title', 'former_name' ), true ) ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		if ( 'post_title' === $f ) {
			$res = wp_update_post(
				wp_slash(
					array(
						'ID'         => (int) $id,
						'post_title' => (string) $was['value'],
						'post_name'  => $slug,
					)
				),
				true
			);
			if ( is_wp_error( $res ) ) {
				WP_CLI::warning( "$slug: " . $res->get_error_message() );
				continue;
			}
		} elseif ( $was['exists'] ) {
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
	names_plan( $args[1] );
} elseif ( 'apply' === $cmd && isset( $args[1], $args[2] ) ) {
	names_apply( $args[1], $args[2] );
} elseif ( 'revert' === $cmd && isset( $args[1] ) ) {
	names_revert( $args[1] );
} else {
	WP_CLI::error( 'usage: plan <names.csv> | apply <names.csv> <log.tsv> | revert <log.tsv>' );
}
