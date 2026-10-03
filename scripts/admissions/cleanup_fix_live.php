<?php
/**
 * Admissions cleanup QA (Digant's plan of 2026-10-03, step 1), on the server: the redirect fixes and the renamed
 * colleges' new addresses. scripts/admissions/cleanup_fix_live.sh pipes this file to `wp eval-file -` with one of:
 *
 *   plan    <fixes.csv>                       dry run: what each address would answer, and the rule that changes
 *   apply   <fixes.csv> <log.tsv>             change the redirects, logging each rule as it was first
 *   plan-pages                                dry run: the published pages left under /admissions/
 *   apply-pages   <log.tsv>                   unpublish them (draft), logging each status
 *   plan-renames  <renames.csv> <group>       dry run: each college page's new address and the redirects around it
 *   apply-renames <renames.csv> <group> <log.tsv>
 *   revert  <log.tsv>                         put everything logged back, newest first
 *
 * fixes.csv (data/admissions/cleanup_qa/fixes.csv, from scripts/admissions/cleanup_qa.py report) has one row per
 * address (admissions/<slug> or admission/<slug>): what it answers today (now), what it should answer (code 301 with a
 * target, or 410) and why. Addresses are compared the way Rank Math compares a request: decoded (it saves exact sources
 * decoded) and against every active rule that matches, exact or not. An address no rule answers as listed gets a new
 * rule. A rule with another answer that holds the address as an exact source is changed when every address it covers
 * needs the same new answer; otherwise the addresses that need another answer move out of it into a new rule (a
 * split), or just out when another rule already answers them as listed, and the rule keeps the rest. A rule that
 * redirects to an /admissions/ address no published college page has any more (a dead end) gives the addresses the
 * list leaves out a 410 as well. A looser rule (contains, start, end, regex) with another answer stays, so the rule
 * with the listed answer is made the newest, which Rank Math tries first. Rank Math also remembers which rule
 * answered an address and tries that rule first while it still matches, so apply clears those remembered answers for
 * the listed addresses. A row is skipped, and reported, when a published college page has the address or a 301
 * target isn't one published college page (or the /admissions/ hub). Plan prints a note for every address more than
 * one rule matches.
 *
 * The pages left under /admissions/ are WordPress pages (children of the old "Admissions" page, last saved 2026-07-15)
 * whose addresses the colleges post type answers, so no visitor sees them; but the page sitemap lists them, and
 * WordPress's 404 guess sends /admission/<slug>/ on to them, which a Rank Math 410 rule doesn't stop. Unpublishing
 * them sets post_status alone (no modified date, no hooks); the sitemap cache is cleared after.
 *
 * renames.csv (data/admissions/cleanup_qa/renames.csv) lists college pages whose address carries a former name, with
 * the proposed address; <group> picks the rows: renamed, optional or all. For each, the page moves to the new address
 * (wp_update_post; the post type is hierarchical, so WordPress keeps no old-slug redirect of its own), one rule sends
 * both old forms (admissions/<old>, admission/<old>) there, every active rule that sent an older address to the old
 * one is pointed at the new one, and a rule that sent the new address to this page (an old address the page takes
 * over) stops covering it. Skipped, and reported, unless exactly one published college page has the old address, no
 * post or attachment has the new one, and no active rule sends the new address anywhere else.
 */

use RankMath\Redirections\DB;
use RankMath\Redirections\Redirection;

const CF_SITE = 'https://gpacalculator.net/';

function cf_csv( $file, array $need ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh, 0, ',', '"', '' );
	foreach ( $need as $col ) {
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

function cf_table() {
	global $wpdb;
	return $wpdb->prefix . 'rank_math_redirections';
}

// A rule's source or target as a path without slashes: "admissions/harvard".
function cf_norm( $url ) {
	$p = strtolower( trim( (string) $url ) );
	$p = preg_replace( '#^https?://[^/]+#', '', $p );
	$p = preg_replace( '/[?#].*$/', '', $p );
	return trim( $p, '/' );
}

function cf_sources( $rule ) {
	return array_values( (array) maybe_unserialize( $rule->sources ) );
}

// Active rules with an exact source equal to one of these addresses: [rule id => rule].
function cf_rules_on( array $addresses ) {
	global $wpdb;
	$where = array();
	foreach ( $addresses as $a ) {
		$where[] = $wpdb->prepare( 'sources LIKE %s', '%' . $wpdb->esc_like( $a ) . '%' );
	}
	$out = array();
	foreach ( $wpdb->get_results( 'SELECT * FROM ' . cf_table() . " WHERE status = 'active' AND (" . implode( ' OR ', $where ) . ')' ) as $r ) {
		foreach ( cf_sources( $r ) as $s ) {
			if ( in_array( cf_norm( $s['pattern'] ?? '' ), $addresses, true ) && 'exact' === ( $s['comparison'] ?? '' ) ) {
				$out[ (int) $r->id ] = $r;
			}
		}
	}
	return $out;
}

// Active rules that send visitors to this address (a 301/302/307/308 whose target is it).
function cf_rules_to( $address ) {
	global $wpdb;
	$like = '%' . $wpdb->esc_like( '/' . $address ) . '%';
	$out  = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . cf_table() . " WHERE status = 'active' AND url_to LIKE %s", $like ) ) as $r ) {
		if ( cf_norm( $r->url_to ) === $address && in_array( (int) $r->header_code, array( 301, 302, 307, 308 ), true ) ) {
			$out[ (int) $r->id ] = $r;
		}
	}
	return $out;
}

function cf_describe( $r ) {
	$src = array();
	foreach ( cf_sources( $r ) as $s ) {
		$src[] = ( $s['pattern'] ?? '' ) . ( 'exact' === ( $s['comparison'] ?? '' ) ? '' : ' (' . ( $s['comparison'] ?? '' ) . ')' );
	}
	return "#{$r->id} " . implode( ' + ', $src ) . " -> {$r->header_code}" . ( '' !== (string) $r->url_to ? " {$r->url_to}" : '' );
}

// College posts (any status) or attachments with this slug: [ID => status].
function cf_posts( $slug, $types = array( 'colleges' ) ) {
	global $wpdb;
	$in   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
	$args = array_merge( array( $slug ), $types );
	$out  = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM {$wpdb->posts} WHERE post_name = %s AND post_type IN ($in)", $args ) ) as $p ) {
		$out[ (int) $p->ID ] = $p->post_status;
	}
	return $out;
}

function cf_one_published( $slug ) {
	$found = array_keys( cf_posts( $slug ), 'publish', true );
	return 1 === count( $found ) ? $found[0] : 0;
}

// Why a 301 target won't do, or ''.
function cf_bad_target( $target ) {
	if ( 0 !== strpos( $target, CF_SITE . 'admissions/' ) ) {
		return "not an /admissions/ address: $target";
	}
	$path = cf_norm( $target );
	if ( 'admissions' === $path ) {
		return ''; // the hub, with or without a search
	}
	if ( ! cf_one_published( substr( $path, strlen( 'admissions/' ) ) ) ) {
		return "not one published college page: $target";
	}
	return '';
}

function cf_api_ready() {
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

function cf_log( $fh, array $fields ) {
	fwrite( $fh, implode( "\t", $fields ) . "\n" );
	fflush( $fh );
}

function cf_forget_cache( array $ids ) {
	global $wpdb;
	foreach ( array_unique( $ids ) as $id ) {
		$wpdb->delete( $wpdb->prefix . 'rank_math_redirections_cache', array( 'redirection_id' => (int) $id ) );
	}
}

// A new active rule for these addresses; returns its ID or 0.
function cf_add_rule( array $addresses, $code, $target ) {
	$rule = Redirection::from( array( 'header_code' => (int) $code, 'status' => 'active' ) );
	foreach ( $addresses as $a ) {
		$rule->add_source( $a, 'exact' );
	}
	if ( 410 !== (int) $code ) {
		$rule->add_destination( $target );
	}
	return (int) $rule->save();
}

function cf_set_rule( $fh, $rule, $code, $target ) {
	global $wpdb;
	cf_log( $fh, array( 'set', $rule->id, $rule->header_code, base64_encode( (string) $rule->url_to ) ) );
	$wpdb->update(
		cf_table(),
		array(
			'header_code' => (int) $code,
			'url_to'      => 410 === (int) $code ? '' : $target,
			'updated'     => current_time( 'mysql' ),
		),
		array( 'id' => (int) $rule->id )
	);
}

// Take addresses (exact sources) out of a rule (deleting it when nothing is left), logging the rule as it was.
function cf_drop_sources( $fh, $rule, array $addresses ) {
	global $wpdb;
	$keep = array();
	foreach ( cf_sources( $rule ) as $s ) {
		if ( 'exact' !== ( $s['comparison'] ?? '' ) || ! in_array( cf_norm( $s['pattern'] ?? '' ), $addresses, true ) ) {
			$keep[] = $s;
		}
	}
	cf_log( $fh, array( 'row', $rule->id, base64_encode( wp_json_encode( (array) $rule ) ) ) );
	if ( $keep ) {
		$wpdb->update( cf_table(), array( 'sources' => maybe_serialize( $keep ) ), array( 'id' => (int) $rule->id ) );
	} else {
		$wpdb->delete( cf_table(), array( 'id' => (int) $rule->id ) );
	}
}

// The answer a rule gives: [code, target], a 410's target ''.
function cf_rule_answer( $rule ) {
	return array( (int) $rule->header_code, 410 === (int) $rule->header_code ? '' : (string) $rule->url_to );
}

// Whether two answers are the same: the code, and a redirect's target with its query (?search=Virginia counts).
function cf_same( array $a, array $b ) {
	$key = function ( $u ) {
		$u = strtolower( rawurldecode( preg_replace( '#^https?://[^/]+#', '', (string) $u ) ) );
		return rtrim( str_replace( '/?', '?', $u ), '/' );
	};
	return (int) $a[0] === (int) $b[0] && ( 410 === (int) $a[0] || $key( $a[1] ) === $key( $b[1] ) );
}

// A redirect to an /admissions/<slug> address that no published college page has any more.
function cf_dead_end( $rule ) {
	$path = cf_norm( $rule->url_to );
	return in_array( (int) $rule->header_code, array( 301, 302, 307, 308 ), true )
		&& preg_match( '#^admissions/([a-z0-9-]+)$#', $path, $m ) && ! cf_one_published( $m[1] );
}

// Fixes ------------------------------------------------------------------------------------------------------------

// An address the way Rank Math compares it: it decodes the request (urldecode) and saves exact sources decoded, so
// admission/globe-university%cb%86wausau is admission/globe-universityˆwausau to it.
function cf_key( $url ) {
	return strtolower( urldecode( cf_norm( $url ) ) );
}

// Whether Rank Math's redirector matches a request for this address (a key) to the rule: the plugin's own comparison
// where it has one, else the same rules (exact, case-blind when the source says so; contains, start, end; regex).
function cf_matches( $rule, $key ) {
	$sources = cf_sources( $rule );
	if ( is_callable( array( DB::class, 'compare_sources' ) ) ) {
		return (bool) DB::compare_sources( $sources, $key );
	}
	foreach ( $sources as $s ) {
		$p = (string) ( $s['pattern'] ?? '' );
		$c = (string) ( $s['comparison'] ?? '' );
		if ( 'exact' === $c ) {
			$p = trim( $p, '/' );
			if ( $p === $key || ( 'case' === ( $s['ignore'] ?? '' ) && strtolower( $p ) === $key ) ) {
				return true;
			}
		} elseif ( in_array( $c, array( 'contains', 'start', 'end' ), true ) ) {
			$p  = untrailingslashit( $p );
			$at = '' === $p ? false : strpos( $key, $p );
			if ( false !== $at && ( 'contains' === $c || ( 'start' === $c && 0 === $at ) || ( 'end' === $c && substr( $key, -strlen( $p ) ) === $p ) ) ) {
				return true;
			}
		} elseif ( 'regex' === $c && @preg_match( '@' . stripslashes( $p ) . '@', $key ) ) { // phpcs:ignore
			return true;
		}
	}
	return false;
}

// Whether a rule holds this address (a key) as an exact source.
function cf_holds( $rule, $key ) {
	foreach ( cf_sources( $rule ) as $s ) {
		if ( 'exact' === ( $s['comparison'] ?? '' ) && cf_key( $s['pattern'] ?? '' ) === $key ) {
			return true;
		}
	}
	return false;
}

// Active rules, newest first, the order Rank Math tries them in: [id => rule].
function cf_active_rules() {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_results( 'SELECT * FROM ' . cf_table() . " WHERE status = 'active' ORDER BY updated DESC, id DESC" ) as $r ) {
		$out[ (int) $r->id ] = $r;
	}
	return $out;
}

// Rank Math's remembered answers for these addresses ([key => true]): [cache row id => [key, rule id]]. Rank Math tries
// the remembered rule first, and it keeps answering while it still matches, even after a newer rule is added.
function cf_cached( array $keys ) {
	global $wpdb;
	$out = array();
	foreach ( $wpdb->get_results( "SELECT id, from_url, redirection_id FROM {$wpdb->prefix}rank_math_redirections_cache WHERE object_id = 0" ) as $c ) {
		$k = cf_key( $c->from_url );
		if ( isset( $keys[ $k ] ) ) {
			$out[ (int) $c->id ] = array( $k, (int) $c->redirection_id );
		}
	}
	return $out;
}

// The work for the whole list: [ops, skipped, notes, keys]. An op is a rule to set, split or make the newest (touch),
// or addresses that need a new rule; keys are the listed addresses as Rank Math compares them ([key => true]).
function cf_fix_ops( array $rows ) {
	$want = array();
	$addr = array();
	$skip = array();
	foreach ( $rows as $row ) {
		$a    = cf_norm( $row['address'] );
		$code = (int) $row['code'];
		if ( ! preg_match( '#^admissions?/[a-z0-9%._~-]+$#', $a ) ) {
			$skip[] = "{$row['address']}: not an /admission(s)/<slug> address";
			continue;
		}
		if ( ! in_array( $code, array( 301, 410 ), true ) ) {
			$skip[] = "$a: code {$row['code']}";
			continue;
		}
		if ( 0 === strpos( $a, 'admissions/' ) && cf_one_published( substr( $a, 11 ) ) ) {
			$skip[] = "$a: a published college page has this address";
			continue;
		}
		$bad = 301 === $code ? cf_bad_target( $row['target'] ) : '';
		if ( $bad ) {
			$skip[] = "$a: $bad";
			continue;
		}
		$k          = cf_key( $a );
		$want[ $k ] = array( $code, 301 === $code ? $row['target'] : '' );
		$addr[ $k ] = $a;
	}
	$rules   = cf_active_rules();
	$cached  = array();
	foreach ( cf_cached( $want ) as $c ) {
		$cached[ $c[0] ][] = $c[1];
	}
	$ops     = array();
	$by_rule = array();
	$covered = array(); // key => true: a rule that keeps the address already gives the listed answer
	$notes   = array();
	foreach ( $want as $k => $answer ) {
		$hits = array_filter(
			$rules,
			function ( $r ) use ( $k ) {
				return cf_matches( $r, $k );
			}
		);
		$right = array(); // matching rules that give the listed answer, newest first
		$held  = false;   // an exact source of a rule with another answer, which the op below changes
		$loose = array(); // rules with another answer that match it through a contains, start, end or regex source
		foreach ( $hits as $id => $rule ) {
			if ( cf_same( $answer, cf_rule_answer( $rule ) ) ) {
				$right[] = $id;
				continue;
			}
			if ( cf_holds( $rule, $k ) ) {
				$held                         = true;
				$by_rule[ $id ]['rule']       = $rule;
				$by_rule[ $id ]['want'][ $k ] = $answer;
			}
			$rest = clone $rule;
			$rest->sources = maybe_serialize(
				array_values(
					array_filter(
						cf_sources( $rule ),
						function ( $s ) use ( $k ) {
							return ! ( 'exact' === ( $s['comparison'] ?? '' ) && cf_key( $s['pattern'] ?? '' ) === $k );
						}
					)
				)
			);
			if ( cf_sources( $rest ) && cf_matches( $rest, $k ) ) {
				$loose[] = $id;
			}
		}
		if ( count( $hits ) > 1 || $loose || ! empty( $cached[ $k ] ) ) {
			$seen = array();
			foreach ( $hits as $id => $rule ) {
				$seen[] = "#$id " . cf_answer( cf_rule_answer( $rule ) ) . ( in_array( $id, $loose, true ) ? ' (not exact)' : '' ) . ( in_array( $id, $cached[ $k ] ?? array(), true ) ? ' (remembered)' : '' );
			}
			$notes[] = "{$addr[ $k ]} should get " . cf_answer( $answer ) . '; rules that match it now: ' . ( $seen ? implode( ', ', $seen ) : 'none' );
		}
		if ( $right ) {
			$covered[ $k ] = true;
			// a looser rule with another answer must not be newer than the one that gives the listed answer
			$newest = $rules[ $right[0] ];
			foreach ( $loose as $id ) {
				if ( $rules[ $id ]->updated > $newest->updated || ( $rules[ $id ]->updated === $newest->updated && $id > $right[0] ) ) {
					$ops[ 'touch ' . $right[0] ] = array( 'rule' => $newest );
				}
			}
		} elseif ( ! $held ) {
			// nothing answers it as listed and no exact source to change: a new rule, the newest, so Rank Math tries it first
			$key                                 = $answer[0] . ' ' . $answer[1] . ' ' . preg_replace( '#^admissions?/#', '', $k );
			$ops[ 'add ' . $key ]['addresses'][] = $k;
			$ops[ 'add ' . $key ]['answer']      = $answer;
		}
	}
	foreach ( $by_rule as $id => $g ) {
		$rule  = $g['rule'];
		$now   = cf_rule_answer( $rule );
		$dead  = cf_dead_end( $rule );
		$moves = array(); // answer => addresses that need it
		$drops = array(); // addresses another rule already answers as listed
		$stay  = array(); // sources that keep the rule's answer
		foreach ( cf_sources( $rule ) as $s ) {
			$p     = cf_norm( $s['pattern'] ?? '' );
			$k     = cf_key( $s['pattern'] ?? '' );
			$exact = 'exact' === ( $s['comparison'] ?? '' );
			$ans   = $exact ? ( $g['want'][ $k ] ?? ( $dead ? array( 410, '' ) : null ) ) : null;
			if ( null === $ans || cf_same( $ans, $now ) ) {
				$stay[] = $p . ( $exact ? '' : ' (' . ( $s['comparison'] ?? '' ) . ')' );
				continue;
			}
			if ( isset( $g['want'][ $k ], $covered[ $k ] ) ) {
				$drops[] = $p;
				continue;
			}
			$m                          = $ans[0] . ' ' . $ans[1];
			$moves[ $m ]['answer']      = $ans;
			$moves[ $m ]['addresses'][] = $p;
		}
		if ( ! $moves && ! $drops ) {
			$ops[ 'done ' . $id ] = array( 'rule' => $rule, 'answer' => $now );
		} elseif ( ! $stay && ! $drops && 1 === count( $moves ) ) {
			$ops[ 'set ' . $id ] = array( 'rule' => $rule, 'answer' => current( $moves )['answer'] );
		} else {
			$ops[ 'split ' . $id ] = array( 'rule' => $rule, 'moves' => array_values( $moves ), 'drops' => $drops, 'stay' => $stay );
		}
	}
	return array( $ops, $skip, $notes, $want );
}

function cf_answer( array $answer ) {
	return 410 === $answer[0] ? '410 Gone' : "301 to {$answer[1]}";
}

function cf_plan( $file ) {
	foreach ( cf_api_ready() as $p ) {
		WP_CLI::warning( $p );
	}
	list( $ops, $skip, $notes, $keys ) = cf_fix_ops( cf_csv( $file, array( 'address', 'code', 'target' ) ) );
	$n                                 = array( 'set' => 0, 'split' => 0, 'add' => 0, 'touch' => 0, 'done' => 0 );
	foreach ( $notes as $note ) {
		WP_CLI::log( "note $note" );
	}
	foreach ( $ops as $key => $op ) {
		$kind = strtok( $key, ' ' );
		++$n[ $kind ];
		if ( 'add' === $kind ) {
			WP_CLI::log( 'add  ' . implode( ' + ', $op['addresses'] ) . ' -> ' . cf_answer( $op['answer'] ) );
		} elseif ( 'set' === $kind ) {
			WP_CLI::log( 'set  ' . cf_describe( $op['rule'] ) . '  =>  ' . cf_answer( $op['answer'] ) );
		} elseif ( 'touch' === $kind ) {
			WP_CLI::log( 'touch ' . cf_describe( $op['rule'] ) . '  =>  made the newest, so Rank Math tries it before the looser rule' );
		} elseif ( 'split' === $kind ) {
			$parts = array();
			foreach ( $op['moves'] as $m ) {
				$parts[] = 'new rule ' . implode( ' + ', $m['addresses'] ) . ' -> ' . cf_answer( $m['answer'] );
			}
			if ( $op['drops'] ) {
				$parts[] = 'out (another rule answers as listed): ' . implode( ' + ', $op['drops'] );
			}
			WP_CLI::log( 'split ' . cf_describe( $op['rule'] ) . '  =>  ' . implode( '; ', $parts ) . '; ' . ( $op['stay'] ? 'the rule keeps ' . implode( ' + ', $op['stay'] ) : 'the rule goes (nothing left in it)' ) );
		}
	}
	foreach ( $skip as $s ) {
		WP_CLI::log( "SKIP $s" );
	}
	WP_CLI::log( "{$n['set']} rules to change, {$n['split']} to split, {$n['add']} to add, {$n['touch']} to make the newest, {$n['done']} already right, " . count( $skip ) . ' skipped; ' . count( cf_cached( $keys ) ) . ' remembered answers to clear' );
}

function cf_apply( $file, $log ) {
	global $wpdb;
	$problems = cf_api_ready();
	if ( $problems ) {
		WP_CLI::error( implode( '; ', $problems ) );
	}
	list( $ops, $skip, $notes, $keys ) = cf_fix_ops( cf_csv( $file, array( 'address', 'code', 'target' ) ) );
	$fh                                = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$n       = array( 'set' => 0, 'split' => 0, 'add' => 0, 'touch' => 0, 'done' => 0 );
	$touched = array();
	foreach ( $ops as $key => $op ) {
		$kind = strtok( $key, ' ' );
		if ( 'set' === $kind ) {
			cf_set_rule( $fh, $op['rule'], $op['answer'][0], $op['answer'][1] );
			$touched[] = $op['rule']->id;
		} elseif ( 'touch' === $kind ) {
			cf_log( $fh, array( 'touch', $op['rule']->id, base64_encode( (string) $op['rule']->updated ) ) );
			$wpdb->update( cf_table(), array( 'updated' => current_time( 'mysql' ) ), array( 'id' => (int) $op['rule']->id ) );
			$touched[] = $op['rule']->id;
		} elseif ( 'split' === $kind ) {
			// The new rules first, so an address is never left without one; then out of the old rule
			$out = $op['drops'];
			foreach ( $op['moves'] as $m ) {
				$id = cf_add_rule( $m['addresses'], $m['answer'][0], $m['answer'][1] );
				if ( ! $id ) {
					WP_CLI::warning( 'not added: ' . implode( ' + ', $m['addresses'] ) );
					continue;
				}
				cf_log( $fh, array( 'add', $id ) );
				$touched[] = $id;
				$out       = array_merge( $out, $m['addresses'] );
			}
			if ( ! $out ) {
				continue;
			}
			cf_drop_sources( $fh, $op['rule'], $out );
			$touched[] = $op['rule']->id;
		} elseif ( 'add' === $kind ) {
			$id = cf_add_rule( $op['addresses'], $op['answer'][0], $op['answer'][1] );
			if ( ! $id ) {
				WP_CLI::warning( 'not added: ' . implode( ' + ', $op['addresses'] ) );
				continue;
			}
			cf_log( $fh, array( 'add', $id ) );
			$touched[] = $id;
		}
		++$n[ $kind ];
	}
	fclose( $fh );
	cf_forget_cache( $touched );
	// Rank Math's remembered answers for the listed addresses go too (a cache: Rank Math rebuilds it on the next visit)
	$cleared = 0;
	foreach ( array_keys( cf_cached( $keys ) ) as $cid ) {
		$cleared += (int) $wpdb->delete( $wpdb->prefix . 'rank_math_redirections_cache', array( 'id' => $cid ) );
	}
	foreach ( $skip as $s ) {
		WP_CLI::warning( "skipped $s" );
	}
	WP_CLI::log( "changed {$n['set']} rules, split {$n['split']}, added {$n['add']}, made {$n['touch']} the newest, {$n['done']} already right, " . count( $skip ) . " skipped, $cleared remembered answers cleared; log $log" );
}

// Pages ------------------------------------------------------------------------------------------------------------

// Published pages whose address is under /admissions/ (not the hub itself): [ID => path].
function cf_shadow_pages() {
	$out = array();
	$ids = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	foreach ( $ids as $id ) {
		$path = cf_norm( get_permalink( $id ) );
		if ( 0 === strpos( $path, 'admissions/' ) ) {
			$out[ (int) $id ] = $path;
		}
	}
	return $out;
}

function cf_plan_pages() {
	$pages = cf_shadow_pages();
	foreach ( $pages as $id => $path ) {
		$college = cf_one_published( substr( $path, strlen( 'admissions/' ) ) );
		$rules   = cf_rules_on( array( $path, 'admission/' . substr( $path, strlen( 'admissions/' ) ) ) );
		WP_CLI::log(
			"page $id /$path/ (" . get_post_field( 'post_modified', $id ) . ')'
			. ( $college ? "; college page $college has this address" : '' )
			. ( $rules ? '; ' . implode( '; ', array_map( 'cf_describe', $rules ) ) : '; no rule' )
		);
	}
	WP_CLI::log( count( $pages ) . ' published pages under /admissions/ to unpublish' );
}

function cf_apply_pages( $log ) {
	global $wpdb;
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$n = 0;
	foreach ( cf_shadow_pages() as $id => $path ) {
		cf_log( $fh, array( 'status', $id, 'publish', $path ) );
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'draft' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		++$n;
	}
	fclose( $fh );
	WP_CLI::log( "unpublished $n pages; log $log" );
}

// Renames ----------------------------------------------------------------------------------------------------------

// What one rename would do: [post ID or 0, message, rules on the new address to take over].
function cf_rename_check( array $row ) {
	$old = $row['slug'];
	$new = $row['proposed_slug'];
	if ( ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $new ) || $new === $old ) {
		return array( 0, "proposed address '$new' won't do", array() );
	}
	$id = cf_one_published( $old );
	if ( ! $id ) {
		$moved = cf_one_published( $new );
		if ( $moved && trim( (string) get_post_meta( $moved, 'ipeds_unitid', true ) ) === $row['ipeds_unitid'] ) {
			return array( 0, "already moved to $new (post $moved)", array() );
		}
		return array( 0, 'not exactly one published college page at this address', array() );
	}
	$unitid = trim( (string) get_post_meta( $id, 'ipeds_unitid', true ) );
	if ( '' !== $row['ipeds_unitid'] && $unitid !== $row['ipeds_unitid'] ) {
		return array( 0, "the page's IPEDS ID is '$unitid', not {$row['ipeds_unitid']}", array() );
	}
	$taken = cf_posts( $new, array( 'colleges', 'attachment' ) );
	if ( $taken ) {
		$used = array();
		foreach ( $taken as $pid => $status ) {
			$used[] = "$pid ($status)";
		}
		return array( 0, "$new is used by post " . implode( ', ', $used ), array() );
	}
	$takeover = array();
	foreach ( cf_rules_on( array( 'admissions/' . $new, 'admission/' . $new ) ) as $rid => $r ) {
		if ( 'admissions/' . $old !== cf_norm( $r->url_to ) ) {
			return array( 0, "$new is answered by " . cf_describe( $r ), array() );
		}
		$takeover[ $rid ] = $r;
	}
	return array( $id, '', $takeover );
}

function cf_rename_rows( $file, $group ) {
	if ( ! in_array( $group, array( 'renamed', 'optional', 'all' ), true ) ) {
		WP_CLI::error( "group is renamed, optional or all, not '$group'" );
	}
	$rows = cf_csv( $file, array( 'slug', 'ipeds_unitid', 'group', 'proposed_slug' ) );
	$out = array();
	foreach ( $rows as $r ) {
		if ( 'all' === $group || $r['group'] === $group ) {
			$out[] = $r;
		}
	}
	return $out;
}

function cf_plan_renames( $file, $group ) {
	foreach ( cf_api_ready() as $p ) {
		WP_CLI::warning( $p );
	}
	$ok = 0;
	$sk = array();
	foreach ( cf_rename_rows( $file, $group ) as $row ) {
		list( $id, $msg, $takeover ) = cf_rename_check( $row );
		if ( ! $id ) {
			$sk[] = "SKIP {$row['slug']}: $msg";
			continue;
		}
		++$ok;
		$pointing = cf_rules_to( 'admissions/' . $row['slug'] );
		WP_CLI::log(
			"ok   {$row['slug']} -> {$row['proposed_slug']} (post $id)"
			. ( $pointing ? '; ' . count( $pointing ) . ' rules re-pointed' : '' )
			. ( $takeover ? '; takes over ' . implode( ', ', array_map( 'cf_describe', $takeover ) ) : '' )
		);
	}
	foreach ( $sk as $s ) {
		WP_CLI::log( $s );
	}
	WP_CLI::log( "$ok pages to move, " . count( $sk ) . ' skipped' );
}

function cf_apply_renames( $file, $group, $log ) {
	global $wpdb;
	$problems = cf_api_ready();
	if ( $problems ) {
		WP_CLI::error( implode( '; ', $problems ) );
	}
	$fh = fopen( $log, 'a' );
	if ( ! $fh ) {
		WP_CLI::error( "can't write $log" );
	}
	$n       = 0;
	$touched = array();
	foreach ( cf_rename_rows( $file, $group ) as $row ) {
		list( $id, $msg, $takeover ) = cf_rename_check( $row );
		$old                         = $row['slug'];
		$new                         = $row['proposed_slug'];
		if ( ! $id ) {
			WP_CLI::warning( "skipped $old: $msg" );
			continue;
		}
		$before = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . cf_table() );
		cf_log( $fh, array( 'rename', $id, $old, $new, get_post_field( 'post_modified', $id ), get_post_field( 'post_modified_gmt', $id ) ) );
		$res = wp_update_post( array( 'ID' => $id, 'post_name' => $new ), true );
		if ( is_wp_error( $res ) || get_post_field( 'post_name', $id ) !== $new ) {
			WP_CLI::warning( "$old: the address is now " . get_post_field( 'post_name', $id ) . '; revert this run' );
			continue;
		}
		// Rules Rank Math added on its own for the change (Auto Post Redirect), so a revert removes them too
		foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . cf_table() . ' WHERE id > %d', $before ) ) as $rid ) {
			cf_log( $fh, array( 'add', $rid ) );
			$touched[] = $rid;
		}
		// The page now answers admissions/<new> itself; a rule for admission/<new> is re-pointed below with the rest
		foreach ( $takeover as $r ) {
			if ( in_array( 'admissions/' . $new, array_map( 'cf_norm', wp_list_pluck( cf_sources( $r ), 'pattern' ) ), true ) ) {
				cf_drop_sources( $fh, $r, array( 'admissions/' . $new ) );
				$touched[] = $r->id;
			}
		}
		$target = CF_SITE . 'admissions/' . $new . '/';
		foreach ( cf_rules_to( 'admissions/' . $old ) as $r ) {
			cf_set_rule( $fh, $r, $r->header_code, $target );
			$touched[] = $r->id;
		}
		$need = array();
		foreach ( array( 'admissions/' . $old, 'admission/' . $old ) as $a ) {
			$rules = cf_rules_on( array( $a ) );
			if ( ! $rules ) {
				$need[] = $a;
				continue;
			}
			foreach ( $rules as $r ) {
				if ( cf_norm( $r->url_to ) !== 'admissions/' . $new ) {
					cf_set_rule( $fh, $r, 301, $target );
					$touched[] = $r->id;
				}
			}
		}
		if ( $need ) {
			$rid = cf_add_rule( $need, 301, $target );
			if ( ! $rid ) {
				WP_CLI::warning( "$old: no rule for " . implode( ' + ', $need ) . '; revert this run' );
				continue;
			}
			cf_log( $fh, array( 'add', $rid ) );
			$touched[] = $rid;
		}
		++$n;
	}
	fclose( $fh );
	cf_forget_cache( $touched );
	WP_CLI::log( "moved $n pages; log $log" );
}

// Revert -----------------------------------------------------------------------------------------------------------

function cf_revert( $log ) {
	global $wpdb;
	$lines = file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
	if ( ! $lines ) {
		WP_CLI::error( "nothing in $log" );
	}
	$n       = array( 'rename' => 0, 'set' => 0, 'add' => 0, 'row' => 0, 'status' => 0, 'touch' => 0 );
	$touched = array();
	foreach ( array_reverse( $lines ) as $line ) {
		$f = explode( "\t", $line );
		switch ( $f[0] ) {
			case 'rename':
				wp_update_post( array( 'ID' => (int) $f[1], 'post_name' => $f[2] ) );
				if ( get_post_field( 'post_name', (int) $f[1] ) !== $f[2] ) {
					WP_CLI::warning( "post {$f[1]} is at " . get_post_field( 'post_name', (int) $f[1] ) . ", not {$f[2]}" );
				}
				if ( isset( $f[5] ) ) {
					$wpdb->update( $wpdb->posts, array( 'post_modified' => $f[4], 'post_modified_gmt' => $f[5] ), array( 'ID' => (int) $f[1] ) );
					clean_post_cache( (int) $f[1] );
				}
				break;
			case 'set':
				$wpdb->update( cf_table(), array( 'header_code' => (int) $f[2], 'url_to' => base64_decode( $f[3] ) ), array( 'id' => (int) $f[1] ) );
				break;
			case 'add':
				if ( method_exists( DB::class, 'delete' ) ) {
					DB::delete( array( (int) $f[1] ) );
				} else {
					$wpdb->delete( cf_table(), array( 'id' => (int) $f[1] ) );
				}
				break;
			case 'row':
				$row = json_decode( base64_decode( $f[2] ), true );
				$wpdb->replace( cf_table(), $row );
				break;
			case 'touch':
				$wpdb->update( cf_table(), array( 'updated' => base64_decode( $f[2] ) ), array( 'id' => (int) $f[1] ) );
				break;
			case 'status':
				$wpdb->update( $wpdb->posts, array( 'post_status' => $f[2] ), array( 'ID' => (int) $f[1] ) );
				clean_post_cache( (int) $f[1] );
				break;
			default:
				WP_CLI::warning( "can't read: $line" );
				continue 2;
		}
		++$n[ $f[0] ];
		if ( ! in_array( $f[0], array( 'rename', 'status' ), true ) ) {
			$touched[] = (int) $f[1];
		}
	}
	cf_forget_cache( $touched );
	WP_CLI::log( "put back {$n['rename']} addresses and {$n['set']} rules, deleted {$n['add']} added rules, restored {$n['row']} rules, put back the order of {$n['touch']} rules, republished {$n['status']} pages" );
}

$cf_args = isset( $args ) ? $args : array();
switch ( $cf_args[0] ?? '' ) {
	case 'plan':
		cf_plan( $cf_args[1] ?? '' );
		break;
	case 'apply':
		cf_apply( $cf_args[1] ?? '', $cf_args[2] ?? '' );
		break;
	case 'plan-pages':
		cf_plan_pages();
		break;
	case 'apply-pages':
		cf_apply_pages( $cf_args[1] ?? '' );
		break;
	case 'plan-renames':
		cf_plan_renames( $cf_args[1] ?? '', $cf_args[2] ?? '' );
		break;
	case 'apply-renames':
		cf_apply_renames( $cf_args[1] ?? '', $cf_args[2] ?? '', $cf_args[3] ?? '' );
		break;
	case 'revert':
		cf_revert( $cf_args[1] ?? '' );
		break;
	default:
		WP_CLI::error( 'usage: plan <fixes.csv> | apply <fixes.csv> <log.tsv> | plan-pages | apply-pages <log.tsv> | plan-renames <renames.csv> <group> | apply-renames <renames.csv> <group> <log.tsv> | revert <log.tsv>' );
}
