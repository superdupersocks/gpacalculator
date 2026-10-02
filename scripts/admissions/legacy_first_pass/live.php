<?php
/**
 * Old /admission/<slug> addresses with no post under that slug: add one Rank Math rule each (301 to the college's
 * current page, or 410 Gone), from data/admissions/redirects/legacy_actions.csv. Run through
 * scripts/admissions/legacy_redirects_live.sh, which passes the arguments:
 *
 *   plan   <actions.csv>              dry run: what each row would add, and why a row would be skipped
 *   apply  <actions.csv> <log.tsv>    add the rules; the log holds each new rule's ID
 *   revert <log.tsv>                  delete the logged rules
 *
 * A row is skipped when its old address already has a post or an active rule, or its 301 target isn't a published
 * college page.
 */

use RankMath\Redirections\Redirection;

function lr_table() {
	global $wpdb;
	return $wpdb->prefix . 'rank_math_redirections';
}

function lr_rows( $file ) {
	$fh = fopen( $file, 'r' );
	if ( ! $fh ) {
		WP_CLI::error( "can't read $file" );
	}
	$head = fgetcsv( $fh );
	$rows = array();
	while ( ( $r = fgetcsv( $fh ) ) !== false ) {
		$rows[] = array_combine( $head, $r );
	}
	fclose( $fh );
	return $rows;
}

function lr_check( $row ) {
	global $wpdb;
	$lr_table = lr_table();
	$source = 'admission/' . $row['old_slug'];
	if ( get_page_by_path( $row['old_slug'], OBJECT, 'colleges' ) ) {
		return array( false, 'a college post already has this slug' );
	}
	$like = '%' . $wpdb->esc_like( '"' . $source . '"' ) . '%';
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $lr_table WHERE status = 'active' AND sources LIKE %s", $like ) ) ) {
		return array( false, 'an active rule already covers it' );
	}
	if ( '301' === $row['code'] ) {
		if ( ! preg_match( '#^https://gpacalculator\.net/admissions/([a-z0-9-]+)/$#', $row['target'], $m ) ) {
			return array( false, 'target is not a college page address' );
		}
		$t = get_page_by_path( $m[1], OBJECT, 'colleges' );
		if ( ! $t || 'publish' !== $t->post_status ) {
			return array( false, "target {$m[1]} is not a published college page" );
		}
	} elseif ( '410' !== $row['code'] ) {
		return array( false, "unknown code {$row['code']}" );
	}
	return array( true, '' );
}

$lr_args = $args;
switch ( $lr_args[0] ?? '' ) {
	case 'plan':
	case 'apply':
		$apply = 'apply' === $lr_args[0];
		$log   = $apply ? fopen( $lr_args[2], 'w' ) : null;
		if ( $apply ) {
			fwrite( $log, "rule_id\told_slug\tcode\ttarget\n" );
		}
		$done    = 0;
		$skipped = 0;
		foreach ( lr_rows( $lr_args[1] ) as $row ) {
			list( $good, $why ) = lr_check( $row );
			$what = 'admission/' . $row['old_slug'] . ' -> ' . ( '301' === $row['code'] ? '301 ' . $row['target'] : '410' );
			if ( ! $good ) {
				WP_CLI::log( "SKIP $what: $why" );
				++$skipped;
				continue;
			}
			if ( ! $apply ) {
				WP_CLI::log( "ok   $what" );
				++$done;
				continue;
			}
			$rule = Redirection::from( array( 'header_code' => (int) $row['code'], 'status' => 'active' ) );
			$rule->add_source( 'admission/' . $row['old_slug'], 'exact' );
			if ( '301' === $row['code'] ) {
				$rule->add_destination( $row['target'] );
			}
			$rid = (int) $rule->save();
			if ( ! $rid ) {
				WP_CLI::warning( "SKIP $what: Rank Math didn't save the rule" );
				++$skipped;
				continue;
			}
			fwrite( $log, "$rid\t{$row['old_slug']}\t{$row['code']}\t{$row['target']}\n" );
			WP_CLI::log( "ok   $what (rule $rid)" );
			++$done;
		}
		if ( $log ) {
			fclose( $log );
		}
		WP_CLI::log( ( $apply ? 'added' : 'ready' ) . " $done, skipped $skipped" );
		break;
	case 'revert':
		$fh = fopen( $lr_args[1], 'r' );
		fgetcsv( $fh, 0, "\t" );
		$n = 0;
		while ( ( $r = fgetcsv( $fh, 0, "\t" ) ) !== false ) {
			$n += (int) $GLOBALS['wpdb']->delete( lr_table(), array( 'id' => (int) $r[0] ) );
		}
		fclose( $fh );
		WP_CLI::log( "deleted $n rules" );
		break;
	default:
		WP_CLI::error( 'usage: plan <actions.csv> | apply <actions.csv> <log.tsv> | revert <log.tsv>' );
}
