<?php
/**
 * GPA Scale hub (22335): the letter-grade table's "Example percentage range" header becomes "% range" (Digant 2026-10-03 09:00).
 * Re-reads the page right before saving and changes only that header cell. GPC_SAVE=1 saves (revisions kept).
 */
$id  = 22335;
$old = '>Example percentage range</th>';
$new = '>% range</th>';
$c   = get_post_field( 'post_content', $id );
$n   = substr_count( $c, $old );
echo "header found x$n\n";
if ( 1 !== $n ) { echo "NOT SAVED\n"; return; }
if ( '1' !== getenv( 'GPC_SAVE' ) ) { echo "dry run\n"; return; }
kses_remove_filters();
$c      = get_post_field( 'post_content', $id ); // re-read right before saving
$before = wp_save_post_revision( $id );
$r      = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( str_replace( $old, $new, $c ) ) ), true );
echo is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() . "\n" : 'saved; revisions ' . (int) $before . ' → ' . (int) wp_save_post_revision( $id ) . "\n";
