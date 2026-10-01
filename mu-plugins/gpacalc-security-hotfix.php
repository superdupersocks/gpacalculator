<?php
/**
 * Plugin Name: gpacalculator.net security hotfix
 * Description: Closes two holes in old plugins until they are retired: CollegeDB's logged-out link rewrite and UniversityTemplate's ?update_universities bulk SEO rewrite. Delete this file to undo.
 * Version: 1.0.0
 *
 * Install: copy to wp-content/mu-plugins/ (must-use plugins load before every normal plugin).
 */

defined( 'ABSPATH' ) || exit;

// UniversityTemplate runs its bulk rewrite while it loads, before users are known, so the trigger
// is dropped for every request. Nobody uses it (no page has [UniversityTemplate]).
unset( $_GET['update_universities'], $_REQUEST['update_universities'] );

// CollegeDB answers cdb_change_url for logged-out visitors (wp_ajax_nopriv_). admin_init runs in
// admin-ajax.php before the action, so stop it there unless the user can manage the site.
// Searching (cdb_search) stays open: the public tables use it.
function gpacalc_hotfix_block_cdb_change_url() {
	if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'cdb_change_url' === $_REQUEST['action'] &&
		! current_user_can( 'manage_options' ) ) {
		wp_die( 'Forbidden', '', array( 'response' => 403 ) );
	}
}
add_action( 'admin_init', 'gpacalc_hotfix_block_cdb_change_url', 0 );
