<?php
/**
 * "GPA scale pages: weighted vs unweighted" (roadmap checklist), content side. WP-CLI: wp eval-file.
 * Built by scripts/build_gpa_scale_view_pass.py, which inlines $config (per page) and, for previews, the theme code.
 *
 * Hub /gpa-scale/: [gpa_scale_converter] right above the letter-grade table; "Look up a GPA" (one 2.0–4.5 grid,
 * [gpa_scale_lookup]) right below the table's note, with one line on weighted vs unweighted.
 * Each GPA page in $config:
 *   - the quick-facts quote becomes [gpa_scale_view] (Unweighted | Weighted toggle, summary, weighted converter);
 *   - the page's own row inserted inside the chart is removed (the theme marks the GPA beside the chart instead);
 *   - the old weighted note under the chart is removed (the toggle covers it);
 *   - the page's figures in the intro and "Is it good?" lead follow the site-wide rule ($config figure swaps);
 *   - the Rank Math FAQ gets the rebuilt questions (letter/% and weighted answers follow the rule and the toggle).
 *   - any older homepage link in the body is unlinked (the view holds the page's one homepage link).
 * Env: GPC_ONLY=slug,slug limits the pages; GPC_SAVE=1 saves (revision kept); GPC_RENDER=<slug> prints that page's
 * rendered draft; GPC_MOCK=1 adds placeholder 4.1–4.5 pages to the lookup grid (preview only).
 */
$save   = '1' === getenv( 'GPC_SAVE' );
$render = getenv( 'GPC_RENDER' );
$only   = array_filter( explode( ',', (string) getenv( 'GPC_ONLY' ) ) );
kses_remove_filters();
add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
if ( '1' === getenv( 'GPC_MOCK' ) ) {
	add_filter( 'gpa_scale_gpa_pages', function ( $p ) {
		foreach ( array( '4.1', '4.2', '4.3', '4.4', '4.5' ) as $g ) { $p[ $g ] = home_url( '/gpa-scale/' . str_replace( '.', '-', $g ) . '-gpa/' ); }
		return $p;
	} );
}

function vp_block( $name, $inner, $attrs = array() ) {
	$a = $attrs ? ' ' . serialize_block_attributes( $attrs ) : '';
	return "<!-- wp:$name$a -->\n$inner\n<!-- /wp:$name -->";
}
function vp_text( $html ) {
	return preg_replace( '#\s+#u', '', html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', ' ', $html ) ), ENT_QUOTES, 'UTF-8' ) );
}

// Site-wide chart (Digant 2026-10-03): D 63–66%, D− 60–62% (matches the calculators); table cells only.
function vp_chart_cells( $c ) {
	return str_replace( array( '>65–66%<', '>60–64%<' ), array( '>63–66%<', '>60–62%<' ), $c );
}

// The page's old figure -> the rule's figure, in the first two paragraphs that hold it (intro, "Is it good?" lead).
function vp_swaps( $c, $swaps, &$log = null ) {
	foreach ( $swaps as $from => $to ) {
		$done = 0;
		$c = preg_replace_callback( '#(<!-- wp:paragraph -->\s*<p>)(.*?)(</p>)#s', function ( $p ) use ( $from, $to, &$done ) {
			if ( $done >= 2 || false === strpos( $p[2], $from ) ) { return $p[0]; }
			$done++;
			return $p[1] . str_replace( $from, $to, $p[2] ) . $p[3];
		}, $c );
		if ( is_array( $log ) ) { $log[] = "swap \"$from\"→\"$to\" x$done"; }
	}
	return $c;
}

function vp_hub( $c ) {
	if ( false !== strpos( $c, '[gpa_scale_lookup]' ) ) { return array( null, 'already done' ); }
	$c = vp_chart_cells( $c );
	$tbl = strpos( $c, '<!-- wp:table -->' );
	if ( false === $tbl || false === strpos( $c, '>Grade points</th>', $tbl ) ) { return array( null, 'letter-grade table not found' ); }
	// "new converter only" (Digant 07:59): drop the old Custom HTML GPA Converter at the top of the hub
	$c   = preg_replace( '#<!-- wp:html -->\s*<!-- GPA Converter .*?id="gpa-converter".*?<!-- /wp:html -->\s*#s', '', $c, 1, $dropped );
	// the old converter is gone, so the bonus note points at the table and the GPA pages' estimator instead
	$c   = str_replace( 'These are the example bonuses used by this page’s converter, not rules', 'These are the example bonuses used in the table below and in the weighted estimator on each GPA page, not rules', $c, $n_note );
	$tbl = strpos( $c, '<!-- wp:table -->' );
	$conv = vp_block( 'shortcode', '[gpa_scale_converter]' ) . "\n\n";
	$c    = substr( $c, 0, $tbl ) . $conv . substr( $c, $tbl );
	$tend = strpos( $c, '<!-- /wp:table -->', $tbl + strlen( $conv ) ) + strlen( '<!-- /wp:table -->' );
	if ( preg_match( '#^\s*<!-- wp:quote -->.*?<!-- /wp:quote -->#s', substr( $c, $tend ), $q ) ) { $tend += strlen( $q[0] ); }
	$look = "\n\n" . vp_block( 'heading', '<h2 class="wp-block-heading has-text-align-center" id="look-up-a-gpa">Look up a GPA</h2>', array( 'style' => array( 'typography' => array( 'textAlign' => 'center' ) ), 'anchor' => 'look-up-a-gpa' ) ) . "\n\n"
		. vp_block( 'paragraph', '<p>Pick a GPA to see its letter grade and percentage, whether it is good, what it means for college and how to raise it.</p>' ) . "\n\n"
		. vp_block( 'paragraph', '<p class="gpa-lookup__note">Every GPA under 4.0 can be weighted or unweighted; each page shows both. A GPA above 4.0 is always weighted.</p>', array( 'className' => 'gpa-lookup__note' ) ) . "\n\n"
		. vp_block( 'shortcode', '[gpa_scale_lookup]' );
	return array( substr( $c, 0, $tend ) . $look . substr( $c, $tend ), ( $dropped ? 'old converter removed; ' : 'old converter NOT FOUND; ' ) . ( $n_note ? 'bonus note reworded; ' : 'bonus note NOT FOUND; ' ) . 'converter above the table + Look up a GPA below it' );
}

function vp_page( $c, $slug, $cfg, &$log ) {
	if ( false !== strpos( $c, '[gpa_scale_view' ) ) { return null; }
	$gs      = $slug[0] . '.' . $slug[2];
	$removed = array();
	$added   = array();
	$n_cells = substr_count( $c, '>65–66%<' ) + substr_count( $c, '>60–64%<' );
	$c       = vp_chart_cells( $c );
	if ( $n_cells ) { $log[] = "chart cells x$n_cells"; }
	// quick-facts quote -> view
	$view = vp_block( 'shortcode', '[gpa_scale_view home_anchor="' . $cfg['home_anchor'] . '"]' );
	// (4.0 has an older quote: "A 4.0 GPA equals an A average…" / "93–95%")
	if ( ! preg_match( '#<!-- wp:quote -->\s*<blockquote class="wp-block-quote">\s*<!-- wp:list -->(?:(?!<!-- /wp:quote -->).)*?(?:Letter grade:|GPA equals an A average).*?<!-- /wp:quote -->#s', $c, $m ) ) { $log[] = 'NO QUICK FACTS'; return null; }
	$c = str_replace( $m[0], $view, $c ); $removed[] = $m[0]; $added[] = $view; $log[] = 'view';
	// the page's own row inside the chart (only when it isn't a chart value)
	if ( preg_match( '#<tr><td[^>]*>' . preg_quote( $gs, '#' ) . '</td><td[^>]*>[^<]*</td><td[^>]*>[^<]*</td></tr>#', $c, $m ) && ! in_array( $gs, array( '4.0', '3.7', '3.3', '3.0', '2.7', '2.3', '2.0', '1.7', '1.3', '1.0' ), true ) ) {
		$c = str_replace( $m[0], '', $c ); $removed[] = $m[0]; $log[] = 'chart row';
	}
	// old weighted note under the chart
	if ( preg_match( '#<!-- wp:paragraph -->\s*<p><strong>(?:Weighted or unweighted\?|Can a GPA be higher than 4\.0\?)</strong>.*?</p>\s*<!-- /wp:paragraph -->\s*#s', $c, $m ) ) {
		$c = str_replace( $m[0], '', $c ); $removed[] = $m[0]; $log[] = 'old note';
	}
	// the old "Weighted or unweighted?" H3 (3.4 and below)
	if ( preg_match( '#<!-- wp:heading \{"level":3\} -->\s*<h3[^>]*>Weighted or unweighted\?</h3>\s*<!-- /wp:heading -->\s*<!-- wp:paragraph -->.*?<!-- /wp:paragraph -->\s*#s', $c, $m ) ) {
		$c = str_replace( $m[0], '', $c ); $removed[] = $m[0]; $log[] = 'old H3';
	}
	// figure swaps in the intro (first paragraph) and the "Is it good?" lead
	$c = vp_swaps( $c, $cfg['swaps'], $log );
	// older homepage links in the body: the view holds the page's one homepage link
	$n = 0;
	$c = preg_replace_callback( '#<a\s+href="https://gpacalculator\.net/?"[^>]*>(.*?)</a>#is', function ( $m ) use ( &$n ) { $n++; return $m[1]; }, $c );
	if ( $n ) { $log[] = "unlinked $n homepage link(s)"; }
	// FAQ
	if ( preg_match( '#<!-- wp:rank-math/faq-block .*?<!-- /wp:rank-math/faq-block -->#s', $c, $fb ) ) {
		$qs = array();
		foreach ( $cfg['faqs'] as $q ) { $qs[] = array( 'id' => 'faq-' . substr( md5( $slug . $q['title'] ), 0, 10 ), 'title' => $q['title'], 'content' => $q['content'], 'visible' => true ); }
		$inner = '<div class="wp-block-rank-math-faq-block">';
		foreach ( $qs as $q ) { $inner .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . esc_html( $q['title'] ) . '</h3><div class="rank-math-answer">' . esc_html( $q['content'] ) . '</div></div>'; }
		$faq = '<!-- wp:rank-math/faq-block ' . serialize_block_attributes( array( 'questions' => $qs ) ) . " -->\n" . $inner . "</div>\n<!-- /wp:rank-math/faq-block -->";
		$c = str_replace( $fb[0], $faq, $c ); $removed[] = $fb[0]; $added[] = $faq; $log[] = 'faq ' . count( $qs );
	}
	return array( $c, $removed, $added );
}

global $wpdb;
$plan = array();
foreach ( $config as $slug => $cfg ) {
	if ( $only && ! in_array( $slug, $only, true ) ) { continue; }
	if ( 'gpa-scale' === $slug ) {
		$hub = get_page_by_path( 'gpa-scale' );
		list( $new, $msg ) = vp_hub( $hub->post_content );
		echo "gpa-scale: $msg\n";
		if ( $new ) { $plan[ $slug ] = array( $hub->ID, $new ); }
		continue;
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type='page' AND post_status='publish' AND post_name=%s", $slug ) );
	$log = array();
	$res = vp_page( $row->post_content, $slug, $cfg, $log );
	if ( ! $res ) { echo "$slug: SKIP " . implode( '; ', $log ) . "\n"; continue; }
	list( $new, $removed, $added ) = $res;
	// gate: apart from the removed / added blocks and the figure swaps, the text is unchanged
	$a = $row->post_content; foreach ( $removed as $r ) { $a = str_replace( $r, '', $a ); }
	$b = $new; foreach ( $added as $r ) { $b = str_replace( $r, '', $b ); }
	$a = vp_swaps( vp_chart_cells( $a ), $cfg['swaps'] );
	$ok = vp_text( $a ) === vp_text( $b );
	echo "$slug: " . implode( '; ', $log ) . ( $ok ? '  OK' : '  TEXT DIFFERS' ) . "\n";
	if ( $ok ) { $plan[ $slug ] = array( (int) $row->ID, $new ); }
}
if ( 'all' === $render ) {
	global $wp_query, $post;
	foreach ( $plan as $slug => list( $id, $content ) ) {
		$post = get_post( $id ); setup_postdata( $post );
		$wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID; $wp_query->is_singular = true; $wp_query->is_page = true;
		echo "=====PAGE $slug=====\n" . apply_filters( 'the_content', $content ) . "\n";
	}
	return;
}
if ( $render ) {
	global $wp_query, $post;
	$post = get_post( $plan[ $render ][0] ); setup_postdata( $post );
	$wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID; $wp_query->is_singular = true; $wp_query->is_page = true;
	echo "=====RENDER=====\n" . apply_filters( 'the_content', $plan[ $render ][1] );
	return;
}
if ( $save ) {
	foreach ( $plan as $slug => list( $id, $content ) ) {
		$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $content ) ), true );
		echo "$slug: " . ( is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() : 'saved' ) . "\n";
	}
}
echo count( $plan ) . ' pages ' . ( $save ? 'saved' : 'ready (dry run)' ) . "\n";
