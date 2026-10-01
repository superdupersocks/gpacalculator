<?php
/**
 * Calculator manifest: every calculator the plugin serves, keyed by slug.
 *
 *   'slug' => array(
 *     'title'         => 'High School GPA Calculator',
 *     'type'          => 'gpa',          // see GPACalc_Registry::TYPES
 *     'source'        => 'theme',        // where it came from: theme | gpacalculator-manager | calcs-plugin | new
 *     'js'            => 'hs-gpa.js',    // in assets/calc-assets/
 *     'css'           => 'hs-gpa.css',
 *     'js_src'        => '',             // original URL to load when the plugin has no copy
 *     'css_src'       => '',
 *     'script_handle' => 'hs-gpa',       // the handle the theme or old plugin used, if any
 *     'style_handle'  => 'hs-gpa-css',
 *     'shortcodes'    => array( 'hs_gpa_calculator' ), // every tag it answered to, old names included
 *     'page_ids'      => array(),        // pages that load it without a shortcode
 *     'atts'          => array(),        // shortcode attribute defaults passed to the JS
 *     'render'        => null,           // optional callable( $atts, $content, $tag ) for server-rendered HTML
 *   ),
 *
 * Handles: calculators moved from the THEME reuse the theme's handles (the theme keeps enqueueing
 * them during the move). Calculators taken over from other plugins leave the
 * handles empty (gpacalc-<slug>), so the new JS never lands on markup the old plugin printed
 * while it's still active.
 *
 * Every Calculators-plugin shortcode is added automatically from its saved settings
 * (legacy-calcs-plugin.php); an entry here with slug 'calcs-<tag>' overrides one of those.
 *
 * Filled in as calculators move over. Never drop a shortcode from an entry.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

return array();
