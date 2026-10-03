<?php
/**
 * Replace one exact string in one page's content (WP-CLI eval-file). $swap = [ page path, from, to ] is inlined by the
 * caller. The string must occur exactly once; the page is re-read right before saving and a revision is kept.
 * GPC_SAVE=1 saves; otherwise a dry run.
 */
list( $path, $from, $to ) = $swap;
$page = get_page_by_path( $path );
if ( ! $page ) { echo "no page $path\n"; return; }
$c = get_post_field( 'post_content', $page->ID );
$n = substr_count( $c, $from );
echo "$path: found x$n\n";
if ( 1 !== $n ) { echo "NOT SAVED (needs exactly one)\n"; return; }
if ( '1' !== getenv( 'GPC_SAVE' ) ) { echo "dry run\n"; return; }
kses_remove_filters();
add_filter( 'theme_page_templates', function ( $t ) { return $t + array( 'gpa-content-page' => 'GPA content page' ); } );
if ( get_post_field( 'post_content', $page->ID ) !== $c ) { echo "CHANGED SINCE READ, not saved\n"; return; }
$before = wp_save_post_revision( $page->ID );
$r      = wp_update_post( array( 'ID' => $page->ID, 'post_content' => wp_slash( str_replace( $from, $to, $c ) ) ), true );
echo is_wp_error( $r ) ? 'SAVE FAILED ' . $r->get_error_message() . "\n" : 'saved; revisions ' . (int) $before . ' → ' . (int) wp_save_post_revision( $page->ID ) . "\n";
