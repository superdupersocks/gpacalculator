<?php
/**
 * Third /gpa-scale/ pass (WP-CLI), built by scripts/build_content_pass.py:
 *  - rewrite the sentences that pointed at the removed college search tool ($sentences: exact old => new)
 *  - cite the source for the "national average 3.0" figure on its first mention in the body (not the intro)
 *  - weighted-GPA note right after the scale table on 3.5-3.9 and 4.0 ($weighted)
 *  - FAQ: 4.0's Rank Math FAQ block (renders nothing) becomes heading + paragraph blocks; 3.9 gets a
 *    "3.9 GPA – Frequently asked questions" H2; Custom HTML blocks holding pasted <div> FAQs or nested lists
 *    become heading / paragraph / list blocks; an empty list block is dropped.
 * Gate: the page's plain text after the edit must equal its plain text before, with only the intended text
 * changes applied. GPC_SAVE = true saves passing pages (revision kept).
 */

// Filled in by the build script: GPC_SAVE, $sentences, $weighted, CITE.

function cp_block( $name, $html, $attrs = array() ) {
	$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '';
	return "<!-- wp:$name$json -->\n$html\n<!-- /wp:$name -->";
}
function cp_h( $level, $text, $center = false ) {
	$attrs = array();
	if ( $center ) { $attrs['textAlign'] = 'center'; }
	if ( 2 !== $level ) { $attrs['level'] = $level; }
	$class = 'wp-block-heading' . ( $center ? ' has-text-align-center' : '' );
	return cp_block( 'heading', "<h$level class=\"$class\">" . trim( $text ) . "</h$level>", $attrs );
}
function cp_p( $html ) { return cp_block( 'paragraph', '<p>' . trim( $html ) . '</p>' ); }
function cp_list( $items, $ordered = false ) {
	$tag = $ordered ? 'ol' : 'ul';
	$li  = '';
	foreach ( $items as $i ) { $li .= cp_block( 'list-item', '<li>' . trim( $i ) . '</li>' ); }
	return cp_block( 'list', "<$tag class=\"wp-block-list\">$li</$tag>", $ordered ? array( 'ordered' => true ) : array() );
}
function cp_text( $html ) {
	$t = preg_replace( '#<!--.*?-->#s', ' ', $html );
	$t = html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '#\s+#u', ' ', $t ) );
}

/** Custom HTML block -> core blocks, or null to leave it. */
function cp_unwrap_html( $inner ) {
	if ( '' === cp_text( $inner ) ) {
		return ''; // empty leftover (e.g. <ul><li></li></ul>)
	}
	if ( preg_match( '#<div\b#i', $inner ) && preg_match( '#<h3\b#i', $inner ) ) {
		preg_match_all( '#<(h3|p)\b[^>]*>(.*?)</\1>#is', $inner, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $el ) {
			$out[] = 'h3' === strtolower( $el[1] ) ? cp_h( 3, $el[2] ) : cp_p( $el[2] );
		}
		return implode( "\n\n", $out );
	}
	if ( preg_match( '#^\s*<ul>\s*<li style="list-style-type:\s*none;?">\s*<ul>(.*)</ul>\s*</li>\s*</ul>\s*$#is', $inner, $m ) ) {
		preg_match_all( '#<li\b[^>]*>(.*?)</li>#is', $m[1], $li );
		return cp_list( $li[1] );
	}
	return null;
}

/** 4.0's Rank Math FAQ block -> h3 + paragraph blocks. */
function cp_rank_math_faq( $content ) {
	return preg_replace_callback(
		'#<!-- wp:rank-math/faq-block[^>]*-->\s*(.*?)\s*<!-- /wp:rank-math/faq-block -->#s',
		function ( $b ) {
			preg_match_all( '#<h3 class="rank-math-question">(.*?)</h3><div class="rank-math-answer">(.*?)</div>#s', $b[1], $items, PREG_SET_ORDER );
			$out = array();
			foreach ( $items as $it ) {
				$q = trim( preg_replace( '#<br\s*/?>#i', '', $it[1] ) );
				$out[] = cp_h( 3, $q );
				$out[] = cp_p( $it[2] );
			}
			return $out ? implode( "\n\n", $out ) : $b[0];
		},
		$content
	);
}

if ( GPC_SAVE ) {
	kses_remove_filters();
	add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
}
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^[0-4]-[0-9]-gpa$' ORDER BY post_name DESC" );
$ok = 0;
foreach ( $rows as $r ) {
	$slug = $r->post_name;
	$gpa  = $slug[0] . '.' . $slug[2];
	$old  = $r->post_content;
	$new  = $old;
	$expect_old = array(); // plain-text substitutions the gate allows: old text => new text
	$log = array();

	// 1. sentences
	foreach ( isset( $sentences[ $slug ] ) ? $sentences[ $slug ] : array() as $from => $to ) {
		$n = substr_count( $new, $from );
		if ( 1 !== $n ) { echo "$slug: SKIP sentence found $n times: " . substr( $from, 0, 60 ) . "\n"; continue 2; }
		$new = str_replace( $from, $to, $new );
		$expect_old[ cp_text( $from ) ] = cp_text( $to );
	}
	$log[] = 'sentences=' . count( isset( $sentences[ $slug ] ) ? $sentences[ $slug ] : array() );

	// 2. citation on the first body mention (after the intro paragraph block)
	$intro_end = strpos( $new, '<!-- /wp:paragraph -->' );
	if ( preg_match( '#national average(?: GPA| for a GPA)? (?:is|of) (?:around |about |roughly )?3\.0#i', $new, $m, PREG_OFFSET_CAPTURE, $intro_end ) ) {
		$at  = $m[0][1] + strlen( $m[0][0] );
		$new = substr( $new, 0, $at ) . CITE . substr( $new, $at );
		$expect_old[ $m[0][0] ] = $m[0][0] . cp_text( CITE );
		$log[] = 'cite=yes';
	} else {
		$log[] = 'cite=NO MATCH';
	}

	// 3. weighted note after the scale table
	if ( isset( $weighted[ $slug ] ) ) {
		$end = strpos( $new, '<!-- /wp:table -->' );
		if ( false === $end || false === strpos( $new, 'gpa-scale-table' ) ) { echo "$slug: SKIP no scale table\n"; continue; }
		$end += strlen( '<!-- /wp:table -->' );
		$new  = substr( $new, 0, $end ) . "\n\n" . cp_p( $weighted[ $slug ] ) . substr( $new, $end );
		$log[] = 'weighted=yes';
	}

	// 4. FAQ fixes
	if ( '4-0-gpa' === $slug ) {
		$new = cp_rank_math_faq( $new );
		$new = str_replace( '<h2 class="wp-block-heading has-text-align-center">Frequently asked questions</h2>', '<h2 class="wp-block-heading has-text-align-center">4.0 GPA – Frequently asked questions</h2>', $new, $c );
		$expect_old['Frequently asked questions'] = '4.0 GPA – Frequently asked questions';
		$log[] = 'faq4=' . ( false === strpos( $new, 'rank-math/faq-block' ) ? 'converted' : 'LEFT' ) . "/h2=$c";
	}
	if ( '3-9-gpa' === $slug ) {
		$q = '<!-- wp:heading {"level":3} -->' . "\n" . '<h3 class="wp-block-heading">What does a 3.9 GPA signify?</h3>';
		if ( 1 !== substr_count( $new, $q ) ) { echo "$slug: SKIP 3.9 FAQ anchor not found\n"; continue; }
		$new = str_replace( $q, cp_h( 2, '3.9 GPA – Frequently asked questions', true ) . "\n\n" . $q, $new );
		$log[] = 'faq39=h2';
	}
	$unwrapped = 0;
	$new = preg_replace_callback(
		'#<!-- wp:html -->\n(.*?)\n<!-- /wp:html -->#s',
		function ( $b ) use ( &$unwrapped ) {
			$res = cp_unwrap_html( $b[1] );
			if ( null === $res ) { return $b[0]; }
			$unwrapped++;
			return $res;
		},
		$new
	);
	$log[] = "unwrapped=$unwrapped";
	$new = preg_replace( "#\n{3,}#", "\n\n", $new );

	// gate: plain text equal after the intended substitutions; inserted weighted note / H2 removed first
	$a = cp_text( $old );
	foreach ( $expect_old as $f => $t ) {
		$pos = strpos( $a, $f );
		if ( false !== $pos ) { $a = substr( $a, 0, $pos ) . $t . substr( $a, $pos + strlen( $f ) ); }
	}
	$b = cp_text( $new );
	if ( isset( $weighted[ $slug ] ) ) { $b = str_replace( cp_text( $weighted[ $slug ] ) . ' ', '', $b ); }
	if ( '3-9-gpa' === $slug ) { $b = str_replace( '3.9 GPA – Frequently asked questions ', '', $b ); }
	// compare without whitespace: block markup changes spacing between elements, never the words
	$a = preg_replace( '#\s+#u', '', $a );
	$b = preg_replace( '#\s+#u', '', $b );
	if ( $a !== $b ) {
		$p = 0;
		while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) { $p++; }
		echo "$slug: SKIP text differs at $p\n  old: " . substr( $a, max( 0, $p - 60 ), 160 ) . "\n  new: " . substr( $b, max( 0, $p - 60 ), 160 ) . "\n";
		continue;
	}
	$blocks = array_filter( parse_blocks( $new ), function ( $x ) { return ! empty( $x['blockName'] ); } );
	$html_left = count( array_filter( $blocks, function ( $x ) { return 'core/html' === $x['blockName']; } ) );
	printf( "%-8s OK  %s  html_blocks_left=%d\n", $slug, implode( ' ', $log ), $html_left );
	$ok++;
	if ( GPC_SAVE && $new !== $old ) {
		$res = wp_update_post( array( 'ID' => (int) $r->ID, 'post_content' => wp_slash( $new ) ), true );
		echo is_wp_error( $res ) ? '  SAVE FAILED: ' . $res->get_error_message() . "\n" : "  saved\n";
	}
}
echo "$ok/" . count( $rows ) . ' pages pass' . ( GPC_SAVE ? ' and were saved' : ' (dry run, nothing saved)' ) . "\n";
