<?php
/**
 * Grade + GPA's own [gpcm_calculator id="..."] keeps working with the engine loaded.
 *
 * University profiles live in the gpcm_university_profiles option on the site (Stanford is also
 * bundled), so this checks every live profile id from tests/fixtures/gpcm-university-profiles.tsv
 * renders through the plugin's own shortcode, and that the engine never takes the tag.
 * Run: php tests/php/gpcm_profiles_test.php
 */
ini_set( 'error_log', '/dev/null' );
$repo = dirname( __DIR__, 2 );
$tmp  = sys_get_temp_dir() . '/gpacalc-gpcm-' . getmypid();
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

$ids = array();
foreach ( file( $repo . '/tests/fixtures/gpcm-university-profiles.tsv' ) as $line ) {
	if ( '' === trim( $line ) || '#' === $line[0] ) { continue; }
	$cols  = explode( "\t", rtrim( $line, "\n" ) );
	$ids[] = $cols[1];
}
// What the site's option holds (minimal stand-ins; the real rules stay in the database).
$profiles = array();
foreach ( $ids as $id ) {
	if ( 'stanford' !== $id ) { $profiles[ $id ] = array( 'id' => $id, 'schemaVersion' => 1 ); }
}
$GLOBALS['options']['gpcm_university_profiles'] = $profiles;
$GLOBALS['options']['calcs_plugin_shortcodes']  = array();

require WP_PLUGIN_DIR . '/gpacalculator-manager/gpacalculator-manager.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; } else { $fail++; echo "FAIL $label: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
}

// init: the plugin registers its tag at priority 10, the engine runs at 20.
gpcm_register_shortcode();
GPACalc_Shortcodes::register();
check( 'live profile ids', count( $ids ), 87 );
check( 'plugin keeps [gpcm_calculator]', $GLOBALS['shortcode_tags']['gpcm_calculator'], 'gpcm_shortcode' );
check( 'engine does not own it', GPACalc_Shortcodes::owns( 'gpcm_calculator' ), false );
check( 'stanford bundled', is_array( gpcm_builtin_stanford() ), true );

$broken = array();
foreach ( $ids as $id ) {
	$GLOBALS['wps'] = new Deps();
	$html = call_user_func( $GLOBALS['shortcode_tags']['gpcm_calculator'], array( 'id' => $id ), '', 'gpcm_calculator' );
	if ( false === strpos( $html, 'data-gpcm-profile-id="' . $id . '"' ) || ! in_array( 'gpcm-shared-engine', wp_scripts()->queue, true ) ) {
		$broken[] = $id;
	}
}
check( 'every live profile renders', $broken, array() );

echo "gpcm_profiles_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
