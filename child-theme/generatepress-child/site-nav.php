<?php
/**
 * Site header and footer navigation (Digant's reorganization, 2026-10-03).
 *
 * The links themselves are WordPress menus (Appearance > Menus), set by scripts/wp/nav_reorg.php:
 *   primary            header: GPA Calculators ▾, Grade Calculators ▾, GPA Scale, Grade Conversion, Colleges
 *   footer-1 … footer-5  one Navigation Menu widget per column
 *   gpa-footer-legal   bottom bar: About · Contact · Privacy · Terms · Data sources
 *
 * This file only shapes the markup:
 *   - each footer column is a <details> with its title as the <summary>. The server prints it open, so
 *     every link is in the HTML and visible without JavaScript; on phones (≤768px) site-nav.js closes the
 *     columns before the footer is painted. Nothing is hidden with display:none.
 *   - the header's dropdown parents ("#" links) get button semantics and aria-expanded (site-nav.js keeps it
 *     in sync and adds Enter/Space/Escape).
 *   - the copyright line becomes the bottom bar.
 *   - the header menu keeps the id "menu-top-nav" whichever menu is assigned, so layout.css still applies.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_setup_theme', function () {
	register_nav_menus( array( 'gpa-footer-legal' => 'Footer bottom bar' ) );
}, 20 );

add_action( 'wp_enqueue_scripts', function () {
	$dir = get_stylesheet_directory();
	$uri = get_stylesheet_directory_uri();
	wp_enqueue_style( 'gpa-site-nav', $uri . '/site-nav.css', array(), (string) filemtime( $dir . '/site-nav.css' ) );
	wp_enqueue_script( 'gpa-site-nav', $uri . '/site-nav.js', array(), (string) filemtime( $dir . '/site-nav.js' ), true );
}, 30 );

/** Header menu: same ul id as before, whichever menu is assigned. */
add_filter( 'wp_nav_menu_args', function ( $args ) {
	if ( isset( $args['theme_location'] ) && 'primary' === $args['theme_location'] ) {
		$args['menu_id'] = 'menu-top-nav';
	}
	return $args;
} );

/** Header dropdown parents: a "#" link that opens a sub-menu is a button for assistive tech. */
add_filter( 'nav_menu_link_attributes', function ( $atts, $item, $args, $depth ) {
	if ( 0 !== (int) $depth || ! isset( $args->theme_location ) || 'primary' !== $args->theme_location ) {
		return $atts;
	}
	if ( ! in_array( 'menu-item-has-children', (array) $item->classes, true ) ) {
		return $atts;
	}
	$atts['aria-haspopup'] = 'true';
	$atts['aria-expanded'] = 'false';
	if ( isset( $atts['href'] ) && '#' === $atts['href'] ) {
		$atts['role'] = 'button';
	}
	return $atts;
}, 10, 4 );

/** Footer columns: <aside> → <details open>, widget title → <summary>. */
add_filter( 'dynamic_sidebar_params', function ( $params ) {
	if ( is_admin() || empty( $params[0]['id'] ) || ! preg_match( '/^footer-[1-5]$/', $params[0]['id'] ) ) {
		return $params;
	}
	if ( empty( $params[0]['widget_id'] ) || 0 !== strpos( $params[0]['widget_id'], 'nav_menu-' ) ) {
		return $params;
	}
	$p = &$params[0];
	$p['before_widget'] = preg_replace( '/^<aside\b/', '<details', $p['before_widget'] );
	$p['before_widget'] = preg_replace( '/class="([^"]*)"/', 'class="$1 gpa-foot-col" open', $p['before_widget'], 1 );
	$p['after_widget']  = preg_replace( '#</aside>\s*$#', '</details>', $p['after_widget'] );
	$p['before_title']  = '<summary class="gpa-foot-col__summary">' . $p['before_title'];
	$p['after_title']   = $p['after_title'] . '</summary>';
	return $params;
} );

/** Bottom bar: the footer-legal menu inline, then © year. Replaces whatever printed the old copyright line. */
add_action( 'wp', function () {
	if ( is_admin() ) {
		return;
	}
	remove_all_actions( 'generate_credits' );
	add_action( 'generate_credits', 'gpa_footer_bottom_bar' );
	// The Freestar privacy link (#pmLink) moves into the bottom bar; drop the stand-alone copy under the footer.
	remove_action( 'wp_footer', 'gpa_freestar_cmp_link', 20 );
}, 99 );

function gpa_footer_bottom_bar() {
	$links = has_nav_menu( 'gpa-footer-legal' ) ? wp_nav_menu( array(
		'theme_location' => 'gpa-footer-legal',
		'container'      => false,
		'menu_class'     => 'gpa-legal__list',
		'menu_id'        => 'gpa-legal',
		'depth'          => 1,
		'fallback_cb'    => false,
		'echo'           => false,
	) ) : '';
	// #pmLink is Freestar's consent button: its script finds it by id, sets the label for the visitor's region
	// ("Do Not Sell or Share My Personal Information" in the US) and shows it with an inline visibility: visible.
	echo '<nav class="gpa-legal" aria-label="Site information">' . $links
		. '<button type="button" id="pmLink" class="gpa-legal__pm">Privacy Manager</button>'
		. '<span class="gpa-legal__copy">&copy; ' . esc_html( wp_date( 'Y' ) ) . ' GPA Calculator</span></nav>';
}
