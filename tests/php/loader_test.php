<?php
/**
 * Tests for plugin/gpacalculator-manager/includes/calculator-assets.php against minimal
 * WordPress stubs. Run: php tests/php/loader_test.php
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['hooks'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['page'] = array( 'id' => 0, 'content' => '' );

class Dep { public $src; public $ver; public $deps; public $extra = array(); public function __construct( $s, $d = array() ) { $this->src = $s; $this->deps = $d; } }
class Deps { public $registered = array(); public $queue = array(); }
$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps();
function wp_scripts() { return $GLOBALS['wps']; }
function wp_styles() { return $GLOBALS['wpst']; }
function add_action( $h, $cb ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function apply_filters( $h, $v ) { return isset( $GLOBALS['filters'][ $h ] ) ? $GLOBALS['filters'][ $h ]( $v ) : $v; }
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

// Fake plugin tree with one moved calculator.
$root = sys_get_temp_dir() . '/gpacalc-loader-' . getmypid() . '/gpacalculator-manager';
@mkdir( $root . '/assets/calc-assets', 0777, true );
file_put_contents( $root . '/assets/calc-assets/grade-calculator.js', '//' );
file_put_contents( $root . '/assets/calc-assets/grade-calculator.css', '/**/' );
require dirname( __DIR__, 2 ) . '/plugin/gpacalculator-manager/includes/calculator-assets.php';
GPACalc_Calculator_Assets::boot( $root . '/gpacalculator-manager.php' );

$GLOBALS['filters']['gpacalc_calculators'] = function () {
	return array(
		'grade'   => array( 'js' => 'grade-calculator.js', 'css' => 'grade-calculator.css', 'script_handle' => 'grade-calc', 'style_handle' => 'grade-calc-css', 'shortcodes' => array( 'grade_calc' ) ),
		'hsgpa'   => array( 'js' => 'hs-gpa.js', 'script_handle' => 'hs-gpa' ), // not moved yet
	);
};

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; } else { $fail++; echo "FAIL $label: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
}
function reset_page( $id, $content ) {
	$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps(); $GLOBALS['page'] = array( 'id' => $id, 'content' => $content );
}
$plugin_js = 'https://site/wp-content/plugins/gpacalculator-manager/assets/calc-assets/grade-calculator.js';

// 1. Page where the theme enqueues the calculator: same handle, plugin src, extras kept.
reset_page( 10, 'no shortcode' );
wp_register_style( 'gpa-brand-tokens', 'theme/brand-tokens.css', array(), '1' ); wp_enqueue_style( 'gpa-brand-tokens' );
wp_register_script( 'grade-calc', 'theme/calc-assets/grade-calculator.js', array( 'jquery' ), '2.9' );
wp_scripts()->registered['grade-calc']->extra = array( 'group' => 1, 'data' => 'var x=1;' );
wp_enqueue_script( 'grade-calc' );
wp_register_style( 'grade-calc-css', 'theme/calc-assets/grade-calculator.css', array(), '2.9' ); wp_enqueue_style( 'grade-calc-css' );
wp_register_script( 'hs-gpa', 'theme/calc-assets/hs-gpa.js', array(), '2.9' ); wp_enqueue_script( 'hs-gpa' );
GPACalc_Calculator_Assets::enqueue();
$s = wp_scripts()->registered['grade-calc'];
check( 'theme page: script src moved', $s->src, $plugin_js );
check( 'theme page: deps kept', $s->deps, array( 'jquery' ) );
check( 'theme page: footer + localize kept', $s->extra, array( 'group' => 1, 'data' => 'var x=1;' ) );
check( 'theme page: version is filemtime', $s->ver, (string) filemtime( $root . '/assets/calc-assets/grade-calculator.js' ) );
check( 'theme page: style moved', wp_styles()->registered['grade-calc-css']->src, str_replace( '.js', '.css', $plugin_js ) );
check( 'theme page: style depends on brand tokens', wp_styles()->registered['grade-calc-css']->deps, array( 'gpa-brand-tokens' ) );
check( 'not-moved calculator keeps theme file', wp_scripts()->registered['hs-gpa']->src, 'theme/calc-assets/hs-gpa.js' );

// 2. Module tag: added to the src tag only, idempotent, other handles untouched.
$tag = "<script id=\"grade-calc-js-extra\">var x=1;</script>\n<script type=\"text/javascript\" src=\"$plugin_js\" id=\"grade-calc-js\"></script>\n";
$out = GPACalc_Calculator_Assets::module_tag( $tag, 'grade-calc' );
check( 'module tag', $out, "<script id=\"grade-calc-js-extra\">var x=1;</script>\n<script type=\"module\" src=\"$plugin_js\" id=\"grade-calc-js\"></script>\n" );
check( 'module tag idempotent', GPACalc_Calculator_Assets::module_tag( $out, 'grade-calc' ), $out );
check( 'other handle untouched', GPACalc_Calculator_Assets::module_tag( '<script src="x"></script>', 'hs-gpa' ), '<script src="x"></script>' );

// 3. Page that doesn't load the calculator: nothing enqueued.
reset_page( 11, 'About us' );
GPACalc_Calculator_Assets::enqueue();
check( 'unrelated page: nothing queued', wp_scripts()->queue, array() );

// 4. Shortcode page with no theme enqueue: registered and enqueued from the plugin.
reset_page( 12, 'Intro [grade_calc] more' );
GPACalc_Calculator_Assets::enqueue();
check( 'shortcode page: script queued', wp_scripts()->queue, array( 'grade-calc' ) );
check( 'shortcode page: plugin src', wp_scripts()->registered['grade-calc']->src, $plugin_js );
check( 'shortcode page: style queued', wp_styles()->queue, array( 'grade-calc-css' ) );

// 5. Hooks registered.
check( 'enqueue hook', isset( $GLOBALS['hooks']['wp_enqueue_scripts'] ), true );
check( 'module filter', isset( $GLOBALS['hooks']['script_loader_tag'] ), true );

echo "loader_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
