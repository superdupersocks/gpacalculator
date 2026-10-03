<?php
/**
 * ARCHIVED 2026-09-07 - this template is no longer offered.
 *
 * The Template Name header below was removed so WordPress stops
 * listing it in Page Attributes. All 48 pages that used it (42 live,
 * 2 found beyond the first page of results, 4 in the trash) were moved
 * to gpa-calculator-tool first, so nothing references it.
 *
 * was: Template Name: GPA - Calculator Page
 *
 * Reusable page template for GPA calculator pages.
 *
 * Renders identically to the default GeneratePress page template — it
 * delegates to the parent theme's page.php — but tags <body> with
 * `gpa-template-calculator` so CSS can scope to this template regardless
 * of the page slug.
 *
 * NOTE: functional detection (calculator CSS/JS enqueue and the
 * `featured-image-active` layout class) is keyed off gpa_is_calculator_page()
 * in functions.php, which is made is_page_template()-aware in T7.2. Until
 * then this template only emits its marker class.
 *
 * @package GeneratePress Child
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'gpa-template-calculator';
	return $classes;
} );

require get_template_directory() . '/page.php';
