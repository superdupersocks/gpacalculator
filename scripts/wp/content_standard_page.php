<?php
/**
 * Content standard for one calculator page (WP-CLI: wp eval-file; GPC_ID=<page id>, GPC_SAVE=1 to save).
 * Pilot: the College GPA calculator (page 22), then the same pass page by page.
 *
 * 1. The pill paragraphs above section headings (p.rx-eyebrow) are removed: section numbers replace them.
 * 2. The FAQ built from core "details" blocks (group .rx-faqwrap) becomes one Rank Math FAQ block with the same
 *    questions and answers, so FAQPage schema comes from Rank Math (the theme's own FAQ JSON-LD only reads rx-faq).
 * 3. Every H2 gets an id, and a Rank Math TOC block ("On this page", H2s only) goes right under the calculator.
 * Gate: the page's rendered text is unchanged apart from the removed pills and the added TOC; dry run by default.
 * Saved with wp_update_post, so a revision keeps the old version.
 */
$id   = (int) ( getenv( 'GPC_ID' ) ?: 22 );
$save = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();

$post = get_post( $id );
if ( ! $post ) { echo "no post $id\n"; return; }
$old    = $post->post_content;
$blocks = parse_blocks( $old );

function cs_text( $html ) {
	$t = html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' );
	return preg_replace( '#\s+#u', '', $t );
}
function cs_block( $name, $attrs, $html ) {
	return array( 'blockName' => $name, 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => $html, 'innerContent' => array( $html ) );
}
function cs_classes( $b ) {
	return isset( $b['attrs']['className'] ) ? ' ' . $b['attrs']['className'] . ' ' : '';
}

$removed = array(); // text of removed pills
$faq_n   = 0;
$used    = array();

/* Walk a block list: drop pills, convert the details FAQ, give H2s ids. */
function cs_walk( $list, &$removed, &$faq_n, &$used ) {
	$out = array();
	foreach ( $list as $b ) {
		$cls = cs_classes( $b );
		if ( 'core/paragraph' === $b['blockName'] && false !== strpos( $cls, ' rx-eyebrow ' ) ) {
			$removed[] = cs_text( $b['innerHTML'] );
			continue;
		}
		if ( 'core/group' === $b['blockName'] && false !== strpos( $cls, ' rx-faqwrap ' ) ) {
			$qs = array();
			foreach ( $b['innerBlocks'] as $d ) {
				if ( 'core/details' !== $d['blockName'] ) { continue; }
				$html = render_block( $d );
				if ( ! preg_match( '#<summary[^>]*>(.*?)</summary>(.*)</details>#s', $html, $m ) ) { continue; }
				$qs[] = array(
					'id'      => 'faq-question-' . ( $faq_n + count( $qs ) + 1 ),
					'title'   => trim( wp_strip_all_tags( $m[1] ) ),
					'content' => trim( preg_replace( '#\s+#', ' ', $m[2] ) ),
					'visible' => true,
				);
			}
			$faq_n += count( $qs );
			$html = '<div class="wp-block-rank-math-faq-block">';
			foreach ( $qs as $q ) {
				$html .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . esc_html( $q['title'] ) . '</h3><div class="rank-math-answer">' . $q['content'] . '</div></div>';
			}
			$html .= '</div>';
			$out[] = cs_block( 'rank-math/faq-block', array( 'questions' => $qs ), $html );
			continue;
		}
		if ( 'core/heading' === $b['blockName'] && ( ! isset( $b['attrs']['level'] ) || 2 === (int) $b['attrs']['level'] ) ) {
			if ( preg_match( '#<h2([^>]*)>(.*?)</h2>#s', $b['innerHTML'], $m ) ) {
				if ( preg_match( '#\sid="([^"]+)"#', $m[1], $im ) ) {
					$used[ $im[1] ] = 1;
				} else {
					$slug = sanitize_title( wp_strip_all_tags( $m[2] ) );
					$sid  = $slug; $n = 2;
					while ( isset( $used[ $sid ] ) ) { $sid = $slug . '-' . $n++; }
					$used[ $sid ] = 1;
					$new_tag = '<h2 id="' . esc_attr( $sid ) . '"' . $m[1] . '>';
					$b['innerHTML'] = str_replace( '<h2' . $m[1] . '>', $new_tag, $b['innerHTML'] );
					foreach ( $b['innerContent'] as &$c ) {
						if ( is_string( $c ) ) { $c = str_replace( '<h2' . $m[1] . '>', $new_tag, $c ); }
					}
					unset( $c );
				}
			}
		}
		if ( $b['innerBlocks'] ) {
			$kids = cs_walk( $b['innerBlocks'], $removed, $faq_n, $used );
			// innerContent holds one null per inner block: rebuild it for the new child count
			$strings = array();
			$slots   = 0;
			foreach ( $b['innerContent'] as $c ) {
				if ( null === $c ) { $slots++; } else { $strings[] = $c; }
			}
			$first = array_shift( $strings );
			$last  = array_pop( $strings );
			$b['innerContent'] = array_merge( array( $first ), array_fill( 0, count( $kids ), null ), array( null === $last ? '' : $last ) );
			$b['innerBlocks']  = $kids;
		}
		$out[] = $b;
	}
	return $out;
}

$blocks = cs_walk( $blocks, $removed, $faq_n, $used );

/* TOC: H2s in order (outside the calculator), inserted after the calculator block */
$heads = array();
$calc  = null;
foreach ( $blocks as $i => $b ) {
	// Calculators often sit in a shortcode block that only expands in the_content, so expand shortcodes here too.
	$html = do_shortcode( render_block( $b ) );
	if ( null === $calc && preg_match( '#id=["\']root["\']|gpacalc-mount|frm_forms#', $html ) ) {
		$calc = $i;
		continue;
	}
	if ( preg_match_all( '#<h2[^>]*\sid="([^"]+)"[^>]*>(.*?)</h2>#s', $html, $mm, PREG_SET_ORDER ) ) {
		foreach ( $mm as $m ) {
			$heads[] = array( 'key' => 'h-' . $m[1], 'content' => trim( wp_strip_all_tags( $m[2] ) ), 'level' => 2, 'link' => '#' . $m[1], 'disable' => false );
		}
	}
}
$has_toc = false !== strpos( $old, 'wp:rank-math/toc-block' );
if ( ! $has_toc && null !== $calc && count( $heads ) >= 4 ) {
	$items = '';
	foreach ( $heads as $h ) { $items .= '<li><a href="' . esc_attr( $h['link'] ) . '">' . esc_html( $h['content'] ) . '</a></li>'; }
	$toc = cs_block(
		'rank-math/toc-block',
		array( 'title' => 'On this page', 'headings' => $heads, 'listStyle' => 'ul', 'titleWrapper' => 'p', 'excludeHeadings' => array( 'h3', 'h4', 'h5', 'h6' ) ),
		'<div class="wp-block-rank-math-toc-block" id="rank-math-toc"><p>On this page</p><nav><ul>' . $items . '</ul></nav></div>'
	);
	array_splice( $blocks, $calc + 1, 0, array( $toc ) );
}

$new = serialize_blocks( $blocks );

/* Gate on rendered text */
$a = cs_text( do_blocks( $old ) );
foreach ( $removed as $r ) { $p = strpos( $a, $r ); if ( false !== $p ) { $a = substr_replace( $a, '', $p, strlen( $r ) ); } }
$b = cs_text( do_blocks( $new ) );
$toc_txt = $has_toc || null === $calc || count( $heads ) < 4 ? '' : cs_text( 'On this page' . implode( '', wp_list_pluck( $heads, 'content' ) ) );
if ( '' !== $toc_txt ) { $p = strpos( $b, $toc_txt ); if ( false !== $p ) { $b = substr_replace( $b, '', $p, strlen( $toc_txt ) ); } }
if ( $has_toc ) {
	$toc_msg = 'TOC: already there';
} elseif ( null === $calc ) {
	$toc_msg = 'TOC: SKIPPED, no calculator block found';
} elseif ( count( $heads ) < 4 ) {
	$toc_msg = 'TOC: skipped, fewer than 4 headings';
} else {
	$toc_msg = sprintf( 'TOC: inserted after block %d with %d entries', $calc, count( $heads ) );
}
printf( "page %d %s: pills removed %d, FAQ questions %d. %s\n", $id, $post->post_name, count( $removed ), $faq_n, $toc_msg );
if ( $a !== $b ) {
	$p = 0;
	while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) { $p++; }
	echo "SKIP: text differs at $p\n  old: " . substr( $a, max( 0, $p - 60 ), 160 ) . "\n  new: " . substr( $b, max( 0, $p - 60 ), 160 ) . "\n";
	return;
}
echo "text check: same\n";
if ( $save ) {
	$res = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
	echo is_wp_error( $res ) ? 'SAVE FAILED: ' . $res->get_error_message() . "\n" : "saved (revision kept)\n";
} else {
	echo "dry run: set GPC_SAVE=1 to save\n";
}
