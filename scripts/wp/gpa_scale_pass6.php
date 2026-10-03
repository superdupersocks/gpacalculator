<?php
/**
 * Sixth /gpa-scale/ pass (WP-CLI: wp eval-file; GPC_SAVE=1 to save). Reads ~/backups/gpa-faqs.json.
 * 1. FAQ: replace the Rank Math FAQ block's questions with the new set (content/gpa-scale-faqs.json).
 * 2. Internal links in the body: each internal URL linked at most once. Keep the first one, except the Raise
 *    GPA calculator, which keeps its link inside "How to raise" (Digant's rule for 3.6, applied to every page).
 *    Repeats are unlinked (text kept). Theme nav/footer are not in post content, so they are untouched.
 * Prints a per-page list of unlinked anchors. Gate: rendered text unchanged outside the FAQ block.
 */
$save = '1' === getenv( 'GPC_SAVE' );
$faqs = json_decode( file_get_contents( getenv( 'HOME' ) . '/backups/gpa-faqs.json' ), true );
kses_remove_filters();
add_filter( 'theme_page_templates', function ( $t ) { return $t; } );

function p6_text( $html ) {
	return preg_replace( '#\s+#u', '', html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' ) );
}
function p6_norm( $url ) {
	$u = preg_replace( '#^https?://(www\.)?gpacalculator\.net#i', '', $url );
	$u = preg_replace( '#[?\#].*$#', '', $u );
	$u = trim( $u, '/' );
	return '' === $u ? '/' : '/' . $u . '/';
}
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^[0-4]-[0-9]-gpa$' ORDER BY post_name DESC" );
$report = array();
$ok = 0;
foreach ( $rows as $r ) {
	$slug = $r->post_name;
	$old  = $r->post_content;
	$new  = $old;

	// 1. FAQ block
	if ( ! preg_match( '#<!-- wp:rank-math/faq-block .*?<!-- /wp:rank-math/faq-block -->#s', $new, $fb, PREG_OFFSET_CAPTURE ) ) { echo "$slug: SKIP no FAQ block\n"; continue; }
	$questions = array();
	foreach ( $faqs[ $slug ] as $q ) {
		$questions[] = array( 'id' => 'faq-' . substr( md5( $slug . $q['title'] ), 0, 10 ), 'title' => $q['title'], 'content' => $q['content'], 'visible' => true );
	}
	$inner = '<div class="wp-block-rank-math-faq-block">';
	foreach ( $questions as $q ) {
		$inner .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . esc_html( $q['title'] ) . '</h3><div class="rank-math-answer">' . esc_html( $q['content'] ) . '</div></div>';
	}
	$inner .= '</div>';
	$faq_new = '<!-- wp:rank-math/faq-block ' . serialize_block_attributes( array( 'questions' => $questions ) ) . " -->\n" . $inner . "\n<!-- /wp:rank-math/faq-block -->";
	$new = substr( $new, 0, $fb[0][1] ) . $faq_new . substr( $new, $fb[0][1] + strlen( $fb[0][0] ) );

	// 2. internal link dedupe
	$raise_h2 = preg_match( '#<h2[^>]*>How to raise[^<]*</h2>#i', $new, $rh, PREG_OFFSET_CAPTURE ) ? $rh[0][1] : -1;
	$next_h2  = $raise_h2 >= 0 && preg_match( '#<h2#i', $new, $nh, PREG_OFFSET_CAPTURE, $raise_h2 + 4 ) ? $nh[0][1] : strlen( $new );
	preg_match_all( '#<a\s+href="((?:https?://(?:www\.)?gpacalculator\.net)?/[^"]*)"[^>]*>(.*?)</a>#is', $new, $links, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
	$by_url = array();
	foreach ( $links as $l ) {
		$by_url[ p6_norm( $l[1][0] ) ][] = array( 'pos' => $l[0][1], 'len' => strlen( $l[0][0] ), 'text' => $l[2][0] );
	}
	$unlink = array();
	foreach ( $by_url as $url => $list ) {
		if ( count( $list ) < 2 ) { continue; }
		$keep = 0;
		if ( '/how-to-raise-gpa/' === $url ) {
			foreach ( $list as $i => $l ) {
				if ( $l['pos'] > $raise_h2 && $l['pos'] < $next_h2 ) { $keep = $i; break; }
			}
		}
		foreach ( $list as $i => $l ) {
			if ( $i !== $keep ) { $unlink[] = $l + array( 'url' => $url ); }
		}
	}
	usort( $unlink, function ( $a, $b ) { return $b['pos'] - $a['pos']; } );
	$changes = array();
	foreach ( $unlink as $l ) {
		$new = substr( $new, 0, $l['pos'] ) . $l['text'] . substr( $new, $l['pos'] + $l['len'] );
		$changes[] = $l['url'] . ' "' . wp_strip_all_tags( $l['text'] ) . '"';
	}
	$report[ $slug ] = array_reverse( $changes );

	// gate: text outside the FAQ block unchanged
	$a = p6_text( apply_filters( 'the_content', str_replace( $fb[0][0], '', $old ) ) );
	$b = p6_text( apply_filters( 'the_content', str_replace( $faq_new, '', $new ) ) );
	if ( $a !== $b ) { echo "$slug: SKIP text changed outside the FAQ\n"; continue; }
	$rend = apply_filters( 'the_content', $new );
	$nq = substr_count( $rend, 'rank-math-question' );
	printf( "%-8s OK  faq=%d (rendered %d)  unlinked=%d\n", $slug, count( $questions ), $nq, count( $changes ) );
	$ok++;
	if ( $save ) {
		$res = wp_update_post( array( 'ID' => (int) $r->ID, 'post_content' => wp_slash( $new ) ), true );
		echo is_wp_error( $res ) ? '  SAVE FAILED: ' . $res->get_error_message() . "\n" : "  saved\n";
	}
}
file_put_contents( getenv( 'HOME' ) . '/backups/gpa-link-changes.json', wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
echo "$ok/" . count( $rows ) . ( $save ? ' saved' : ' pass (dry run)' ) . "\n";
