<?php
/**
 * mu-plugins/gpacalc-security-hotfix.php blocks CollegeDB's logged-out link rewrite and
 * UniversityTemplate's ?update_universities trigger, and leaves the public search alone.
 * Run: php tests/php/security_hotfix_test.php
 */
define( 'ABSPATH', '/' );
$GLOBALS['hooks'] = array();
function add_action( $hook, $cb, $prio = 10 ) { $GLOBALS['hooks'][ $hook ][] = $cb; }
function wp_doing_ajax() { return true; }
function current_user_can( $cap ) { return ! empty( $GLOBALS['admin'] ); }
class Died extends Exception {}
function wp_die( $msg, $title = '', $args = array() ) { throw new Died( (string) $args['response'] ); }

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	if ( $got === $want ) { $pass++; } else { $fail++; echo "FAIL $label: got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n"; }
}
function run( $action, $admin ) {
	$_REQUEST = array( 'action' => $action );
	$GLOBALS['admin'] = $admin;
	try { gpacalc_hotfix_block_cdb_change_url(); return 'allowed'; } catch ( Died $e ) { return 'blocked ' . $e->getMessage(); }
}

$_GET = $_REQUEST = array( 'update_universities' => '1' );
require dirname( __DIR__, 2 ) . '/mu-plugins/gpacalc-security-hotfix.php';
check( 'update_universities dropped', array( isset( $_GET['update_universities'] ), isset( $_REQUEST['update_universities'] ) ), array( false, false ) );
check( 'hooked on admin_init', isset( $GLOBALS['hooks']['admin_init'] ), true );
check( 'logged-out link rewrite blocked', run( 'cdb_change_url', false ), 'blocked 403' );
check( 'admin can still edit links', run( 'cdb_change_url', true ), 'allowed' );
check( 'public search untouched', run( 'cdb_search', false ), 'allowed' );

echo "security_hotfix_test: $pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
