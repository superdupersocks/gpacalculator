<?php
/**
 * Tests for the calculator engine in plugin/gpacalculator-manager/includes/ (registry, asset
 * loader, shortcodes, legacy-plugin coverage) against minimal WordPress stubs.
 * Run: php tests/php/engine_test.php
 */
define( 'ABSPATH', __DIR__ );
$tmp = sys_get_temp_dir() . '/gpacalc-engine-' . getmypid();
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
$GLOBALS['hooks'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['shortcode_tags'] = array();
$GLOBALS['page'] = array( 'id' => 0, 'content' => '' );
$GLOBALS['screen'] = 'plugins';

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

// Fake plugins dir: the engine, plus an old plugin that still owns two shortcodes.
$root = WP_PLUGIN_DIR . '/gpacalculator-manager';
@mkdir( $root . '/assets/calc-assets', 0777, true );
@mkdir( WP_PLUGIN_DIR . '/grades-gpa-plugin', 0777, true );
foreach ( array( 'grade-calculator.js', 'grade-calculator.css', 'grade-conversion.js', 'grade-conversion.css' ) as $f ) {
	file_put_contents( $root . '/assets/calc-assets/' . $f, '/**/' );
}
file_put_contents( WP_PLUGIN_DIR . '/grades-gpa-plugin/grades.php', '<?php function legacy_conv_sc() { return "<div class=old-conv></div>"; } function legacy_other_sc() { return "old"; }' );
require WP_PLUGIN_DIR . '/grades-gpa-plugin/grades.php';

$src = dirname( __DIR__, 2 ) . '/plugin/gpacalculator-manager/includes';
require $src . '/bootstrap.php';
GPACalc_Calculator_Assets::boot( $root . '/gpacalculator-manager.php' );

$GLOBALS['filters']['gpacalc_calculators'] = function ( $list ) {
	return $list + array(
		'grade'      => array( 'type' => 'grade', 'source' => 'theme', 'js' => 'grade-calculator.js', 'css' => 'grade-calculator.css', 'script_handle' => 'grade-calc', 'style_handle' => 'grade-calc-css', 'shortcodes' => array( 'grade_calc' ) ),
		'hsgpa'      => array( 'type' => 'gpa', 'source' => 'theme', 'js' => 'hs-gpa.js', 'script_handle' => 'hs-gpa' ), // not moved yet
		'conversion' => array( 'type' => 'grade-conversion', 'source' => 'grades-gpa-plugin', 'js' => 'grade-conversion.js', 'css' => 'grade-conversion.css', 'shortcodes' => array( 'old_conv', 'grade_conversion' ), 'atts' => array( 'country' => 'us' ) ),
		'seo'        => array( 'type' => 'other', 'shortcodes' => array( 'seo_calc' ), 'render' => function ( $a ) { return '<p>' . $a['n'] . '</p>'; } ),
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
function plugin_url( $f ) { return 'https://site/wp-content/plugins/gpacalculator-manager/assets/calc-assets/' . $f; }

// ---- Registry
$all = GPACalc_Registry::all();
check( 'registry slugs', array_keys( $all ), array( 'grade', 'hsgpa', 'conversion', 'seo' ) );
check( 'default handle', $all['conversion']['script_handle'], 'gpacalc-conversion' );
check( 'for_shortcode old tag', GPACalc_Registry::for_shortcode( 'old_conv' )['slug'], 'conversion' );
check( 'types listed', array_key_exists( 'grade-conversion', GPACalc_Registry::TYPES ), true );
check( 'is_moved', array( GPACalc_Calculator_Assets::is_moved( $all['grade'] ), GPACalc_Calculator_Assets::is_moved( $all['hsgpa'] ) ), array( true, false ) );

// ---- Old plugin active: it keeps its shortcodes
add_shortcode( 'old_conv', 'legacy_conv_sc' );
add_shortcode( 'old_other', 'legacy_other_sc' );
GPACalc_Shortcodes::register();
check( 'old plugin keeps its tag', $GLOBALS['shortcode_tags']['old_conv'], 'legacy_conv_sc' );
check( 'engine takes free tags', GPACalc_Shortcodes::owns( 'grade_conversion' ) && GPACalc_Shortcodes::owns( 'grade_calc' ), true );
reset_page( 20, 'Convert: [old_conv country="uk"]' );
GPACalc_Calculator_Assets::enqueue();
check( 'no engine assets on a tag the old plugin owns', wp_scripts()->queue, array() );
check( 'coverage', GPACalc_Shortcodes::coverage(), array( 'grades-gpa-plugin' => array( 'covered' => array( 'old_conv' ), 'missing' => array( 'old_other' ) ) ) );
ob_start(); GPACalc_Shortcodes::coverage_notice(); $notice = ob_get_clean();
check( 'notice: keep active', false !== strpos( $notice, 'Keep it active until these are ported: [old_other]' ), true );
$GLOBALS['screen'] = 'dashboard';
ob_start(); GPACalc_Shortcodes::coverage_notice(); check( 'notice only on Plugins screen', ob_get_clean(), '' );
$GLOBALS['screen'] = 'plugins';

// ---- Old plugin deactivated: the engine answers the same tags, no page edits
remove_shortcode( 'old_conv' ); remove_shortcode( 'old_other' );
GPACalc_Shortcodes::register();
check( 'engine takes over old tag', GPACalc_Shortcodes::owns( 'old_conv' ), true );
reset_page( 20, 'Convert: [old_conv country="uk"]' );
GPACalc_Calculator_Assets::enqueue();
check( 'assets in head for the page', array( wp_scripts()->queue, wp_styles()->queue ), array( array( 'gpacalc-conversion' ), array( 'gpacalc-conversion' ) ) );
check( 'plugin src', wp_scripts()->registered['gpacalc-conversion']->src, plugin_url( 'grade-conversion.js' ) );
$html = GPACalc_Shortcodes::render( array( 'country' => 'uk', 'scale' => 'ects' ), '', 'old_conv' );
check( 'mount markup with atts', $html, '<div class="gpacalc-mount" data-calc="conversion" data-atts="{&quot;country&quot;:&quot;uk&quot;,&quot;scale&quot;:&quot;ects&quot;}"></div>' );
check( 'att defaults', GPACalc_Shortcodes::render( '', '', 'grade_conversion' ), '<div class="gpacalc-mount" data-calc="conversion" data-atts="{&quot;country&quot;:&quot;us&quot;}"></div>' );
reset_page( 21, 'widget area' );
GPACalc_Shortcodes::render( array(), '', 'old_conv' );
check( 'late shortcode enqueues itself', wp_scripts()->queue, array( 'gpacalc-conversion' ) );
check( 'render callback', GPACalc_Shortcodes::render( array( 'n' => '7' ), '', 'seo_calc' ), '<p>7</p>' );
check( 'unknown tag', GPACalc_Shortcodes::render( array(), '', 'nope' ), '' );

// ---- Theme calculator: same handle repointed, extras kept
reset_page( 10, 'no shortcode' );
wp_register_style( 'gpa-brand-tokens', 'theme/brand-tokens.css', array(), '1' ); wp_enqueue_style( 'gpa-brand-tokens' );
wp_register_script( 'grade-calc', 'theme/calc-assets/grade-calculator.js', array( 'jquery' ), '2.9' );
wp_scripts()->registered['grade-calc']->extra = array( 'group' => 1, 'data' => 'var x=1;' );
wp_enqueue_script( 'grade-calc' );
wp_register_style( 'grade-calc-css', 'theme/calc-assets/grade-calculator.css', array(), '2.9' ); wp_enqueue_style( 'grade-calc-css' );
wp_register_script( 'hs-gpa', 'theme/calc-assets/hs-gpa.js', array(), '2.9' ); wp_enqueue_script( 'hs-gpa' );
GPACalc_Calculator_Assets::enqueue();
$s = wp_scripts()->registered['grade-calc'];
check( 'theme page: script src moved', $s->src, plugin_url( 'grade-calculator.js' ) );
check( 'theme page: deps kept', $s->deps, array( 'jquery' ) );
check( 'theme page: footer + localize kept', $s->extra, array( 'group' => 1, 'data' => 'var x=1;' ) );
check( 'theme page: version is filemtime', $s->ver, (string) filemtime( $root . '/assets/calc-assets/grade-calculator.js' ) );
check( 'theme page: style moved', wp_styles()->registered['grade-calc-css']->src, plugin_url( 'grade-calculator.css' ) );
check( 'theme page: style depends on brand tokens', wp_styles()->registered['grade-calc-css']->deps, array( 'gpa-brand-tokens' ) );
check( 'not-moved calculator keeps theme file', wp_scripts()->registered['hs-gpa']->src, 'theme/calc-assets/hs-gpa.js' );
reset_page( 11, 'About us' );
GPACalc_Calculator_Assets::enqueue();
check( 'unrelated page: nothing queued', wp_scripts()->queue, array() );

// ---- Module tags
$u = plugin_url( 'grade-calculator.js' );
$tag = "<script id=\"grade-calc-js-extra\">var x=1;</script>\n<script type=\"text/javascript\" src=\"$u\" id=\"grade-calc-js\"></script>\n";
$out = GPACalc_Calculator_Assets::module_tag( $tag, 'grade-calc' );
check( 'module tag', $out, "<script id=\"grade-calc-js-extra\">var x=1;</script>\n<script type=\"module\" src=\"$u\" id=\"grade-calc-js\"></script>\n" );
check( 'module tag idempotent', GPACalc_Calculator_Assets::module_tag( $out, 'grade-calc' ), $out );
check( 'other handle untouched', GPACalc_Calculator_Assets::module_tag( '<script src="x"></script>', 'hs-gpa' ), '<script src="x"></script>' );

// ---- Once every old tag is ported, the notice says it's safe to deactivate
add_shortcode( 'old_conv', 'legacy_conv_sc' );
GPACalc_Shortcodes::register();
ob_start(); GPACalc_Shortcodes::coverage_notice(); $notice = ob_get_clean();
check( 'notice: safe to deactivate', false !== strpos( $notice, 'all 1 of its shortcodes are covered' ), true );

// ---- Hooks
check( 'hooks', array( isset( $GLOBALS['hooks']['wp_enqueue_scripts'] ), isset( $GLOBALS['hooks']['script_loader_tag'] ), isset( $GLOBALS['hooks']['init'] ) ), array( true, true, true ) );

echo "engine_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
