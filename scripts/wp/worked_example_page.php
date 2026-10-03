<?php
/**
 * Replace a page's hand-built worked GPA example (an .rx-table core table block followed by the .rx-result paragraph
 * "GPA = 40.9 ÷ 12 credits = 3.41") with the theme's [gpa_table type="worked-example"] component.
 * WP-CLI: GPC_ID=22 wp eval-file worked_example_page.php   (dry run); GPC_SAVE=1 to save (a revision keeps the old one).
 * Gate: the example's numbers in the rendered component (40.9, 12, 3.41) must match the old block's.
 */
$id   = (int) ( getenv( 'GPC_ID' ) ?: 22 );
$save = '1' === getenv( 'GPC_SAVE' );
$ex   = getenv( 'GPC_EXAMPLE' ) ?: 'college';
kses_remove_filters();

$post = get_post( $id );
if ( ! $post ) { echo "no post $id\n"; return; }
$old = $post->post_content;

$re = '#<!-- wp:table \{(?:(?!-->).)*?"className":"rx-table"(?:(?!-->).)*? -->\s*<figure class="wp-block-table rx-table">.*?</figure>\s*<!-- /wp:table -->\s*'
    . '<!-- wp:paragraph \{(?:(?!-->).)*?"className":"rx-result"(?:(?!-->).)*? -->\s*<p[^>]*rx-result[^>]*>(.*?)</p>\s*<!-- /wp:paragraph -->#s';
if ( ! preg_match( $re, $old, $m ) ) { echo "page $id: no rx-table + rx-result example found\n"; return; }

$old_nums = array();
preg_match_all( '#\d+(?:\.\d+)?#', wp_strip_all_tags( $m[1] ), $nm );
$old_nums = $nm[0];
$component = gpa_render_worked_example( $ex );
preg_match_all( '#gpa-ex__value">([^<]+)<#', $component, $vm );
$new_nums = $vm[1];
printf( "page %d %s: old result %s | component %s\n", $id, $post->post_name, implode( ' ', $old_nums ), implode( ' ', $new_nums ) );
foreach ( $new_nums as $n ) {
	if ( ! in_array( $n, $old_nums, true ) ) { echo "SKIP: component value $n not in the old result\n"; return; }
}
$block = "<!-- wp:shortcode -->\n[gpa_table type=\"worked-example\"" . ( 'college' === $ex ? '' : " example=\"$ex\"" ) . "]\n<!-- /wp:shortcode -->";
$new   = str_replace( $m[0], $block, $old );
echo "numbers check: same\n";
if ( $save ) {
	$res = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $new ) ), true );
	echo is_wp_error( $res ) ? 'SAVE FAILED: ' . $res->get_error_message() . "\n" : "saved (revision kept)\n";
} else {
	echo "dry run: set GPC_SAVE=1 to save\n";
}
