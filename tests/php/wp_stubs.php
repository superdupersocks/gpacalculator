<?php
/**
 * Minimal WordPress stand-ins for the engine tests. Set WP_PLUGIN_DIR before requiring.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['hooks'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['shortcode_tags'] = array();
$GLOBALS['page'] = array( 'id' => 0, 'content' => '' );
$GLOBALS['screen'] = 'plugins';
$GLOBALS['options'] = array();
$GLOBALS['prio'] = array();

class Dep { public $src; public $ver; public $deps; public $extra = array(); public function __construct( $s, $d = array() ) { $this->src = $s; $this->deps = $d; } }
class Deps { public $registered = array(); public $queue = array(); }
$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps();
function wp_scripts() { return $GLOBALS['wps']; }
function wp_styles() { return $GLOBALS['wpst']; }
function add_action( $h, $cb ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $h ][] = $cb; $GLOBALS['prio'][ $h ][] = array( $prio, $cb ); }
// Runs filters added with add_filter() in priority order, then the test's own override in $GLOBALS['filters'].
function apply_filters( $h, $v ) {
	$list = isset( $GLOBALS['prio'][ $h ] ) ? $GLOBALS['prio'][ $h ] : array();
	usort( $list, function ( $a, $b ) { return $a[0] - $b[0]; } );
	foreach ( $list as $item ) { $v = call_user_func( $item[1], $v ); }
	return isset( $GLOBALS['filters'][ $h ] ) ? $GLOBALS['filters'][ $h ]( $v ) : $v;
}
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['options'] ) ? $GLOBALS['options'][ $k ] : $d; }
function shortcode_atts( $pairs, $atts ) { $atts = (array) $atts; $out = array(); foreach ( $pairs as $k => $v ) { $out[ $k ] = array_key_exists( $k, $atts ) ? $atts[ $k ] : $v; } return $out; }
function plugins_url( $p, $f ) { return 'https://site/wp-content/plugins/' . basename( dirname( $f ) ) . '/' . $p; }
function wp_script_is( $h ) { return in_array( $h, wp_scripts()->queue, true ); }
function wp_style_is( $h ) { return in_array( $h, wp_styles()->queue, true ); }
function wp_register_script( $h, $s, $d, $v ) { wp_scripts()->registered[ $h ] = new Dep( $s, $d ); wp_scripts()->registered[ $h ]->ver = $v; }
function wp_register_style( $h, $s, $d, $v ) { wp_styles()->registered[ $h ] = new Dep( $s, $d ); wp_styles()->registered[ $h ]->ver = $v; }
function wp_enqueue_script( $h ) { if ( ! wp_script_is( $h ) ) wp_scripts()->queue[] = $h; }
function wp_enqueue_style( $h ) { if ( ! wp_style_is( $h ) ) wp_styles()->queue[] = $h; }
function is_page( $ids ) { return in_array( $GLOBALS['page']['id'], (array) $ids, true ); }
function is_singular() { return $GLOBALS['page']['id'] > 0; }
function get_post() { return (object) array( 'post_content' => $GLOBALS['page']['content'] ); }
function has_shortcode( $c, $t ) { return false !== strpos( $c, '[' . $t ); }
function shortcode_exists( $t ) { return isset( $GLOBALS['shortcode_tags'][ $t ] ); }
function add_shortcode( $t, $cb ) { $GLOBALS['shortcode_tags'][ $t ] = $cb; }
function remove_shortcode( $t ) { unset( $GLOBALS['shortcode_tags'][ $t ] ); }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $k ) ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function current_user_can() { return true; }
function get_current_screen() { return (object) array( 'id' => $GLOBALS['screen'] ); }

