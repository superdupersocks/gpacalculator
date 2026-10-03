<?php
/**
 * The per-page switch (includes/calc-switch.php): every calculator stays on its old version until it
 * is ticked in Grade + GPA > New calculators; ?calc=old falls back, ?calc=new previews for editors.
 * Run: php tests/php/calc_switch_test.php
 */
ini_set( 'error_log', '/dev/null' );
$repo = dirname( __DIR__, 2 );
$tmp  = sys_get_temp_dir() . '/gpacalc-switch-' . getmypid();
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
@mkdir( WP_PLUGIN_DIR, 0777, true );
symlink( $repo . '/plugin/gpacalculator-manager', WP_PLUGIN_DIR . '/gpacalculator-manager' );

require __DIR__ . '/wp_stubs.php';
function esc_url( $u ) { return $u; }
function register_activation_hook( $f, $cb ) {}
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function sanitize_title( $s ) { return strtolower( trim( preg_replace( '/[^A-Za-z0-9-]+/', '-', $s ), '-' ) ); }
function add_query_arg( $k, $v, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $k . '=' . $v; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function wp_get_upload_dir() { return array( 'error' => false, 'basedir' => '/nonexistent', 'baseurl' => 'https://site/uploads' ); }
function wp_unslash( $v ) { return $v; }
function get_queried_object_id() { return 22; }
function get_permalink( $id ) { return 22 === $id ? 'https://gpacalculator.net/college-gpa-calculator/' : false; }
function rest_url( $p ) { return 'https://gpacalculator.net/wp-json/' . $p; }
function wp_dequeue_script( $h ) { wp_scripts()->queue = array_values( array_diff( wp_scripts()->queue, array( $h ) ) ); }
function wp_dequeue_style( $h ) { wp_styles()->queue = array_values( array_diff( wp_styles()->queue, array( $h ) ) ); }

$GLOBALS['options']['gpcm_university_profiles'] = array(
	'ucla'     => json_decode( file_get_contents( $repo . '/data/gpcm-university-profiles/ucla.json' ), true ),
);
$GLOBALS['options']['calcs_plugin_shortcodes'] = array();
require WP_PLUGIN_DIR . '/gpacalculator-manager/gpacalculator-manager.php';

gpcm_register_shortcode();
GPACalc_Shortcodes::register();

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; } else { $fail++; echo "FAIL $label: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
}
function reset_queue() {
	$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps();
	foreach ( array( 'main-js-college-gpa-calculator' ) as $h ) { wp_register_script( $h, 'old.js', array(), 1 ); wp_enqueue_script( $h ); }
	foreach ( array( 'main-css-college-gpa-calculator', 'gpa-design-tokens' ) as $h ) { wp_register_style( $h, 'old.css', array(), 1 ); wp_enqueue_style( $h ); }
}
function college_page( $get = array(), $on = array() ) {
	$_GET = $get; $GLOBALS['options'][ GPACalc_Switch::OPTION ] = $on;
	$GLOBALS['page'] = array( 'id' => 'college-gpa-calculator', 'content' => '<div id="root"></div>' );
	reset_queue();
	GPACalc_Switch::enqueue();
	return array( wp_scripts()->queue, wp_styles()->queue );
}

check( 'switch hooked', in_array( array( 'GPACalc_Switch', 'enqueue' ), $GLOBALS['hooks']['wp_enqueue_scripts'], true ), true );
check( 'switches', array_keys( GPACalc_Switch::all() ), array( 'college', 'home', 'uni-ucla' ) );

// College: off by default (uploading the plugin changes nothing).
list( $js, $css ) = college_page();
check( 'college off: old script stays', $js, array( 'main-js-college-gpa-calculator' ) );
check( 'college off: old style stays', in_array( 'main-css-college-gpa-calculator', $css, true ), true );
check( 'college off: no new style', in_array( 'gpacalc-v2-0', $css, true ), false );
check( 'college off: no manifest', in_array( array( 'GPACalc_Switch', 'manifest_link' ), $GLOBALS['hooks']['wp_head'] ?? array(), true ), false );

// College on.
list( $js, $css ) = college_page( array(), array( 'college' ) );
check( 'college on: script swapped', $js, array( 'gpacalc-v2-college' ) );
check( 'college on: old style gone', in_array( 'main-css-college-gpa-calculator', $css, true ), false );
check( 'college on: core + GPA css', array_values( array_intersect( $css, array( 'gpacalc-v2-0', 'gpacalc-v2-1' ) ) ), array( 'gpacalc-v2-0', 'gpacalc-v2-1' ) );
check( 'college on: core css after tokens', wp_styles()->registered['gpacalc-v2-0']->deps, array( 'gpa-design-tokens' ) );
check( 'college on: entry file', substr( wp_scripts()->registered['gpacalc-v2-college']->src, -30 ), 'calc-assets/gpa/college-gpa.js' );
check( 'college on: entry exists', is_readable( GPACalc_Calculator_Assets::path( 'gpa/college-gpa.js' ) ), true );
check( 'college on: versioned by file time', ctype_digit( (string) wp_scripts()->registered['gpacalc-v2-college']->ver ), true );

// Add to home screen: the College page gets its own web app manifest (no service worker).
check( 'college on: manifest link in the head', in_array( array( 'GPACalc_Switch', 'manifest_link' ), $GLOBALS['hooks']['wp_head'] ?? array(), true ), true );
ob_start(); GPACalc_Switch::manifest_link(); $link = ob_get_clean();
check( 'manifest link', $link, "<link rel=\"manifest\" href=\"https://gpacalculator.net/wp-json/gpacalc/v1/manifest/22\">\n" );
check( 'manifest route hooked', in_array( array( 'GPACalc_Switch', 'manifest_route' ), $GLOBALS['hooks']['rest_api_init'], true ), true );
$m = GPACalc_Switch::manifest_data( 22 );
check( 'manifest names', array( $m['name'], $m['short_name'], $m['display'], $m['scope'] ), array( 'GPA Calculator', 'GPA Calc', 'standalone', '/' ) );
check( 'manifest start_url', $m['start_url'], 'https://gpacalculator.net/college-gpa-calculator/?source=homescreen' );
check( 'manifest icons', array_map( function ( $i ) { return array( $i['sizes'], basename( $i['src'] ) ); }, $m['icons'] ), array( array( '192x192', 'badge-192.png' ), array( '512x512', 'badge-512.png' ) ) );
check( 'manifest icon files exist', is_readable( GPACalc_Calculator_Assets::path( 'a2hs/badge-192.png' ) ) && is_readable( GPACalc_Calculator_Assets::path( 'a2hs/badge-512.png' ) ), true );
// The old shortcode enqueues its files while the content renders (after wp_enqueue_scripts): they are
// dropped again before the footer prints them (live check, Oct 3 16:58).
function old_shortcode_runs() {
	wp_register_script( 'main-js-college-gpa-calculator', 'old.js', array(), 1 ); wp_enqueue_script( 'main-js-college-gpa-calculator' );
	wp_register_style( 'main-css-college-gpa-calculator', 'old.css', array(), 1 ); wp_enqueue_style( 'main-css-college-gpa-calculator' );
}
check( 'late drop hooked before the footer prints', in_array( array( 'GPACalc_Switch', 'drop_late' ), $GLOBALS['hooks']['wp_print_footer_scripts'] ?? array(), true ) && in_array( array( 'GPACalc_Switch', 'drop_late' ), $GLOBALS['hooks']['wp_footer'] ?? array(), true ), true );
college_page( array(), array( 'college' ) );
old_shortcode_runs();
GPACalc_Switch::drop_late();
check( 'college on: old script enqueued late is dropped', wp_scripts()->queue, array( 'gpacalc-v2-college' ) );
check( 'college on: old style enqueued late is dropped', in_array( 'main-css-college-gpa-calculator', wp_styles()->queue, true ), false );
college_page();
old_shortcode_runs();
GPACalc_Switch::drop_late();
check( 'college off: late drop leaves the old calculator', wp_scripts()->queue, array( 'main-js-college-gpa-calculator' ) );
college_page( array( 'calc' => 'old' ), array( 'college' ) );
old_shortcode_runs();
GPACalc_Switch::drop_late();
check( 'college ?calc=old: late drop leaves the old calculator', wp_scripts()->queue, array( 'main-js-college-gpa-calculator' ) );

// Fallback and preview.
list( $js ) = college_page( array( 'calc' => 'old' ), array( 'college' ) );
check( 'college ?calc=old: old version', $js, array( 'main-js-college-gpa-calculator' ) );
list( $js ) = college_page( array( 'calc' => 'new' ) );
check( 'college ?calc=new (editor): preview', $js, array( 'gpacalc-v2-college' ) );

// Other pages are never touched.
$_GET = array(); $GLOBALS['options'][ GPACalc_Switch::OPTION ] = array( 'college' );
$GLOBALS['page'] = array( 'id' => 'high-school-gpa-calculator', 'content' => '' );
reset_queue(); GPACalc_Switch::enqueue();
check( 'other page untouched', wp_scripts()->queue, array( 'main-js-college-gpa-calculator' ) );

// Homepage: the front page ([gpa-calculator]), off by default; only the front page is touched.
function home_page( $get = array(), $on = array(), $front = true ) {
	$_GET = $get; $GLOBALS['options'][ GPACalc_Switch::OPTION ] = $on;
	$GLOBALS['page'] = array( 'id' => 'home', 'front' => $front, 'content' => '<div id="root"></div>' );
	$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps();
	wp_register_script( 'main-js-gpa-calculator', 'old.js', array(), 1 ); wp_enqueue_script( 'main-js-gpa-calculator' );
	wp_register_style( 'main-css-gpa-calculator', 'old.css', array(), 1 ); wp_enqueue_style( 'main-css-gpa-calculator' );
	GPACalc_Switch::enqueue();
	return array( wp_scripts()->queue, wp_styles()->queue );
}
list( $js ) = home_page();
check( 'home off: old homepage calculator', $js, array( 'main-js-gpa-calculator' ) );
list( $js, $css ) = home_page( array(), array( 'home' ) );
check( 'home on: new module only', $js, array( 'gpacalc-v2-home' ) );
check( 'home on: old style gone', in_array( 'main-css-gpa-calculator', $css, true ), false );
list( $js ) = home_page( array( 'calc' => 'old' ), array( 'home' ) );
check( 'home ?calc=old: old version', $js, array( 'main-js-gpa-calculator' ) );
list( $js ) = home_page( array(), array( 'home' ), false );
check( 'home on: other pages untouched', $js, array( 'main-js-gpa-calculator' ) );
list( $js ) = home_page( array(), array( 'college' ) );
check( 'college on: homepage untouched', $js, array( 'main-js-gpa-calculator' ) );

// Module tag.
check( 'module tag', GPACalc_Switch::module_tag( "<script type='text/javascript' src='x.js' id='gpacalc-v2-college-js'></script>", 'gpacalc-v2-college' ), "<script type=\"module\" src='x.js' id='gpacalc-v2-college-js'></script>" );
check( 'module tag: other handles', GPACalc_Switch::module_tag( "<script src='y.js'></script>", 'jquery' ), "<script src='y.js'></script>" );

// UCLA shortcode.
$sc = $GLOBALS['shortcode_tags']['gpcm_calculator'];
$_GET = array(); $GLOBALS['options'][ GPACalc_Switch::OPTION ] = array();
reset_queue(); $out = $sc( array( 'id' => 'ucla' ) );
check( 'ucla off: old host', false !== strpos( $out, 'data-gpacalc-css=' ) && false === strpos( $out, 'data-gpac-engine' ), true );
check( 'ucla off: old engine', in_array( 'gpcm-init', wp_scripts()->queue, true ), true );

$GLOBALS['options'][ GPACalc_Switch::OPTION ] = array( 'uni-ucla' );
reset_queue(); $out = $sc( array( 'id' => 'ucla' ) );
check( 'ucla on: new host', 1 === preg_match( '/^<div class="gpcm-host gpacalc-mount" data-calc="uni" data-gpcm-profile-id="ucla" data-gpac-engine="v2"><script type="application\/json" data-gpcm-profile>\{.*\}<\/script><\/div>$/s', $out ), true );
check( 'ucla on: profile json intact', json_decode( preg_replace( '/^.*data-gpcm-profile>|<\/script>.*$/s', '', $out ), true ), $GLOBALS['options']['gpcm_university_profiles']['ucla'] );
check( 'ucla on: no old engine', array_values( array_intersect( wp_scripts()->queue, array( 'gpcm-loader', 'gpcm-shared-engine', 'gpcm-init' ) ) ), array() );
check( 'ucla on: new module', in_array( 'gpacalc-v2-uni-ucla', wp_scripts()->queue, true ), true );

$_GET = array( 'calc' => 'old' );
reset_queue(); $out = $sc( array( 'id' => 'ucla' ) );
check( 'ucla ?calc=old: old host', false === strpos( $out, 'data-gpac-engine' ), true );
$_GET = array();
reset_queue(); $out = $sc( array( 'id' => 'stanford' ) );
check( 'stanford (no switch): old host', false === strpos( $out, 'data-gpac-engine' ) && in_array( 'gpcm-init', wp_scripts()->queue, true ), true );

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
echo "calc_switch_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
