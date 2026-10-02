<?php
/**
 * Admissions Phase 2, checkpoints C and D, on the server. scripts/admissions/phase2_cd_live.sh pipes this file to
 * `wp eval-file -` with one of:
 *
 *   plan   <C|D> <actions.csv>            dry run: each row's post, target and any redirect already on its addresses
 *   apply  <C|D> <actions.csv> <log.tsv>  unpublish each post (kept as a draft) and add one Rank Math redirect for its
 *                                         /admissions/ and old /admission/ addresses: 410 Gone, or a 301 to the target
 *   revert <log.tsv>                      publish the logged posts again and delete the logged redirects
 *
 * A row is skipped, and reported, when its post isn't the one published college post with that slug, its target
 * isn't published, or a redirect already answers its /admissions/ address. An existing rule on the old /admission/
 * address that forwards to the post's /admissions/ address stays, and the new rule then covers only the new address.
 */

use RankMath\Redirections\DB;
use RankMath\Redirections\Redirection;

const CD_SITE = 'https://gpacalculator.net/admissions/';

function cd_rows( $file, $checkpoint ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	$rows = array();
	while ( ( $r = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {
		$r = array_combine( $head, $r );
		if ( $r['checkpoint'] === $checkpoint ) {
			$rows[] = $r;
		}
	}
	fclose( $fh );
	if ( ! $rows ) {
		WP_CLI::error( "no rows for checkpoint $checkpoint in $file" );
	}
	return $rows;
}

// The college posts with this slug that aren't in the trash, as [ID => status].
function cd_posts( $slug ) {
	global $wpdb;
	$found = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_status FROM {$wpdb->posts} WHERE post_type = 'colleges' AND post_name = %s AND post_status <> 'trash'",
			$slug
		)
	);
	$out = array();
	foreach ( $found as $p ) {
		$out[ (int) $p->ID ] = $p->post_status;
	}
	return $out;
}

function cd_norm( $pattern ) {
	$p = strtolower( trim( (string) $pattern ) );
	$p = preg_replace( '#^https?://[^/]+#', '', $p );
	return trim( $p, '/' );
}

// Redirects (any status) with a source that is exactly one of these addresses (paths without slashes).
function cd_rules_on( array $addresses ) {
	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';
	$where = array();
	foreach ( $addresses as $a ) {
		$where[] = $wpdb->prepare( 'sources LIKE %s', '%' . $wpdb->esc_like( $a ) . '%' );
	}
	$rules = $wpdb->get_results( "SELECT id, sources, url_to, header_code, status FROM $table WHERE " . implode( ' OR ', $where ) );
	$out   = array();
	foreach ( $rules as $r ) {
		foreach ( (array) maybe_unserialize( $r->sources ) as $s ) {
			$p = cd_norm( $s['pattern'] ?? '' );
			if ( in_array( $p, $addresses, true ) ) {
				$out[] = array(
					'id'         => (int) $r->id,
					'address'    => $p,
					'comparison' => $s['comparison'] ?? '',
					'to'         => $r->url_to,
					'code'       => (int) $r->header_code,
					'status'     => $r->status,
				);
			}
		}
	}
	return $out;
}

function cd_describe( array $rules ) {
	return implode(
		'; ',
		array_map(
			function ( $r ) {
				return "#{$r['id']} {$r['status']} {$r['address']} ({$r['comparison']}) -> {$r['code']} {$r['to']}";
			},
			$rules
		)
	);
}

// What one row would do: [ok, post ID, sources to add, message].
function cd_check( array $row ) {
	$slug  = $row['slug'];
	$new   = 'admissions/' . $slug;
	$old   = 'admission/' . $slug;
	$posts = cd_posts( $slug );
	if ( count( $posts ) !== 1 ) {
		return array( false, 0, array(), count( $posts ) . ' college posts with this slug' );
	}
	$id = (int) key( $posts );
	if ( 'publish' !== current( $posts ) ) {
		return array( false, $id, array(), 'post is ' . current( $posts ) );
	}
	if ( '301' === $row['action'] ) {
		if ( strpos( $row['target'], CD_SITE ) !== 0 ) {
			return array( false, $id, array(), 'target is not a college page: ' . $row['target'] );
		}
		$target = cd_posts( trim( substr( $row['target'], strlen( CD_SITE ) ), '/' ) );
		if ( count( $target ) !== 1 || 'publish' !== current( $target ) ) {
			return array( false, $id, array(), 'target is not one published post: ' . $row['target'] );
		}
	} elseif ( 'retire' !== $row['action'] ) {
		return array( false, $id, array(), 'unknown action ' . $row['action'] );
	}
	$sources = array( $new, $old );
	$notes   = array();
	foreach ( cd_rules_on( array( $new, $old ) ) as $r ) {
		if ( 'active' !== $r['status'] ) {
			$notes[] = 'inactive ' . cd_describe( array( $r ) );
			continue;
		}
		$forward = cd_norm( $r['to'] ) === cd_norm( CD_SITE . $slug );
		if ( $r['address'] === $old && $forward && in_array( $r['code'], array( 301, 302, 307, 308 ), true ) ) {
			$sources = array( $new ); // the old address already forwards to the new one, which the new rule answers
			$notes[] = 'kept ' . cd_describe( array( $r ) );
			continue;
		}
		return array( false, $id, array(), 'already redirected: ' . cd_describe( array( $r ) ) );
	}
	return array( true, $id, $sources, implode( '; ', $notes ) );
}

function cd_api_ready() {
	$missing = array();
	if ( ! class_exists( '\RankMath\Helper' ) || ! \RankMath\Helper::is_module_active( 'redirections' ) ) {
		$missing[] = "Rank Math's Redirections module is off";
	}
	foreach ( array( 'from', 'add_source', 'add_destination', 'save' ) as $m ) {
		if ( ! method_exists( Redirection::class, $m ) ) {
			$missing[] = "RankMath\\Redirections\\Redirection::$m is missing";
		}
	}
	return $missing;
}

function cd_plan( $file, $checkpoint ) {
	global $wpdb;
	$problems = cd_api_ready();
	foreach ( $problems as $p ) {
		WP_CLI::warning( $p );
	}
	$table = $wpdb->prefix . 'rank_math_redirections';
	foreach ( $wpdb->get_results( "SELECT id, sources, url_to, header_code FROM $table WHERE status = 'active' AND sources NOT LIKE '%\"exact\"%'" ) as $r ) {
		WP_CLI::log( "active non-exact rule #{$r->id}: {$r->sources} -> {$r->header_code} {$r->url_to}" );
	}
	$sample = $wpdb->get_var( "SELECT sources FROM $table WHERE status = 'active' AND sources LIKE '%admission/%' ORDER BY id DESC LIMIT 1" );
	WP_CLI::log( 'how an existing /admission/ rule stores its source: ' . $sample );
	$ok = 0;
	foreach ( cd_rows( $file, $checkpoint ) as $row ) {
		list( $good, $id, $sources, $msg ) = cd_check( $row );
		if ( ! $good ) {
			WP_CLI::log( "SKIP {$row['slug']}: $msg" );
			continue;
		}
		++$ok;
		$to = 'retire' === $row['action'] ? '410 Gone' : '301 to ' . $row['target'];
		WP_CLI::log( "ok   {$row['slug']} (post $id): unpublish; " . implode( ' + ', $sources ) . " answer $to" . ( $msg ? " [$msg]" : '' ) );
	}
	WP_CLI::log( "$ok rows ready" . ( $problems ? ', but apply would refuse: ' . implode( '; ', $problems ) : '' ) );
}

function cd_apply( $file, $checkpoint, $log ) {
	$problems = cd_api_ready();
	if ( $problems ) {
		WP_CLI::error( implode( '; ', $problems ) );
	}
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$done    = 0;
	$skipped = 0;
	foreach ( cd_rows( $file, $checkpoint ) as $row ) {
		list( $good, $id, $sources, $msg ) = cd_check( $row );
		if ( ! $good ) {
			WP_CLI::warning( "skipped {$row['slug']}: $msg" );
			++$skipped;
			continue;
		}
		// The rule first: if it can't be saved the post stays published, and if the post can't be unpublished the
		// rule goes again, so no row is left half done.
		$addresses = array( 'admissions/' . $row['slug'], 'admission/' . $row['slug'] );
		$before    = wp_list_pluck( cd_rules_on( $addresses ), 'id' );
		$code      = 'retire' === $row['action'] ? 410 : 301;
		// Rank Math 1.0.279 has no set_header_code(); from() keeps the code in the rule's data and DB::add() stores it.
		$rule      = Redirection::from( array( 'header_code' => $code, 'status' => 'active' ) );
		foreach ( $sources as $s ) {
			$rule->add_source( $s, 'exact' );
		}
		if ( 301 === $code ) {
			$rule->add_destination( $row['target'] );
		}
		$rid = (int) $rule->save();
		if ( ! $rid ) {
			WP_CLI::warning( "skipped {$row['slug']}: Rank Math didn't save the redirect" );
			++$skipped;
			continue;
		}
		$res = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			cd_delete_rule( $rid );
			WP_CLI::warning( "skipped {$row['slug']}: " . $res->get_error_message() );
			++$skipped;
			continue;
		}
		fwrite( $fh, implode( "\t", array( $checkpoint, $row['slug'], $id, 'publish', $row['action'], $row['target'], $rid, implode( ' ', $sources ) ) ) . "\n" );
		$extra = array_diff( wp_list_pluck( cd_rules_on( $addresses ), 'id' ), $before, array( $rid ) );
		if ( $extra ) {
			WP_CLI::warning( "{$row['slug']}: other rules appeared for its addresses: #" . implode( ', #', $extra ) );
		}
		++$done;
	}
	fclose( $fh );
	WP_CLI::log( "applied $done, skipped $skipped; log $log" );
}

function cd_delete_rule( $rid ) {
	global $wpdb;
	if ( method_exists( DB::class, 'delete' ) ) {
		DB::delete( array( $rid ) );
		return;
	}
	$wpdb->delete( $wpdb->prefix . 'rank_math_redirections', array( 'id' => $rid ) );
	$wpdb->delete( $wpdb->prefix . 'rank_math_redirections_cache', array( 'redirection_id' => $rid ) );
}

function cd_revert( $log ) {
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$posts = 0;
	$rules = 0;
	foreach ( $lines as $line ) {
		list( , $slug, $id, $status, , , $rid ) = array_pad( explode( "\t", $line ), 8, '' );
		$res = wp_update_post(
			array(
				'ID'          => (int) $id,
				'post_status' => $status,
			),
			true
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::warning( "$slug: " . $res->get_error_message() );
		} else {
			++$posts;
		}
		if ( (int) $rid ) {
			cd_delete_rule( (int) $rid );
			++$rules;
		}
	}
	WP_CLI::log( "published $posts posts again and deleted $rules redirects" );
}

$cd_args = isset( $args ) ? $args : array();
switch ( $cd_args[0] ?? '' ) {
	case 'plan':
		cd_plan( $cd_args[2] ?? '', $cd_args[1] ?? '' );
		break;
	case 'apply':
		cd_apply( $cd_args[2] ?? '', $cd_args[1] ?? '', $cd_args[3] ?? '' );
		break;
	case 'revert':
		cd_revert( $cd_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan|apply <C|D> <actions.csv> [<log.tsv>], or revert <log.tsv>' );
}
