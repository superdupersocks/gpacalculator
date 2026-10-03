<?php
/**
 * GPA scale work, steps 1 and 2 (WP-CLI: wp eval-file). Built by scripts/build_gpa_scale_hub_weighted.py.
 *
 * 1. Hub /gpa-scale/ (page 22335): [gpa_scale_converter] right above the letter-grade table, and a
 *    "Look up a GPA" section ([gpa_scale_lookup] cards) right below the table's note. The table and its links
 *    are not touched.
 * 2. 3.0–4.0 pages: a "What if it's weighted?" section before "What a X GPA means for college". It replaces the
 *    page's older weighted note (the paragraph under the table on 3.5–4.0, the "Weighted or unweighted?" H3 on
 *    3.0–3.4) and carries the page's only homepage link, with the anchor assigned by page ID. Any older homepage
 *    link in the body is unlinked (text kept). The NAEP citation is not touched.
 *
 * Env: GPC_SAVE=1 saves (revision kept). GPC_RENDER=<slug|gpa-scale> prints that page's rendered draft content
 * (for previews; GPC_MOCK=1 adds placeholder 4.1–4.5 pages to the lookup cards). Otherwise prints a dry run.
 */
$save   = '1' === getenv( 'GPC_SAVE' );
$render = getenv( 'GPC_RENDER' );
kses_remove_filters();
if ( '1' === getenv( 'GPC_MOCK' ) ) {
	add_filter( 'gpa_scale_gpa_pages', function ( $p ) {
		foreach ( array( '4.1', '4.2', '4.3', '4.4', '4.5' ) as $g ) { $p[ $g ] = home_url( '/gpa-scale/' . str_replace( '.', '-', $g ) . '-gpa/' ); }
		return $p;
	} );
}
$HOME_URL = 'https://gpacalculator.net/';
$WEIGHTED = 'https://gpacalculator.net/weighted-gpa-calculator/';

function hw_block( $name, $inner, $attrs = array() ) {
	$a = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	return "<!-- wp:$name$a -->\n$inner\n<!-- /wp:$name -->";
}
function hw_h2( $text, $anchor ) {
	return hw_block( 'heading', '<h2 class="wp-block-heading has-text-align-center" id="' . $anchor . '">' . $text . '</h2>', array( 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ), 'anchor' => $anchor ) );
}
function hw_p( $html ) { return hw_block( 'paragraph', '<p>' . $html . '</p>' ); }
function hw_text( $html ) {
	return preg_replace( '#\s+#u', '', html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' ) );
}

/* ---------- 1. hub ---------- */
function hw_hub( $c ) {
	$tbl = strpos( $c, '<!-- wp:table -->' );
	if ( false === $tbl || false === strpos( $c, '<th class="has-text-align-center" data-align="center">Grade points</th>', $tbl ) ) { return array( null, 'letter-grade table not found' ); }
	if ( false !== strpos( $c, '[gpa_scale_converter]' ) ) { return array( null, 'already has the converter' ); }
	$conv = hw_block( 'shortcode', '[gpa_scale_converter]' ) . "\n\n";
	$c    = substr( $c, 0, $tbl ) . $conv . substr( $c, $tbl );
	$tend = strpos( $c, '<!-- /wp:table -->', $tbl + strlen( $conv ) ) + strlen( '<!-- /wp:table -->' );
	$after = substr( $c, $tend );
	if ( preg_match( '#^\s*<!-- wp:quote -->.*?<!-- /wp:quote -->#s', $after, $q ) ) { $tend += strlen( $q[0] ); }
	$look = "\n\n" . hw_h2( 'Look up a GPA', 'look-up-a-gpa' ) . "\n\n"
		. hw_p( 'Pick a GPA to see its letter grade and percentage, whether it is good, what it means for college and how to raise it.' ) . "\n\n"
		. hw_block( 'shortcode', '[gpa_scale_lookup]' );
	return array( substr( $c, 0, $tend ) . $look . substr( $c, $tend ), 'converter + lookup' );
}

/* ---------- 2. 3.0–4.0 pages ---------- */
function hw_anchor_sentence( $anchor, $home ) {
	$a = '<a href="' . $home . '">' . $anchor . '</a>';
	switch ( $anchor ) {
		case 'check my GPA':          return 'Asking yourself how to ' . $a . ' on both scales? Enter your classes and grades on our homepage calculator and it does the math.';
		case 'check your GPA':        return 'You can ' . $a . ' on our homepage: enter each class and its grade, and the result updates as you go.';
		case 'GPA calculator':        return 'Our ' . $a . ' works it out from your own classes and grades.';
		case 'online GPA calculator': return 'Our free ' . $a . ' works it out from your own classes and grades.';
		case 'calculate your GPA':    return 'To ' . $a . ' from your own classes, enter each class and its grade on our homepage.';
		case 'calculate my GPA':      return 'Wondering how to ' . $a . ' with Honors or AP classes in the mix? Start with your classes and grades on our homepage calculator.';
	}
	return '';
}
function hw_weighted_section( $g, $anchor, $home, $weighted ) {
	$gs   = number_format( $g, 1 );
	$down = number_format( $g - 0.25, 2 );
	$up   = number_format( $g + 0.25, 2 );
	return hw_h2( 'What if it’s weighted?', 'what-if-its-weighted' ) . "\n\n"
		. hw_p( "This page reads {$gs} on the standard unweighted scale, where an A is worth 4.0. Many high schools also report a weighted GPA, which adds points for Honors, AP or IB classes (commonly +0.5 for Honors and +1.0 for AP or IB), so the same number means something different on each scale." ) . "\n\n"
		. hw_p( "<strong>If {$gs} is your weighted GPA</strong>, your unweighted GPA is lower. For example, if a quarter of your classes are AP or IB at +1.0, a {$gs} weighted works out to about a {$down} unweighted." ) . "\n\n"
		. hw_p( "<strong>If {$gs} is unweighted</strong>, the same grades with that course load come to about a {$up} weighted. Colleges often recalculate GPAs their own way, so report the one your transcript shows." ) . "\n\n"
		. hw_p( hw_anchor_sentence( $anchor, $home ) . ' For Honors and AP classes, the <a href="' . $weighted . '">Weighted GPA calculator</a> shows both numbers.' );
}
function hw_page( $c, $g, $anchor, $home, $weighted, &$log ) {
	if ( false !== strpos( $c, 'id="what-if-its-weighted"' ) ) { return null; }
	$old_text = array();
	// older weighted note under the table (3.5–4.0)
	if ( preg_match( '#<!-- wp:paragraph -->\s*<p><strong>(?:Weighted or unweighted\?|Can a GPA be higher than 4\.0\?)</strong>.*?</p>\s*<!-- /wp:paragraph -->\s*#s', $c, $m ) ) {
		$c = str_replace( $m[0], '', $c ); $old_text[] = $m[0]; $log[] = 'removed note under table';
	}
	// older "Weighted or unweighted?" H3 + its paragraph (3.0–3.4)
	if ( preg_match( '#<!-- wp:heading \{"level":3\} -->\s*<h3[^>]*>Weighted or unweighted\?</h3>\s*<!-- /wp:heading -->\s*<!-- wp:paragraph -->.*?<!-- /wp:paragraph -->\s*#s', $c, $m ) ) {
		$c = str_replace( $m[0], '', $c ); $old_text[] = $m[0]; $log[] = 'removed H3 note';
	}
	// unlink older homepage links in the body
	$n = 0;
	$c = preg_replace_callback( '#<a\s+href="https://gpacalculator\.net/?"[^>]*>(.*?)</a>#is', function ( $m ) use ( &$n ) { $n++; return $m[1]; }, $c );
	if ( $n ) { $log[] = "unlinked $n older homepage link(s)"; }
	// insert before "What a X GPA means for college" (4.0 has no such H2: before its first H2 after the table)
	$sec = hw_weighted_section( $g, $anchor, $home, $weighted ) . "\n\n";
	if ( preg_match( '#<!-- wp:heading[^>]*-->\s*<h2[^>]*>What a [0-9.]+ GPA means for college</h2>#', $c, $m, PREG_OFFSET_CAPTURE ) ) {
		$at = $m[0][1];
	} else {
		$tbl = strpos( $c, '<!-- /wp:table -->' );
		$at  = preg_match( '#<!-- wp:heading[^>]*-->\s*<h2#', $c, $m2, PREG_OFFSET_CAPTURE, $tbl ) ? $m2[0][1] : false;
	}
	if ( false === $at ) { return null; }
	$log[] = 'section inserted';
	return array( substr( $c, 0, $at ) . $sec . substr( $c, $at ), implode( '', $old_text ), $sec );
}

/* ---------- run ---------- */
global $wpdb;
$anchors = array( 'check my GPA', 'check your GPA', 'GPA calculator', 'online GPA calculator', 'calculate your GPA', 'calculate my GPA' );
$rows    = $wpdb->get_results( "SELECT ID, post_name, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name REGEXP '^(3-[0-9]|4-0)-gpa$' ORDER BY ID ASC" );
$plan    = array();
foreach ( $rows as $i => $r ) {
	$g      = (float) str_replace( '-', '.', substr( $r->post_name, 0, 3 ) );
	$anchor = $anchors[ $i % count( $anchors ) ];
	$log    = array();
	$res    = hw_page( $r->post_content, $g, $anchor, $HOME_URL, $WEIGHTED, $log );
	if ( ! $res ) { echo "{$r->post_name}: SKIP (already done or no insertion point)\n"; continue; }
	list( $new, $removed, $sec ) = $res;
	// gate: text equal after taking out the removed notes and the new section; links only changed
	$a = hw_text( str_replace( $removed, '', $r->post_content ) );
	$b = hw_text( str_replace( $sec, '', $new ) );
	$ok = $a === $b;
	printf( "%-8s id=%d anchor=\"%s\" %s %s\n", $r->post_name, $r->ID, $anchor, implode( '; ', $log ), $ok ? 'OK' : 'TEXT DIFFERS' );
	if ( $ok ) { $plan[ $r->post_name ] = array( $r->ID, $new ); }
}
$hub = get_page_by_path( 'gpa-scale' );
list( $hub_new, $hub_log ) = hw_hub( $hub->post_content );
echo "gpa-scale (hub, id={$hub->ID}): " . ( $hub_new ? $hub_log . ', table unchanged: ' . ( false !== strpos( $hub_new, substr( $hub->post_content, strpos( $hub->post_content, '<!-- wp:table -->' ), 800 ) ) ? 'yes' : 'NO' ) : $hub_log ) . "\n";
if ( $hub_new ) { $plan['gpa-scale'] = array( $hub->ID, $hub_new ); }

if ( $render ) {
	global $wp_query, $post;
	$post = get_post( $plan[ $render ][0] ); setup_postdata( $post );
	$wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID; $wp_query->is_singular = true; $wp_query->is_page = true;
	echo "=====RENDER=====\n" . apply_filters( 'the_content', $plan[ $render ][1] );
	return;
}
if ( $save ) {
	foreach ( $plan as $slug => list( $id, $content ) ) {
		$res = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $content ) ), true );
		echo $slug . ': ' . ( is_wp_error( $res ) ? 'SAVE FAILED ' . $res->get_error_message() : 'saved' ) . "\n";
	}
}
echo count( $plan ) . ' pages ' . ( $save ? 'saved' : 'ready (dry run)' ) . "\n";
