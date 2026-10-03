<?php
/**
 * Remove one stretch of a page's content between two markers (inclusive), e.g. a worked-out calculation the example
 * component now shows. WP-CLI env: GPC_ID, GPC_FROM (text where the cut starts: the start of the element holding it),
 * GPC_TO (text where it ends: through the end of the element holding it), GPC_SAVE=1 to save; dry run prints the cut.
 * The cut starts at the block comment or tag that opens GPC_FROM's element and ends after the closing block comment
 * or tag of GPC_TO's element. Saved with wp_update_post plus an explicit revision.
 */
$id   = (int) getenv( 'GPC_ID' );
$from = (string) getenv( 'GPC_FROM' );
$to   = (string) getenv( 'GPC_TO' );
$save = '1' === getenv( 'GPC_SAVE' );
kses_remove_filters();
$post = get_post( $id );
if ( ! $post || '' === $from || '' === $to ) { echo "need GPC_ID, GPC_FROM, GPC_TO\n"; return; }
$c = $post->post_content;
$f = strpos( $c, $from );
$t = false === $f ? false : strpos( $c, $to, $f );
if ( false === $f || false === $t ) { echo "page $id: markers not found\n"; return; }
if ( 1 !== substr_count( $c, $from ) ) { echo "page $id: GPC_FROM is not unique\n"; return; }
// start: the block comment just before, if the element is a block; else the opening tag
$bc = strrpos( substr( $c, 0, $f ), '<!-- wp:' );
$tg = strrpos( substr( $c, 0, $f ), "\n<" );
$start = ( false !== $bc && '' === trim( preg_replace( '#<[^>]+>|<!--.*?-->#s', '', substr( $c, $bc, $f - $bc ) ) ) ) ? $bc : ( false !== $tg ? $tg + 1 : $f );
// end: after the closing block comment if the next thing is one, else after the element's closing tag
$after = $t + strlen( $to );
if ( preg_match( '#^(?:\s*</[a-z0-9]+>)*\s*(?:<!-- /wp:[a-z/-]+ -->)?#', substr( $c, $after ), $m ) ) { $after += strlen( $m[0] ); }
$cut = substr( $c, $start, $after - $start );
echo "page $id {$post->post_name}: cut " . strlen( $cut ) . " chars\n--- text removed ---\n"
	. trim( preg_replace( '#\s+#', ' ', html_entity_decode( preg_replace( '#<[^>]+>#', ' ', $cut ) ) ) ) . "\n--- next 120 chars kept ---\n"
	. trim( preg_replace( '#\s+#', ' ', html_entity_decode( preg_replace( '#<[^>]+>#', ' ', substr( $c, $after, 400 ) ) ) ) ) . "\n";
if ( ! $save ) { echo "dry run: set GPC_SAVE=1 to save\n"; return; }
$tpl = get_page_template_slug( $id );
if ( $tpl ) { add_filter( 'theme_page_templates', function ( $x ) use ( $tpl ) { $x[ $tpl ] = $tpl; return $x; } ); }
$r = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( substr( $c, 0, $start ) . substr( $c, $after ) ) ), true );
echo is_wp_error( $r ) ? 'SAVE FAILED: ' . $r->get_error_message() . "\n" : 'saved; revision ' . wp_save_post_revision( $id ) . "\n";
