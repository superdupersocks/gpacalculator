<?php
/**
 * Template Name: GPA – Content Page
 *
 * Reusable page template for long-form content pages (GPA scale, grade
 * conversion, how-to guides, etc.).
 *
 * Renders identically to the default GeneratePress page template — it
 * delegates to the parent theme's page.php — but tags <body> with
 * `gpa-template-content` so CSS can scope to this template regardless of
 * the page slug.
 *
 * NOTE: the `content-page` layout class and content CSS are governed by
 * gpa_is_content_page() in functions.php, which is made
 * is_page_template()-aware in T7.2. Until then this template only emits
 * its marker class.
 *
 * @package GeneratePress Child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'gpa-template-content';
	return $classes;
} );

require get_template_directory() . '/page.php';
