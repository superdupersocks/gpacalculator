<?php
/**
 * Second pass on the /gpa-scale/<x-x>-gpa/ pages (WP-CLI), built by scripts/build_gpa_scale_update.py:
 *
 * 1. Headings: inside the "… Frequently asked questions" section (its H2 up to the next H2), or on every
 *    H2-H4 with --all-headings, strip <b>/<strong> and inline color / font-weight spans, so the theme's
 *    heading style applies. Text is unchanged.
 * 2. GPA chart image (uploads/<x.x>-GPA-<w>x<h>.png, in an Image, Paragraph or Custom HTML block) becomes a
 *    core Table block with class gpa-scale-table: the standard scale plus the page's own GPA as a row,
 *    which the theme highlights (gpa_scale_table_mark_rows()).
 *
 * Gate: after normalising the two intended edits away, the rendered page must equal today's render.
 * GPC_SAVE = true saves passing pages (wp_update_post keeps a revision). Dry run otherwise.
 */

// Filled in by the build script: GPC_SAVE, GPU_ALL_HEADINGS (every H2-H4, not just the FAQ section), $tables.

function gpu_clean_heading_inner( $inner ) {
	$inner = preg_replace( '#</?(?:b|strong)\b[^>]*>#i', '', $inner );
	// unwrap spans whose style is only color / font-weight
	do {
		$before = $inner;
		$inner  = preg_replace( '#<span\s+style="(?:\s*(?:color|font-weight):[^;"]*;?)+\s*">((?:(?!<span\b).)*?)</span>#is', '$1', $inner );
	} while ( $inner !== $before );
	return $inner;
}

/** Returns [ new content, number of headings changed ]. */
function gpu_unbold_faq( $content ) {
	if ( GPU_ALL_HEADINGS ) {
		$start = 0;
		$next  = strlen( $content );
	} else {
		if ( ! preg_match( '#<h2\b[^>]*>(?:(?!</h2>).)*frequently asked(?:(?!</h2>).)*</h2>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return array( $content, 0 );
		}
		$start = $m[0][1];
		$next  = preg_match( '#<h2\b#i', $content, $n, PREG_OFFSET_CAPTURE, $start + strlen( $m[0][0] ) ) ? $n[0][1] : strlen( $content );
	}
	$region = substr( $content, $start, $next - $start );
	$count  = 0;
	$region = preg_replace_callback(
		'#<(h[2-4])\b([^>]*)>(.*?)</\1>#is',
		function ( $h ) use ( &$count ) {
			$inner = gpu_clean_heading_inner( $h[3] );
			if ( $inner !== $h[3] ) {
				$count++;
			}
			return '<' . $h[1] . $h[2] . '>' . trim( $inner ) . '</' . $h[1] . '>';
		},
		$region
	);
	return array( substr( $content, 0, $start ) . $region . substr( $content, $next ), $count );
}

/** The block (with its comments) holding the GPA chart image, or null. */
function gpu_find_chart_block( $content ) {
	if ( ! preg_match( '#<img\b[^>]*/uploads/[0-9]\.[0-9]-GPA-\d+x\d+\.png[^>]*>#i', $content, $img, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$at = $img[0][1];
	if ( ! preg_match_all( '#<!--\s*wp:(image|paragraph|html)\b[^>]*-->#', substr( $content, 0, $at ), $opens, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$last  = end( $opens[0] );
	$name  = end( $opens[1] )[0];
	$close = '<!-- /wp:' . $name . ' -->';
	$end   = strpos( $content, $close, $at );
	if ( false === $end ) {
		return null;
	}
	$block = substr( $content, $last[1], $end + strlen( $close ) - $last[1] );
	// the block must hold only the image (and its caption), nothing else of substance
	$rest = trim( wp_strip_all_tags( preg_replace( array( '#<!--.*?-->#s', '#\[caption[^\]]*\]|\[/caption\]#', '#<img\b[^>]*>#i' ), '', $block ) ) );
	if ( '' !== $rest && ! preg_match( '#^[0-9]\.[0-9] GPA is equivalent to#', $rest ) ) {
		return null;
	}
	return array( $last[1], strlen( $block ) );
}

function gpu_norm( $html ) {
	$html = preg_replace( '#\s+#u', ' ', $html );
	return trim( preg_replace( '#>\s+<#', '><', $html ) );
}

if ( GPC_SAVE ) {
	kses_remove_filters();
	add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
}
global $wpdb;
$rows = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^[0-4]-[0-9]-gpa$' ORDER BY post_name DESC" );
$ok = 0;
foreach ( $rows as $r ) {
	$old = $r->post_content;
	list( $new, $heads ) = gpu_unbold_faq( $old );
	// gate 1: only heading markup changed (compare with all heading inner tags stripped the same way)
	$strip = function ( $c ) { return gpu_norm( preg_replace_callback( '#<(h[2-4])\b([^>]*)>(.*?)</\1>#is', function ( $h ) { return '<' . $h[1] . $h[2] . '>' . trim( gpu_clean_heading_inner( $h[3] ) ) . '</' . $h[1] . '>'; }, $c ) ); };
	if ( $strip( $old ) !== $strip( $new ) ) {
		echo "{$r->post_name}: SKIP heading edit touched more than headings\n";
		continue;
	}
	$chart = gpu_find_chart_block( $new );
	$table = isset( $tables[ $r->post_name ] ) ? $tables[ $r->post_name ] : '';
	$swapped = 'no';
	if ( $chart && '' !== $table ) {
		$new     = substr( $new, 0, $chart[0] ) . $table . substr( $new, $chart[0] + $chart[1] );
		$swapped = 'yes';
	}
	// gate 2: rendering. Everything outside the table must render as before (headings normalised).
	$a = $strip( apply_filters( 'the_content', $chart ? substr( $old, 0, gpu_find_chart_block( $old )[0] ) . '@@TABLE@@' . substr( $old, array_sum( gpu_find_chart_block( $old ) ) ) : $old ) );
	$b = $strip( apply_filters( 'the_content', $chart ? substr( $new, 0, $chart[0] ) . '@@TABLE@@' . substr( $new, $chart[0] + strlen( $table ) ) : $new ) );
	$a = preg_replace( '#((?:id="attachment_|caption-attachment-)\d+)-\d+"#', '$1"', $a );
	$b = preg_replace( '#((?:id="attachment_|caption-attachment-)\d+)-\d+"#', '$1"', $b );
	if ( $a !== $b ) {
		$p = 0;
		while ( $p < strlen( $a ) && $p < strlen( $b ) && $a[ $p ] === $b[ $p ] ) { $p++; }
		echo "{$r->post_name}: SKIP render differs at $p\n  old: " . substr( $a, max( 0, $p - 80 ), 200 ) . "\n  new: " . substr( $b, max( 0, $p - 80 ), 200 ) . "\n";
		continue;
	}
	$rendered = apply_filters( 'the_content', $new );
	$valid    = $swapped === 'no' || ( false !== strpos( $rendered, 'gpa-scale-table' ) && 1 === count( array_filter( parse_blocks( $new ), function ( $b ) { return 'core/table' === $b['blockName']; } ) ) );
	printf( "%-8s OK  headings_unbolded=%2d  chart->table=%s%s\n", $r->post_name, $heads, $swapped, $valid ? '' : '  TABLE CHECK FAILED' );
	if ( ! $valid ) {
		continue;
	}
	$ok++;
	if ( GPC_SAVE && $new !== $old ) {
		$res = wp_update_post( array( 'ID' => (int) $r->ID, 'post_content' => wp_slash( $new ) ), true );
		echo is_wp_error( $res ) ? "  SAVE FAILED: " . $res->get_error_message() . "\n" : "  saved\n";
	}
}
echo "$ok/" . count( $rows ) . " pages pass" . ( GPC_SAVE ? ' and were saved' : ' (dry run, nothing saved)' ) . "\n";
