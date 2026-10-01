<?php
/**
 * Enqueue the site-wide brand tokens (brand-tokens.css).
 * Loaded from functions.php with: require_once get_stylesheet_directory() . '/inc/brand-tokens.php';
 * The gpacalculator-manager plugin's calculator styles depend on the 'gpa-brand-tokens' handle
 * when it is registered, so tokens always print before calculator CSS.
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	function () {
		$file = get_stylesheet_directory() . '/brand-tokens.css';
		if ( ! file_exists( $file ) ) {
			return;
		}
		wp_enqueue_style(
			'gpa-brand-tokens',
			get_stylesheet_directory_uri() . '/brand-tokens.css',
			array(),
			(string) filemtime( $file )
		);
	},
	5
);
