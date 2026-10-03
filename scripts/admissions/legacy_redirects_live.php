<?php
/**
 * Admissions Phase 2, the old /admission/ addresses, on the server. scripts/admissions/legacy_redirects_live.sh pipes
 * this file to `wp eval-file -` with one of:
 *
 *   plan   <map.csv>            dry run: the rule each row of legacy_redirect_map.csv would add, and what already
 *                               answers its addresses
 *   apply  <map.csv> <log.tsv>  add one Rank Math rule per row for admission/<old> and admissions/<old>: 410 Gone, or
 *                               a 301 to the college's page; log each rule's ID
 *   revert <log.tsv>            delete the logged rules, newest first
 *
 * A row is skipped, and reported, when a published college post uses its old slug again, its 301 target isn't one
 * published college post, or an active rule already answers either address. An existing rule that forwards the old
 * /admission/ address to the /admissions/ one stays, and the new rule then covers only the /admissions/ address. A row
 * whose rule is already there from an earlier apply (same code and target) is skipped as done.
 */

use RankMath\Redirections\DB;
use RankMath\Redirections\Redirection;

const LR_SITE = 'https://gpacalculator.net/admissions/';

function lr_rows( $file ) {
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

// The college posts with this slug that aren't in the trash, as [ID => status].
function lr_posts( $slug ) {
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

function lr_norm( $pattern ) {
	$p = strtolower( trim( (string) $pattern ) );
	$p = preg_replace( '#^https?://[^/]+#', '', $p );
	return trim( $p, '/' );
}

// Redirects (any status) with a source that is exactly one of these addresses (paths without slashes).
function lr_rules_on( array $addresses ) {
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
			$p = lr_norm( $s['pattern'] ?? '' );
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

function lr_describe( array $r ) {
	return "#{$r['id']} {$r['status']} {$r['address']} ({$r['comparison']}) -> {$r['code']} {$r['to']}";
}

// What one row would do: [state, sources, message]; state is 'ok', 'done' (an earlier apply added it) or 'skip'.
function lr_check( array $row ) {
	$old       = $row['old_slug'];
	$addresses = array( 'admission/' . $old, 'admissions/' . $old );
	if ( '' === $old || preg_match( '#[/?\#%\s]#', $old ) ) {
		return array( 'skip', array(), 'not a plain slug' );
	}
	foreach ( lr_posts( $old ) as $id => $status ) {
		if ( 'publish' === $status ) {
			return array( 'skip', array(), "published college post $id uses this slug again" );
		}
	}
	$code = (int) $row['action'];
	if ( 301 === $code ) {
		if ( strpos( $row['target'], LR_SITE ) !== 0 ) {
			return array( 'skip', array(), 'target is not a college page: ' . $row['target'] );
		}
		$target = lr_posts( trim( substr( $row['target'], strlen( LR_SITE ) ), '/' ) );
		if ( count( $target ) !== 1 || 'publish' !== current( $target ) ) {
			return array( 'skip', array(), 'target is not one published college post: ' . $row['target'] );
		}
	} elseif ( 410 !== $code ) {
		return array( 'skip', array(), 'unknown action ' . $row['action'] );
	}
	$notes = array();
	$same  = array();
	foreach ( lr_rules_on( $addresses ) as $r ) {
		if ( 'active' !== $r['status'] ) {
			$notes[] = 'inactive ' . lr_describe( $r );
			continue;
		}
		if ( $r['code'] === $code && ( 410 === $code || lr_norm( $r['to'] ) === lr_norm( $row['target'] ) ) ) {
			$same[ $r['address'] ] = $r['id'];
			continue;
		}
		$forward = lr_norm( $r['to'] ) === lr_norm( LR_SITE . $old );
		if ( 'admission/' . $old === $r['address'] && $forward && in_array( $r['code'], array( 301, 302, 307, 308 ), true ) ) {
			$same[ $r['address'] ] = $r['id']; // forwards to the /admissions/ address, which the new rule answers
			$notes[]               = 'kept ' . lr_describe( $r );
			continue;
		}
		return array( 'skip', array(), 'already redirected: ' . lr_describe( $r ) );
	}
	if ( count( $same ) === count( $addresses ) ) {
		$ids = array_unique( $same );
		return array( 'done', array(), ( count( $ids ) > 1 ? 'rules #' : 'rule #' ) . implode( ', #', $ids ) . ( count( $ids ) > 1 ? ' already answer' : ' already answers' ) . ' both addresses' );
	}
	return array( 'ok', array_values( array_diff( $addresses, array_keys( $same ) ) ), implode( '; ', $notes ) );
}

function lr_api_ready() {
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

function lr_plan( $file ) {
	$problems = lr_api_ready();
	foreach ( $problems as $p ) {
		WP_CLI::warning( $p );
	}
	$count = array(
		'ok'   => 0,
		'done' => 0,
		'skip' => 0,
	);
	foreach ( lr_rows( $file ) as $row ) {
		list( $state, $sources, $msg ) = lr_check( $row );
		++$count[ $state ];
		$to = '410' === $row['action'] ? '410 Gone' : '301 to ' . $row['target'];
		if ( 'ok' === $state ) {
			WP_CLI::log( 'ok   ' . implode( ' + ', $sources ) . " answer $to" . ( $msg ? " [$msg]" : '' ) );
		} else {
			WP_CLI::log( ( 'done' === $state ? 'DONE ' : 'SKIP ' ) . "{$row['old_slug']}: $msg" );
		}
	}
	WP_CLI::log( "{$count['ok']} rows ready, {$count['done']} already done, {$count['skip']} skipped" . ( $problems ? '; apply would refuse: ' . implode( '; ', $problems ) : '' ) );
}

function lr_apply( $file, $log ) {
	$problems = lr_api_ready();
	if ( $problems ) {
		WP_CLI::error( implode( '; ', $problems ) );
	}
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$count = array(
		'ok'   => 0,
		'done' => 0,
		'skip' => 0,
	);
	foreach ( lr_rows( $file ) as $row ) {
		list( $state, $sources, $msg ) = lr_check( $row );
		if ( 'ok' !== $state ) {
			if ( 'skip' === $state ) {
				WP_CLI::warning( "skipped {$row['old_slug']}: $msg" );
			}
			++$count[ $state ];
			continue;
		}
		$code = (int) $row['action'];
		// Rank Math 1.0.279 has no set_header_code(); from() keeps the code in the rule's data and DB::add() stores it.
		$rule = Redirection::from( array( 'header_code' => $code, 'status' => 'active' ) );
		foreach ( $sources as $s ) {
			$rule->add_source( $s, 'exact' );
		}
		if ( 301 === $code ) {
			$rule->add_destination( $row['target'] );
		}
		$rid = (int) $rule->save();
		if ( ! $rid ) {
			WP_CLI::warning( "skipped {$row['old_slug']}: Rank Math didn't save the redirect" );
			++$count['skip'];
			continue;
		}
		fwrite( $fh, implode( "\t", array( $row['old_slug'], $rid, $code, $row['target'], implode( ' ', $sources ) ) ) . "\n" );
		fflush( $fh );
		++$count['ok'];
	}
	fclose( $fh );
	WP_CLI::log( "added {$count['ok']} rules, {$count['done']} already there, {$count['skip']} skipped; log $log" );
}

function lr_revert( $log ) {
	global $wpdb;
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n = 0;
	foreach ( array_reverse( $lines ) as $line ) {
		list( $old, $rid ) = array_pad( explode( "\t", $line ), 2, '' );
		$rid               = (int) $rid;
		if ( ! $rid ) {
			WP_CLI::warning( "can't read: $line" );
			continue;
		}
		if ( method_exists( DB::class, 'delete' ) ) {
			DB::delete( array( $rid ) );
		} else {
			$wpdb->delete( $wpdb->prefix . 'rank_math_redirections', array( 'id' => $rid ) );
			$wpdb->delete( $wpdb->prefix . 'rank_math_redirections_cache', array( 'redirection_id' => $rid ) );
		}
		++$n;
	}
	WP_CLI::log( "deleted $n redirects" );
}

$lr_args = isset( $args ) ? $args : array();
switch ( $lr_args[0] ?? '' ) {
	case 'plan':
		lr_plan( $lr_args[1] ?? '' );
		break;
	case 'apply':
		lr_apply( $lr_args[1] ?? '', $lr_args[2] ?? '' );
		break;
	case 'revert':
		lr_revert( $lr_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan <map.csv>, apply <map.csv> <log.tsv>, or revert <log.tsv>' );
}
