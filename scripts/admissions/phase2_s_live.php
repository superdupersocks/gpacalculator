<?php
/**
 * Admissions Phase 2, the held pages' next step, on the server: add a page for each college in
 * data/admissions/audit/phase2_s_new.csv, with its fields from phase2_s_pages.csv (E's columns: every column but slug
 * and post_title is a post meta key, which the theme's college-data.php reads with its source and year).
 * scripts/admissions/phase2_s_live.sh pipes this file to `wp eval-file -` with one of:
 *
 *   plan   <new.csv> <pages.csv>            dry run: each page it would add, or why not
 *   apply  <new.csv> <pages.csv> <log.tsv>  add each page, published with its fields in one insert, and log its ID
 *   revert <log.tsv>                        move the pages the logged run added to the trash
 *
 * A page is added only when no college post outside the trash has its slug and no Rank Math redirect answers its
 * /admissions/ or old /admission/ address. Like E, it writes only fields with a value. Running apply twice adds nothing
 * the second time.
 */

function s_csv( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	$rows = array();
	while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		if ( count( $r ) !== count( $head ) ) {
			WP_CLI::error( 'row with ' . count( $r ) . ' columns (the header has ' . count( $head ) . "): {$r[0]}" );
		}
		$rows[] = array_combine( $head, $r );
	}
	fclose( $fh );
	if ( ! $rows || array_slice( $head, 0, 2 ) !== array( 'slug', 'post_title' ) ) {
		WP_CLI::error( "$file has no rows, or doesn't start with slug, post_title" );
	}
	return $rows;
}

// The fields a new page gets: every column of its pages.csv row but slug and post_title, without the empty ones.
function s_fields( array $row ) {
	$out = array();
	foreach ( $row as $key => $value ) {
		if ( 'slug' === $key || 'post_title' === $key || '' === (string) $value ) {
			continue;
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
			WP_CLI::error( "unexpected column name: $key" );
		}
		$out[ $key ] = (string) $value;
	}
	return $out;
}

// Rank Math redirects (any status) with one of these exact addresses (paths without slashes) among their sources.
function s_rules_on( array $addresses ) {
	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';
	$where = array();
	foreach ( $addresses as $a ) {
		$where[] = $wpdb->prepare( 'sources LIKE %s', '%' . $wpdb->esc_like( $a ) . '%' );
	}
	$out = array();
	foreach ( $wpdb->get_results( "SELECT id, sources, status FROM $table WHERE " . implode( ' OR ', $where ) ) as $r ) {
		foreach ( (array) maybe_unserialize( $r->sources ) as $s ) {
			$p = trim( preg_replace( '#^https?://[^/]+#', '', strtolower( trim( (string) ( $s['pattern'] ?? '' ) ) ) ), '/' );
			if ( in_array( $p, $addresses, true ) ) {
				$out[] = "#{$r->id} ({$r->status}) $p";
			}
		}
	}
	return $out;
}

// Why a page can't be added, or '' when it can.
function s_blocked( $slug, $title, $row ) {
	global $wpdb;
	if ( ! $row ) {
		return 'no row for it in pages.csv';
	}
	if ( $row['post_title'] !== $title ) {
		return 'pages.csv has it as "' . $row['post_title'] . '"';
	}
	$posts = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_status FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$slug
		)
	);
	if ( $posts ) {
		return 'a college post already has this slug (' . implode( ', ', $posts ) . ')';
	}
	$rules = s_rules_on( array( 'admissions/' . $slug, 'admission/' . $slug ) );
	if ( $rules ) {
		return 'a redirect answers its address: ' . implode( '; ', $rules );
	}
	return '';
}

// The author most published college pages have.
function s_author() {
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT post_author FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_status = 'publish'
		GROUP BY post_author ORDER BY COUNT(*) DESC LIMIT 1"
	);
}

function s_pages( $new_file, $pages_file ) {
	$pages = array();
	foreach ( s_csv( $pages_file ) as $row ) {
		$pages[ $row['slug'] ] = $row;
	}
	return array( s_csv( $new_file ), $pages );
}

function s_plan( $new_file, $pages_file ) {
	if ( ! post_type_exists( 'colleges' ) ) {
		WP_CLI::error( 'the colleges post type is not registered' );
	}
	list( $new, $pages ) = s_pages( $new_file, $pages_file );
	$ok = 0;
	foreach ( $new as $n ) {
		$row = $pages[ $n['slug'] ] ?? null;
		$why = s_blocked( $n['slug'], $n['post_title'], $row );
		if ( $why ) {
			WP_CLI::log( "SKIP {$n['slug']}: $why" );
			continue;
		}
		++$ok;
		$fields = count( s_fields( $row ) );
		WP_CLI::log( "ok   {$n['slug']}: add \"{$n['post_title']}\" with $fields fields (IPEDS {$row['ipeds_unitid']})" );
	}
	WP_CLI::log( "$ok pages would be added, " . ( count( $new ) - $ok ) . ' skipped; author ' . s_author() );
}

function s_apply( $new_file, $pages_file, $log ) {
	if ( ! post_type_exists( 'colleges' ) ) {
		WP_CLI::error( 'the colleges post type is not registered' );
	}
	list( $new, $pages ) = s_pages( $new_file, $pages_file );
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$author  = s_author();
	$added   = 0;
	$skipped = 0;
	foreach ( $new as $n ) {
		$row = $pages[ $n['slug'] ] ?? null;
		$why = s_blocked( $n['slug'], $n['post_title'], $row );
		if ( $why ) {
			WP_CLI::warning( "skipped {$n['slug']}: $why" );
			++$skipped;
			continue;
		}
		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => 'colleges',
					'post_status'    => 'publish',
					'post_title'     => $n['post_title'],
					'post_name'      => $n['slug'],
					'post_content'   => '',
					'post_author'    => $author,
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => s_fields( $row ),
				)
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			WP_CLI::warning( "skipped {$n['slug']}: " . ( is_wp_error( $id ) ? $id->get_error_message() : 'not added' ) );
			++$skipped;
			continue;
		}
		fwrite( $fh, "{$n['slug']}\t$id\n" );
		fflush( $fh );
		$got = get_post_field( 'post_name', $id );
		if ( $got !== $n['slug'] ) {
			wp_trash_post( $id );
			WP_CLI::warning( "skipped {$n['slug']}: WordPress gave post $id the address $got, so it went to the trash" );
			++$skipped;
			continue;
		}
		++$added;
	}
	fclose( $fh );
	WP_CLI::log( "added $added, skipped $skipped; log $log" );
}

function s_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $slug, $id ) = array_pad( explode( "\t", $line ), 2, '' );
		$post = get_post( (int) $id );
		if ( ! $post || 'colleges' !== $post->post_type || 0 !== strpos( $post->post_name, $slug ) ) {
			WP_CLI::warning( "post $id isn't the college page $slug; left as is" );
			continue;
		}
		if ( 'trash' !== $post->post_status ) {
			wp_trash_post( (int) $id );
		}
		++$n;
	}
	WP_CLI::log( "moved $n pages to the trash" );
}

$s_args = isset( $args ) ? $args : array();
switch ( $s_args[0] ?? '' ) {
	case 'plan':
		s_plan( $s_args[1] ?? '', $s_args[2] ?? '' );
		break;
	case 'apply':
		s_apply( $s_args[1] ?? '', $s_args[2] ?? '', $s_args[3] ?? '' );
		break;
	case 'revert':
		s_revert( $s_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan <new.csv> <pages.csv>, apply <new.csv> <pages.csv> <log.tsv>, or revert <log.tsv>' );
}
