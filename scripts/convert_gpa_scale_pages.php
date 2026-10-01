<?php
/**
 * Convert the /gpa-scale/<x-x>-gpa/ pages from the Classic block to core blocks (WP-CLI).
 *
 *   wp eval-file - < convert.php            (built by scripts/build_gpa_scale_convert.py; dry run)
 *
 * Per page: drop the retired "admission chances" section and the [CollegeDB] shortcode, apply body fixes,
 * convert the HTML that renders today (wpautop + shortcode_unautop) into paragraph / heading / list blocks,
 * anything else into Custom HTML blocks byte for byte, and prepend the intro paragraph (the hero shows it).
 * Gate: render the cleaned classic content and the new blocks (minus the intro) through the_content and
 * compare after normalising whitespace and the classes core blocks add. Any difference skips the page.
 * GPC_SAVE = true saves passing pages with wp_update_post (a revision keeps the old content).
 */

// Filled in by the build script: GPC_SAVE, $intros (slug => text), $fixes (slug => [from => to]).

// The retired closing section: the "Your Admission Chances" / "List of Colleges" / "Colleges likely to accept"
// heading(s) above [CollegeDB] and the lead-in promising the admissions calculator. Same logic as the theme filter
// that hid it on the live pages until this conversion removed it (2026-10-01).
$hide = function ( $content ) {
	global $shortcode_tags;
	$at = strpos( $content, '[CollegeDB' );
	if ( false === $at || ! isset( $shortcode_tags['CollegeDB'] ) || '__return_empty_string' !== $shortcode_tags['CollegeDB'] ) {
		return $content;
	}
	// Keep the block/paragraph wrapper the shortcode sits in, so the markup stays balanced.
	$before = substr( $content, 0, $at );
	if ( preg_match( '#(?:<!--\s*wp:(?:paragraph|shortcode)\b[^>]*-->\s*)?(?:<p>\s*)?$#i', $before, $wrap ) ) {
		$at -= strlen( $wrap[0] );
		$before = substr( $content, 0, $at );
	}
	if ( ! preg_match_all( '#(?:<!--\s*wp:heading\b[^>]*-->\s*)?<h([1-6])\b[^>]*>(.*?)</h\1>#is', $before, $heads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}
	$start = $at;
	foreach ( array_reverse( $heads ) as $h ) {
		if ( ! preg_match( '#admission chances|list of colleges|colleges likely to accept#i', wp_strip_all_tags( $h[2][0] ) ) ) {
			break;
		}
		$start = $h[0][1];
	}
	// Some pages put an FAQ between that lead-in section and the last heading: drop any earlier
	// "Admission Chances" section that promises the admissions calculator, up to the next heading.
	$kept = preg_replace(
		'#(?:<!--\s*wp:heading\b[^>]*-->\s*)?<h([1-6])\b[^>]*>(?:(?!</h\1>).)*?admission chances(?:(?!</h\1>).)*</h\1>(?:(?!<h[1-6]\b|<!--\s*wp:heading\b).)*?admissions calculator(?:(?!<h[1-6]\b|<!--\s*wp:heading\b).)*#is',
		'',
		substr( $content, 0, $start )
	);
	return ( null === $kept ? substr( $content, 0, $start ) : $kept ) . substr( $content, $at );
};

function gpc_block( $name, $html, $attrs = array() ) {
	$json = $attrs ? ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ) : '';
	return "<!-- wp:$name$json -->\n$html\n<!-- /wp:$name -->";
}

/** Split HTML into top-level chunks: block-level elements (sliced as written) and runs of anything else. */
function gpc_chunks( $html ) {
	$blockish = 'p|h[1-6]|ul|ol|div|table|figure|blockquote|pre|hr|dl|section';
	$out = array();
	$i = 0;
	$n = strlen( $html );
	$loose = '';
	while ( $i < $n ) {
		if ( preg_match( '#\G<(' . $blockish . ')\b[^>]*>#i', $html, $m, 0, $i ) ) {
			$tag = strtolower( $m[1] );
			if ( '' !== trim( $loose ) ) {
				$out[] = array( 'loose', $loose );
			}
			$loose = '';
			if ( 'hr' === $tag ) {
				$out[] = array( 'hr', $m[0] );
				$i += strlen( $m[0] );
				continue;
			}
			$depth = 0;
			$j = $i;
			while ( preg_match( '#<(/?)' . $tag . '\b[^>]*>#i', $html, $t, PREG_OFFSET_CAPTURE, $j ) ) {
				$depth += '' === $t[1][0] ? 1 : -1;
				$j = $t[0][1] + strlen( $t[0][0] );
				if ( 0 === $depth ) {
					break;
				}
			}
			if ( 0 !== $depth ) {
				return null; // unbalanced markup: leave this page alone
			}
			$out[] = array( $tag, substr( $html, $i, $j - $i ) );
			$i = $j;
			continue;
		}
		$loose .= $html[ $i ];
		$i++;
	}
	if ( '' !== trim( $loose ) ) {
		$out[] = array( 'loose', $loose );
	}
	return $out;
}

function gpc_list_items( $inner ) {
	// Top-level <li> only; nested lists or unknown attributes make the caller fall back to HTML.
	if ( preg_match( '#<(ul|ol)\b#i', $inner ) ) {
		return null;
	}
	if ( ! preg_match_all( '#<li\b([^>]*)>(.*?)</li>#is', $inner, $m, PREG_SET_ORDER ) ) {
		return null;
	}
	$rest = trim( preg_replace( '#<li\b[^>]*>.*?</li>#is', '', $inner ) );
	if ( '' !== $rest ) {
		return null;
	}
	$items = array();
	foreach ( $m as $li ) {
		$attrs = trim( preg_replace( '#\s*(?:style="font-weight:\s*400;?"|aria-level="\d+")#i', '', $li[1] ) );
		if ( '' !== $attrs ) {
			return null;
		}
		$items[] = gpc_block( 'list-item', '<li>' . $li[2] . '</li>' );
	}
	return implode( '', $items );
}

function gpc_convert( $raw ) {
	$html   = shortcode_unautop( wpautop( $raw ) );
	$chunks = gpc_chunks( $html );
	if ( null === $chunks ) {
		return null;
	}
	$blocks = array();
	$stats  = array();
	foreach ( $chunks as list( $tag, $chunk ) ) {
		$block = null;
		if ( 'p' === $tag && preg_match( '#^<p>(.*)</p>$#is', $chunk, $m ) && false === strpos( $m[1], '[' ) ) {
			$block = gpc_block( 'paragraph', $chunk );
			$type  = 'paragraph';
		} elseif ( preg_match( '#^h([1-6])$#', $tag, $lv ) && preg_match( '#^<h\d(\s+style="text-align:\s*center;?")?\s*>(.*)</h\d>$#is', $chunk, $m ) ) {
			$level = (int) $lv[1];
			$attrs = 2 === $level ? array() : array( 'level' => $level );
			$class = 'wp-block-heading';
			if ( ! empty( $m[1] ) ) {
				$attrs = array( 'textAlign' => 'center' ) + $attrs;
				$class .= ' has-text-align-center';
			}
			$block = gpc_block( 'heading', "<h$level class=\"$class\">{$m[2]}</h$level>", $attrs );
			$type  = 'heading';
		} elseif ( ( 'ul' === $tag || 'ol' === $tag ) && preg_match( '#^<' . $tag . '>(.*)</' . $tag . '>$#is', $chunk, $m ) ) {
			$items = gpc_list_items( $m[1] );
			if ( null !== $items ) {
				$block = gpc_block( 'list', "<$tag class=\"wp-block-list\">$items</$tag>", 'ol' === $tag ? array( 'ordered' => true ) : array() );
				$type  = 'list';
			}
		}
		if ( null === $block ) {
			$block = gpc_block( 'html', trim( $chunk ) );
			$type  = 'html';
		}
		$blocks[] = $block;
		$stats[ $type ] = ( isset( $stats[ $type ] ) ? $stats[ $type ] : 0 ) + 1;
	}
	return array( implode( "\n\n", $blocks ), $stats );
}

function gpc_norm( $html ) {
	$html = str_replace( 'class="wp-block-heading has-text-align-center"', 'style="text-align: center;"', $html );
	$html = preg_replace( '#\s+class="wp-block-(?:heading|list|paragraph)"#', '', $html );
	$html = preg_replace( '#(<li\b[^>]*?)\s*(?:style="font-weight:\s*400;?"|aria-level="\d+")#i', '$1', $html );
	$html = preg_replace( '#(<li\b[^>]*?)\s*(?:style="font-weight:\s*400;?"|aria-level="\d+")#i', '$1', $html );
	$html = preg_replace( '#<br\s*/?>#i', '<br>', $html );
	// [caption] renders as <p><figure>…</figure></p> in classic content (browsers close the <p> before
	// <figure>), and WordPress suffixes a repeated caption id when the same content renders twice.
	$html = preg_replace( array( '#<p>\s*(<figure\b)#i', '#(</figure>)\s*</p>#i' ), '$1', $html );
	$html = preg_replace( '#((?:id="attachment_|caption-attachment-)\d+)-\d+"#', '$1"', $html );
	$html = preg_replace( '#\s+#u', ' ', $html );
	return trim( preg_replace( '#>\s+<#', '><', $html ) );
}

function gpc_render( $content ) {
	return apply_filters( 'the_content', $content );
}

if ( GPC_SAVE ) {
	kses_remove_filters(); // content is ours; keep block comments and inline styles exactly
	// The pages use the 'gpa-content-page' template, which WP-CLI's theme template list doesn't include;
	// without this wp_update_post() saves the content but returns 'Invalid page template' before revisions.
	add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
}
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_content LIKE '%[CollegeDB %' ORDER BY post_name DESC" );
$ok = 0;
foreach ( $rows as $r ) {
	$raw = $r->post_content;
	// 1. drop the retired section (same logic as the theme filter) and the shortcode itself
	$clean = $hide( $raw );
	$clean = preg_replace( '#\s*(?:<!--\s*wp:paragraph\s*-->\s*)?(?:<p>\s*)?\[CollegeDB\b[^\]]*\](?:\s*</p>)?(?:\s*<!--\s*/wp:paragraph\s*-->)?\s*$#', '', $clean, 1, $gone );
	if ( 1 !== $gone ) {
		echo "{$r->post_name}: SKIP shortcode not at the end\n";
		continue;
	}
	// 2. body fixes
	foreach ( isset( $fixes[ $r->post_name ] ) ? $fixes[ $r->post_name ] : array() as $from => $to ) {
		if ( 1 !== substr_count( $clean, $from ) ) {
			echo "{$r->post_name}: SKIP fix text not found once: $from\n";
			continue 2;
		}
		$clean = str_replace( $from, $to, $clean );
	}
	// 3. convert (pages already in blocks keep their blocks)
	if ( has_blocks( $clean ) ) {
		$body  = trim( $clean );
		$stats = array( 'already blocks' => 1 );
	} else {
		$conv = gpc_convert( $clean );
		if ( null === $conv ) {
			echo "{$r->post_name}: SKIP unbalanced markup\n";
			continue;
		}
		list( $body, $stats ) = $conv;
	}
	// 4. gate: the new blocks must render like the cleaned classic content
	$a = gpc_norm( gpc_render( $clean ) );
	$b = gpc_norm( gpc_render( $body ) );
	if ( $a !== $b ) {
		$p = 0;
		while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) {
			$p++;
		}
		echo "{$r->post_name}: SKIP render differs at $p\n  old: " . substr( $a, max( 0, $p - 80 ), 200 ) . "\n  new: " . substr( $b, max( 0, $p - 80 ), 200 ) . "\n";
		continue;
	}
	$intro = isset( $intros[ $r->post_name ] ) ? $intros[ $r->post_name ] : '';
	$new   = ( '' !== $intro ? gpc_block( 'paragraph', '<p>' . esc_html( $intro ) . '</p>' ) . "\n\n" : '' ) . $body;
	$parsed = array_values( array_filter( parse_blocks( $new ), function ( $b ) { return ! empty( $b['blockName'] ); } ) );
	$first  = $parsed ? $parsed[0]['blockName'] : '-';
	$s = array();
	foreach ( $stats as $k => $v ) {
		$s[] = "$k=$v";
	}
	printf( "%-8s OK  %s  first=%s  intro=%s  removed=%d chars\n", $r->post_name, implode( ' ', $s ), $first, '' !== $intro ? 'yes' : 'no', strlen( $raw ) - strlen( $clean ) );
	$ok++;
	if ( GPC_SAVE ) {
		$res = wp_update_post( array( 'ID' => (int) $r->ID, 'post_content' => wp_slash( $new ) ), true );
		echo is_wp_error( $res ) ? "  SAVE FAILED: " . $res->get_error_message() . "\n" : "  saved\n";
	}
}
echo "$ok/" . count( $rows ) . " pages pass" . ( GPC_SAVE ? ' and were saved' : ' (dry run, nothing saved)' ) . "\n";
