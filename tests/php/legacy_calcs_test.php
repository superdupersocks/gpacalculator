<?php
/**
 * The Calculators plugin (legacy/calcs-plugin) vs the engine that replaces it.
 *
 * Runs the REAL calcs-plugin code and the engine side by side on the same saved shortcode list,
 * then checks that after the old plugin is deactivated every shortcode prints the same markup and
 * loads the same handles, from the plugin's copy when it has one.
 * Run: php tests/php/legacy_calcs_test.php
 */
ini_set( 'error_log', '/dev/null' );
$repo = dirname( __DIR__, 2 );
$tmp  = sys_get_temp_dir() . '/gpacalc-legacy-' . getmypid();
define( 'WP_PLUGIN_DIR', $tmp . '/plugins' );
@mkdir( WP_PLUGIN_DIR . '/calcs-plugin/calculators', 0777, true );
symlink( $repo . '/plugin/gpacalculator-manager', WP_PLUGIN_DIR . '/gpacalculator-manager' );
copy( $repo . '/legacy/calcs-plugin/calcs-plugin.php', WP_PLUGIN_DIR . '/calcs-plugin/calcs-plugin.php' );
copy( $repo . '/legacy/calcs-plugin/calculators/calculators.php', WP_PLUGIN_DIR . '/calcs-plugin/calculators/calculators.php' );

require __DIR__ . '/wp_stubs.php';
function esc_url( $u ) { return $u; }
function esc_url_raw( $u ) { return $u; }

$theme = 'https://gpacalculator.net/wp-content/themes/generatepress-child/calc-assets/';
$GLOBALS['options']['calcs_plugin_shortcodes'] = array(
	array( 'name' => 'GPA Calculator', 'shortcode' => 'gpa-calculator', 'js_path' => $theme . 'gpa-calculator.js', 'css_path' => $theme . 'gpa-calculator.css' ),
	array( 'name' => 'Grade Calculator', 'shortcode' => 'grade-calculator', 'js_path' => $theme . 'grade-calculator.js?ver=3', 'css_path' => $theme . 'grade-calculator.css' ),
	array( 'name' => 'GPA Scale', 'shortcode' => 'gpa-scale', 'js_path' => 'https://cdn.example/scale.js', 'css_path' => 'https://cdn.example/scale.css' ),
	array( 'name' => 'GPA Conversion', 'shortcode' => 'gpa_conversion', 'js_path' => 'https://cdn.example/conv.js', 'css_path' => 'https://cdn.example/conv.css' ),
	array( 'name' => 'Not moved', 'shortcode' => 'old-thing', 'js_path' => $theme . 'old-thing.js', 'css_path' => $theme . 'old-thing.css' ),
);

require WP_PLUGIN_DIR . '/gpacalculator-manager/includes/bootstrap.php';
GPACalc_Calculator_Assets::boot( WP_PLUGIN_DIR . '/gpacalculator-manager/gpacalculator-manager.php' );
require WP_PLUGIN_DIR . '/calcs-plugin/calcs-plugin.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; } else { $fail++; echo "FAIL $label: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
}
function reset_page( $id, $content ) {
	$GLOBALS['wps'] = new Deps(); $GLOBALS['wpst'] = new Deps(); $GLOBALS['page'] = array( 'id' => $id, 'content' => $content );
}
function norm( $html ) { return preg_replace( '/\s+/', ' ', trim( $html ) ); }
function plugin_url( $f ) { return 'https://site/wp-content/plugins/gpacalculator-manager/assets/calc-assets/' . $f; }

$calls = array(
	array( 'gpa-calculator', array() ),
	array( 'grade-calculator', array() ), // second generic one on the page: calcs-plugin printed nothing (include_once)
	array( 'gpa-scale', array( 'gpa' => '3.5', 'letter' => 'B+', 'percent' => '' ) ),
	array( 'gpa_conversion', array( 'country' => 'india' ) ),
	array( 'gpa-scale', array() ),
);
function run_calls( $calls ) {
	$out = array();
	foreach ( $calls as $c ) {
		$cb    = $GLOBALS['shortcode_tags'][ $c[0] ];
		$out[] = norm( (string) call_user_func( $cb, $c[1], '', $c[0] ) );
	}
	return $out;
}

// ---- Registry built from the saved list
$all = GPACalc_Registry::all();
check( 'one calculator per saved shortcode', array_keys( $all ), array( 'calcs-gpa-calculator', 'calcs-grade-calculator', 'calcs-gpa-scale', 'calcs-gpa_conversion', 'calcs-old-thing' ) );
check( 'file from calc-assets URL', array( $all['calcs-gpa-calculator']['js'], $all['calcs-grade-calculator']['js'], $all['calcs-gpa-scale']['js'] ), array( 'gpa-calculator.js', 'grade-calculator.js', '' ) );
check( 'old handles kept', array( $all['calcs-gpa-scale']['script_handle'], $all['calcs-gpa-scale']['style_handle'] ), array( 'main-js-gpa-scale', 'main-css-gpa-scale' ) );
check( 'types', array( $all['calcs-gpa-calculator']['type'], $all['calcs-grade-calculator']['type'], $all['calcs-gpa-scale']['type'] ), array( 'gpa', 'grade', 'grade-conversion' ) );

// ---- Phase 1: Calculators plugin active. It keeps every tag; the engine stays out.
calcs_plugin_register_shortcodes();
GPACalc_Shortcodes::register();
$owned = array_map( array( 'GPACalc_Shortcodes', 'owns' ), array( 'gpa-calculator', 'gpa-scale', 'gpa_conversion', 'old-thing' ) );
check( 'old plugin keeps all its tags', $owned, array( false, false, false, false ) );
reset_page( 30, '[gpa-calculator]' );
GPACalc_Calculator_Assets::enqueue();
check( 'engine loads nothing while old plugin is active', array( wp_scripts()->queue, wp_styles()->queue ), array( array(), array() ) );
check( 'coverage: all covered', GPACalc_Shortcodes::coverage(), array( 'calcs-plugin' => array( 'covered' => array( 'gpa-calculator', 'grade-calculator', 'gpa-scale', 'gpa_conversion', 'old-thing' ), 'missing' => array() ) ) );
ob_start(); GPACalc_Shortcodes::coverage_notice(); $notice = ob_get_clean();
check( 'notice: safe to deactivate', false !== strpos( $notice, '<strong>calcs-plugin</strong>: all 5 of its shortcodes are covered' ), true );

reset_page( 31, 'x' );
$legacy_html    = run_calls( $calls );
$legacy_scripts = wp_scripts()->queue;
$legacy_styles  = wp_styles()->queue;

// ---- Phase 2: Calculators plugin deactivated. Same tags, same markup, same handles.
foreach ( array( 'gpa-calculator', 'grade-calculator', 'gpa-scale', 'gpa_conversion', 'old-thing', 'calculators-united', 'calculator-derivative' ) as $t ) {
	remove_shortcode( $t );
}
GPACalc_Shortcodes::register();
check( 'engine takes over every tag', array_map( array( 'GPACalc_Shortcodes', 'owns' ), array( 'gpa-calculator', 'grade-calculator', 'gpa-scale', 'gpa_conversion', 'old-thing' ) ), array( true, true, true, true, true ) );
reset_page( 31, 'x' );
GPACalc_Legacy_Calcs::reset();
$engine_html = run_calls( $calls );
$prerender   = file_get_contents( $repo . '/plugin/gpacalculator-manager/assets/calc-assets/gpa-calculator.html' );
$want_html   = $legacy_html;
$want_html[0] = norm( '<div id="root">' . $prerender . '</div>' ); // plus the prerendered start, as the theme did
check( 'same markup as the old plugin', $engine_html, $want_html );
check( 'old plugin markup (sanity)', array_slice( $legacy_html, 0, 4 ), array( '<div id="root"></div>', '', '<div id="gpa-converter-app" data-default-gpa="3.5" data-default-letter="B+"></div>', '<div id="gpa-conversion-app" data-country="india"></div>' ) );
check( 'same script handles', wp_scripts()->queue, $legacy_scripts );
check( 'same style handles', wp_styles()->queue, $legacy_styles );
check( 'moved file served from the plugin', wp_scripts()->registered['main-js-gpa-calculator']->src, plugin_url( 'gpa-calculator.js' ) );
check( 'moved css served from the plugin', wp_styles()->registered['main-css-grade-calculator']->src, plugin_url( 'grade-calculator.css' ) );
check( 'external file keeps its URL', wp_scripts()->registered['main-js-gpa-scale']->src, 'https://cdn.example/scale.js' );

// Not moved: still loads from the theme URL.
reset_page( 32, 'x' );
call_user_func( $GLOBALS['shortcode_tags']['old-thing'], array(), '', 'old-thing' );
check( 'not-moved file keeps its theme URL', wp_scripts()->registered['main-js-old-thing']->src, $theme . 'old-thing.js' );

// Page with the shortcode: assets go in <head> at wp_enqueue_scripts.
reset_page( 33, 'Intro [gpa-calculator] more' );
GPACalc_Calculator_Assets::enqueue();
check( 'head enqueue for shortcode page', array( wp_scripts()->queue, wp_styles()->queue ), array( array( 'main-js-gpa-calculator' ), array( 'main-css-gpa-calculator' ) ) );
check( 'printed as module', false !== strpos( GPACalc_Calculator_Assets::module_tag( '<script src="' . plugin_url( 'gpa-calculator.js' ) . '" id="main-js-gpa-calculator-js"></script>', 'main-js-gpa-calculator' ), 'type="module"' ), true );

// Manifest override wins over the saved list.
GPACalc_Registry::reset();
$GLOBALS['filters']['gpacalc_calculators'] = function ( $list ) {
	$list['calcs-gpa-scale']['type'] = 'grade-conversion';
	$list['calcs-gpa-scale']['title'] = 'GPA Scale (manifest)';
	return $list;
};
check( 'filter can adjust a saved entry', GPACalc_Registry::get( 'calcs-gpa-scale' )['title'], 'GPA Scale (manifest)' );

echo "legacy_calcs_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
