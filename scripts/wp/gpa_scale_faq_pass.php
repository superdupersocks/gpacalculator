<?php
/**
 * Rebuild the Rank Math FAQ block on the /gpa-scale/<x-y>-gpa/ pages from content/gpa-scale-faqs.json (inlined as
 * $faqs by the caller: {slug: [{title, content}]}). Only the FAQ block changes; each page is re-read right before it
 * is saved, and a revision is kept. GPC_SAVE=1 saves; otherwise a dry run that reports what would change.
 */
$save = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();
add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
$n = 0;
foreach ( $faqs as $slug => $list ) {
	$page = get_page_by_path( 'gpa-scale/' . $slug );
	if ( ! $page ) { echo "$slug: NOT FOUND\n"; continue; }
	$c = get_post_field( 'post_content', $page->ID );
	if ( ! preg_match( '#<!-- wp:rank-math/faq-block .*?<!-- /wp:rank-math/faq-block -->#s', $c, $fb ) ) { echo "$slug: no FAQ block\n"; continue; }
	$qs = array();
	foreach ( $list as $q ) { $qs[] = array( 'id' => 'faq-' . substr( md5( $slug . $q['title'] ), 0, 10 ), 'title' => $q['title'], 'content' => $q['content'], 'visible' => true ); }
	$inner = '<div class="wp-block-rank-math-faq-block">';
	foreach ( $qs as $q ) { $inner .= '<div class="rank-math-faq-item"><h3 class="rank-math-question">' . esc_html( $q['title'] ) . '</h3><div class="rank-math-answer">' . esc_html( $q['content'] ) . '</div></div>'; }
	$faq = '<!-- wp:rank-math/faq-block ' . serialize_block_attributes( array( 'questions' => $qs ) ) . " -->\n" . $inner . "</div>\n<!-- /wp:rank-math/faq-block -->";
	if ( $faq === $fb[0] ) { echo "$slug: unchanged\n"; continue; }
	$n++;
	if ( ! $save ) { echo "$slug: would update the FAQ\n"; continue; }
	$c = get_post_field( 'post_content', $page->ID ); // re-read right before saving
	if ( false === strpos( $c, $fb[0] ) ) { echo "$slug: CHANGED SINCE READ, skipped\n"; continue; }
	$before = wp_save_post_revision( $page->ID );
	$r      = wp_update_post( array( 'ID' => $page->ID, 'post_content' => wp_slash( str_replace( $fb[0], $faq, $c ) ) ), true );
	echo "$slug: " . ( is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() : 'saved; revisions ' . (int) $before . ' → ' . (int) wp_save_post_revision( $page->ID ) ) . "\n";
}
echo "$n pages " . ( $save ? 'saved' : 'to update (dry run)' ) . "\n";
