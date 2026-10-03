<?php
/**
 * Fifth /gpa-scale/ pass (WP-CLI: wp eval-file; GPC_SAVE=1 in the environment to save).
 *
 * 1. Top of page: the quick-facts list (or 4.0's opening list) becomes a quote block (the theme styles it as a
 *    definition callout), followed by a lead-in sentence so the quote and the scale table aren't back to back.
 * 2. External links: none before the first H2; at most one NAEP link per page, the first one after that.
 * 3. FAQ: the "X GPA – Frequently asked questions" section becomes a Rank Math FAQ block (FAQPage schema from
 *    Rank Math), with the H2 kept above it.
 * 4. Template: page-templates/template-content.php ("GPA – Content Page").
 * Gate: rendered plain text equals before, except the replaced facts list and the added quote / lead-in.
 */
$save = '1' === getenv( 'GPC_SAVE' );
$tpl  = 'page-templates/template-content.php';
kses_remove_filters();

function p5_text( $html ) {
	$t = html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' );
	return preg_replace( '#\s+#u', '', $t );
}
function p5_block( $name, $inner, $attrs = array() ) {
	$a = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	return "<!-- wp:$name$a -->\n$inner\n<!-- /wp:$name -->";
}
function p5_fig( $slug ) {
	static $f = null;
	if ( null === $f ) { $f = json_decode( file_get_contents( getenv( 'HOME' ) . '/backups/gpa-figs.json' ), true ); }
	return $f[ $slug ];
}

global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^[0-4]-[0-9]-gpa$' ORDER BY post_name DESC" );
$ok = 0;
foreach ( $rows as $r ) {
	$slug = $r->post_name;
	$gs   = $slug[0] . '.' . $slug[2];
	$old  = $r->post_content;
	$new  = $old;
	list( $letter, $pct ) = p5_fig( $slug );
	$log = array();

	// 1. facts list -> wrapped in a quote (as Digant did on 3.6), then a lead-in so it isn't back to back with the table
	$tbl_at = strpos( $new, '<!-- wp:table {"className":"gpa-scale-table"}' );
	if ( false === $tbl_at ) { echo "$slug: SKIP no scale table\n"; continue; }
	$removed_txt = '';
	$lead        = p5_block( 'paragraph', '<p>Here is where a ' . $gs . ' sits on the standard 4.0 scale, from A+ down to F:</p>' );
	$quote       = '';
	$before_tbl  = rtrim( substr( $new, 0, $tbl_at ) );
	if ( '<!-- /wp:list -->' === substr( $before_tbl, -17 ) ) {
		$list_open = strrpos( $before_tbl, '<!-- wp:list -->' );
		$list      = substr( $before_tbl, $list_open );
		$new       = substr( $new, 0, $list_open ) . p5_block( 'quote', '<blockquote class="wp-block-quote">' . $list . '</blockquote>' ) . "\n\n" . $lead . "\n\n" . substr( $new, $tbl_at );
		$log[]     = 'quote=wrapped';
	} elseif ( '<!-- /wp:quote -->' === substr( $before_tbl, -18 ) ) {
		$new   = substr( $new, 0, $tbl_at ) . $lead . "\n\n" . substr( $new, $tbl_at );
		$log[] = 'quote=already';
	} else {
		$lead  = '';
		$log[] = 'quote=NONE';
	}
	$added_txt = $lead;

	// 2. external links
	$first_h2 = strpos( $new, '<h2' );
	$kept     = false;
	$unwrapped = 0;
	$offset = 0;
	while ( preg_match( '#<a\s+href="(https?://(?![^"]*gpacalculator\.net)[^"]+)"[^>]*>(.*?)</a>#is', $new, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$pos = $m[0][1];
		if ( ! $kept && false !== $first_h2 && $pos > $first_h2 ) {
			$kept   = true;
			$offset = $pos + strlen( $m[0][0] );
			continue;
		}
		$new    = substr( $new, 0, $pos ) . $m[2][0] . substr( $new, $pos + strlen( $m[0][0] ) );
		$offset = $pos + strlen( $m[2][0] );
		$unwrapped++;
	}
	$log[] = 'ext_kept=' . ( $kept ? 1 : 0 ) . " unlinked=$unwrapped";

	// 3. FAQ -> Rank Math FAQ block
	if ( preg_match( '#<!-- wp:heading[^>]*-->\s*<h2[^>]*>[^<]*Frequently asked[^<]*</h2>\s*<!-- /wp:heading -->#i', $new, $h2, PREG_OFFSET_CAPTURE ) ) {
		$faq_start = $h2[0][1] + strlen( $h2[0][0] );
		$region    = substr( $new, $faq_start );
		$blocks    = array_values( array_filter( parse_blocks( $region ), function ( $b ) { return ! empty( $b['blockName'] ); } ) );
		$questions = array();
		$lead_blocks = array();
		foreach ( $blocks as $b ) {
			if ( 'core/spacer' === $b['blockName'] ) { continue; }
			if ( 'core/heading' === $b['blockName'] && preg_match( '#<h3#i', $b['innerHTML'] ) ) {
				$questions[] = array( 'id' => 'faq-' . substr( md5( $slug . wp_strip_all_tags( $b['innerHTML'] ) ), 0, 10 ), 'title' => trim( wp_strip_all_tags( $b['innerHTML'] ) ), 'content' => '', 'visible' => true );
				continue;
			}
			$html = trim( serialize_block( $b ) );
			$html = trim( preg_replace( '#<!--.*?-->#s', '', $html ) );
			if ( 'core/paragraph' === $b['blockName'] ) { $html = preg_replace( '#^<p>(.*)</p>$#s', '$1', $html ); }
			if ( ! $questions ) { $lead_blocks[] = serialize_block( $b ); continue; }
			$q = &$questions[ count( $questions ) - 1 ];
			$q['content'] .= ( '' === $q['content'] ? '' : "\n\n" ) . $html;
			unset( $q );
		}
		$questions = array_values( array_filter( $questions, function ( $q ) { return '' !== trim( $q['content'] ); } ) );
		$inner = '<div class="wp-block-rank-math-faq-block">';
		foreach ( $questions as $q ) {
			$inner .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . esc_html( $q['title'] ) . '</h3><div class="rank-math-answer">' . $q['content'] . '</div></div>';
		}
		$inner .= '</div>';
		$faq = p5_block( 'rank-math/faq-block', $inner, array( 'questions' => $questions ) );
		$new = substr( $new, 0, $faq_start ) . "\n\n" . ( $lead_blocks ? implode( "\n\n", $lead_blocks ) . "\n\n" : '' ) . $faq . "\n";
		$log[] = 'faq=' . count( $questions );
	} else {
		$log[] = 'faq=NONE';
	}

	// gate on rendered text
	$a = p5_text( apply_filters( 'the_content', str_replace( $removed_txt, '', $old ) ) );
	$b = p5_text( apply_filters( 'the_content', '' !== $added_txt ? str_replace( $added_txt, '', $new ) : $new ) );
	if ( $a !== $b ) {
		$p = 0;
		while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) { $p++; }
		echo "$slug: SKIP text differs at $p\n  old: " . substr( $a, max( 0, $p - 60 ), 160 ) . "\n  new: " . substr( $b, max( 0, $p - 60 ), 160 ) . "\n";
		continue;
	}
	if ( getenv( 'GPC_SHOW' ) === $slug ) {
		$out = apply_filters( 'the_content', $new );
		$i   = strpos( $out, 'rank-math' );
		echo substr( $out, max( 0, $i - 200 ), 1500 ), "\n";
		$blk = array_values( array_filter( parse_blocks( $new ), function ( $x ) { return 'rank-math/faq-block' === $x['blockName']; } ) );
		echo 'attrs questions: ', count( $blk[0]['attrs']['questions'] ), "\n";
	}
	$cur_tpl = get_page_template_slug( $r->ID );
	printf( "%-8s OK  %s  template:%s\n", $slug, implode( ' ', $log ), $cur_tpl === $tpl ? 'ok' : "$cur_tpl -> content" );
	$ok++;
	if ( $save ) {
		$res = wp_update_post( array( 'ID' => (int) $r->ID, 'post_content' => wp_slash( $new ), 'page_template' => $tpl ), true );
		echo is_wp_error( $res ) ? '  SAVE FAILED: ' . $res->get_error_message() . "\n" : "  saved\n";
	}
}
echo "$ok/" . count( $rows ) . ( $save ? ' saved' : ' pass (dry run)' ) . "\n";
