<?php
/**
 * Template Name: GPA – Database Archive
 *
 * Reusable page template for pages that present the college database in an
 * archive/listing layout (e.g. the /admission/ landing page), styled like
 * the colleges custom-post-type archive.
 *
 * Renders identically to the default GeneratePress page template — it
 * delegates to the parent theme's page.php — but tags <body> with
 * `gpa-template-database-archive` so CSS can scope to this template
 * regardless of the page slug.
 *
 * NOTE: database CSS/JS enqueue and the related layout classes are governed
 * by gpa_is_database_page() in functions.php, which is made
 * is_page_template()-aware in T7.2. Until then this template only emits its
 * marker class.
 *
 * @package GeneratePress Child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'gpa-template-database-archive';
	return $classes;
} );

require get_template_directory() . '/page.php';
