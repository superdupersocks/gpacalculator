<?php
/**
 * "On this page" on a /gpa-scale/<x-y>-gpa/ page as content, in the College GPA page's shared markup (Digant 2026-10-03
 * 19:52): a core Details block (class gpa-toc, summary "On this page · N sections") holding a Rank Math TOC block,
 * placed right under the top card ([gpa_scale_view]). The numbered H2s get id attributes equal to the IDs the live page
 * gives them today (the theme's browser TOC built them from the heading text), so every existing #link keeps working.
 * The FAQ heading gets its ID too but is listed as disabled, as on the College GPA page. Styles: layout.css (live).
 *
 * WP-CLI: GPC_SLUG=3-7-gpa wp eval-file gpa_scale_toc_block.php
 *   (dry run: prints the change, the rendered content and the page's JSON-LD with the new content, nothing saved)
 *   GPC_SAVE=1 saves (the page is re-read right before saving; revisions kept).
 * $ids (inlined by the caller, optional): { "H2 text": "live-id" } to pin IDs; otherwise the browser builder's rule.
 */
$ids  = isset( $ids ) ? $ids : array();
$slug = getenv( 'GPC_SLUG' );
$save = '1' === getenv( 'GPC_SAVE' );
$page = get_page_by_path( 'gpa-scale/' . $slug );
if ( ! $page ) { echo "no page $slug\n"; return; }
kses_remove_filters();
add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );

// gpa_toc_builder() (functions.php) rule: lowercase, runs of other characters to "-", trimmed, 60 characters.
$live_id = function ( $text ) use ( &$ids ) {
	if ( isset( $ids[ $text ] ) ) { return $ids[ $text ]; }
	return substr( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $text ) ), '-' ), 0, 60 );
};

$build = function ( $c ) use ( $live_id ) {
	if ( false !== strpos( $c, 'wp:rank-math/toc-block' ) ) { return array( null, 'already has a TOC block' ); }
	$headings = array();
	$n_ids    = 0;
	$c = preg_replace_callback( '#(<!-- wp:heading(?: \{[^\n]*?\})? -->\s*)<h2([^>]*)>(.*?)</h2>#s', function ( $m ) use ( &$headings, &$n_ids, $live_id ) {
		$attrs = $m[2];
		$text  = trim( html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES, 'UTF-8' ) );
		if ( preg_match( '/\bid="([^"]+)"/', $attrs, $im ) ) {
			$id = $im[1];
		} else {
			$id     = $live_id( $text );
			$attrs  = ' id="' . esc_attr( $id ) . '"' . $attrs;
			$n_ids++;
		}
		$faq        = (bool) preg_match( '/frequently asked questions|\bFAQs?\b/i', $text );
		$headings[] = array( 'key' => 'h-' . $id, 'content' => $text, 'level' => 2, 'link' => '#' . $id, 'disable' => $faq );
		return $m[1] . '<h2' . $attrs . '>' . $m[3] . '</h2>';
	}, $c );
	$on = array_values( array_filter( $headings, function ( $h ) { return ! $h['disable']; } ) );
	$links = '';
	foreach ( $on as $h ) { $links .= '<li><a href="' . esc_attr( $h['link'] ) . '">' . esc_html( $h['content'] ) . '</a></li>'; }
	$attrs = array( 'title' => 'On this page', 'headings' => $headings, 'listStyle' => 'ul', 'titleWrapper' => 'p', 'excludeHeadings' => array( 'h3', 'h4', 'h5', 'h6' ) );
	$toc   = "<!-- wp:details {\"className\":\"gpa-toc\"} -->\n"
		. '<details class="wp-block-details gpa-toc"><summary>On this page <span class="gpa-toc__count">· ' . count( $on ) . ' sections</span></summary>'
		. '<!-- wp:rank-math/toc-block ' . serialize_block_attributes( $attrs ) . ' --><div class="wp-block-rank-math-toc-block" id="rank-math-toc"><p>On this page</p><nav><ul>'
		. $links . '</ul></nav></div><!-- /wp:rank-math/toc-block --></details>' . "\n<!-- /wp:details -->";
	$card = '#<!-- wp:shortcode -->\s*\[gpa_scale_view[^\]]*\]\s*<!-- /wp:shortcode -->#';
	if ( ! preg_match( $card, $c, $cm, PREG_OFFSET_CAPTURE ) ) { return array( null, 'top card not found' ); }
	$at = $cm[0][1] + strlen( $cm[0][0] );
	$c  = substr( $c, 0, $at ) . "\n\n" . $toc . substr( $c, $at );
	return array( $c, count( $on ) . ' sections listed, ' . ( count( $headings ) - count( $on ) ) . ' disabled, ' . $n_ids . ' heading IDs added: '
		. implode( ', ', wp_list_pluck( $headings, 'link' ) ) );
};

$old = get_post_field( 'post_content', $page->ID );
list( $new, $msg ) = $build( $old );
echo "$slug: $msg\n";
if ( null === $new ) { return; }

// gate: apart from the TOC block and the id attributes, nothing changes
$strip = function ( $c ) { return preg_replace( '#\s+#', ' ', preg_replace( '#<!-- wp:details \{"className":"gpa-toc"\} -->.*?<!-- /wp:details -->|\sid="[^"]*"#s', '', $c ) ); };
echo 'gate: ' . ( $strip( $old ) === $strip( $new ) ? 'OK (only the TOC block and heading ids differ)' : 'DIFFERS' ) . "\n";

if ( ! $save ) {
	global $wp_query, $post;
	$post = get_post( $page->ID );
	$post->post_content = $new;
	setup_postdata( $post );
	$wp_query->queried_object = $post; $wp_query->queried_object_id = $post->ID; $wp_query->is_singular = true; $wp_query->is_page = true;
	echo "=====CONTENT=====\n" . apply_filters( 'the_content', $new ) . "\n";
	add_filter( 'the_posts', function ( $p ) use ( $new ) { foreach ( $p as $x ) { $x->post_content = $new; } return $p; } );
	return;
}
$cur = get_post_field( 'post_content', $page->ID ); // re-read right before saving
if ( $cur !== $old ) { echo "CHANGED SINCE READ, not saved\n"; return; }
$before = wp_save_post_revision( $page->ID );
$r      = wp_update_post( array( 'ID' => $page->ID, 'post_content' => wp_slash( $new ) ), true );
echo is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() . "\n" : 'saved; revisions ' . (int) $before . ' → ' . (int) wp_save_post_revision( $page->ID ) . "\n";
