<?php
/**
 * College GPA calculator page (ID 22): its 4.0 scale table follows the site-wide chart (Digant 2026-10-03):
 * A+ 97–100% and A 93–96% (both 4.0), D 63–66%, D− 60–62%. WP-CLI: wp eval-file; GPC_SAVE=1 saves with revisions.
 */
$id  = 22;
$c   = get_post_field( 'post_content', $id );
$td  = '<td class="has-text-align-center" data-align="center">';
$a   = '<mark style="background-color:#d1fae5;color:#047857" class="has-inline-color">';
$old_a = '<tr>' . $td . '4.0</td>' . $td . $a . 'A</mark></td>' . $td . '93–100%</td></tr>';
$new_a = '<tr>' . $td . '4.0</td>' . $td . $a . 'A+</mark></td>' . $td . '97–100%</td></tr>'
	. '<tr>' . $td . '4.0</td>' . $td . $a . 'A</mark></td>' . $td . '93–96%</td></tr>';
$n = array();
$c = str_replace( $old_a, $new_a, $c, $n['A'] );
$c = preg_replace( '#(>D</mark></td>' . preg_quote( $td, '#' ) . ')65–66%#', '${1}63–66%', $c, -1, $n['D'] );
$c = preg_replace( '#(>D-</mark></td>' . preg_quote( $td, '#' ) . ')60–64%#', '${1}60–62%', $c, -1, $n['D-'] );
echo 'A split x' . $n['A'] . '; D x' . $n['D'] . '; D- x' . $n['D-'] . "\n";
if ( 1 !== $n['A'] || 1 !== $n['D'] || 1 !== $n['D-'] ) { echo "NOT SAVED: expected one of each\n"; return; }
if ( '1' === getenv( 'GPC_SAVE' ) ) {
	kses_remove_filters();
	$tpl = get_page_template_slug( $id );
	if ( $tpl ) { add_filter( 'theme_page_templates', function ( $t ) use ( $tpl ) { return $t + array( $tpl => $tpl ); } ); }
	$before = wp_save_post_revision( $id );
	$r      = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( $c ) ), true );
	echo is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() . "\n" : 'saved; revisions ' . (int) $before . ' → ' . (int) wp_save_post_revision( $id ) . "\n";
} else {
	echo "dry run\n";
}
