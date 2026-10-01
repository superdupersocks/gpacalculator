<?php
/**
 * Calculator manifest: every calculator the plugin serves, keyed by slug.
 *
 *   'slug' => array(
 *     'title'         => 'High School GPA Calculator',
 *     'type'          => 'gpa',          // see GPACalc_Registry::TYPES
 *     'source'        => 'theme',        // where it came from: theme | gpacalculator-manager | calc-plugin | grades-gpa-plugin | new
 *     'js'            => 'hs-gpa.js',    // in assets/calc-assets/
 *     'css'           => 'hs-gpa.css',
 *     'script_handle' => 'hs-gpa',       // the handle the theme or old plugin used, if any
 *     'style_handle'  => 'hs-gpa-css',
 *     'shortcodes'    => array( 'hs_gpa_calculator' ), // every tag it answered to, old names included
 *     'page_ids'      => array(),        // pages that load it without a shortcode
 *     'atts'          => array(),        // shortcode attribute defaults passed to the JS
 *     'render'        => null,           // optional callable( $atts, $content, $tag ) for server-rendered HTML
 *   ),
 *
 * Handles: calculators moved from the THEME reuse the theme's handles (the theme keeps enqueueing
 * them during the move). Calculators ported from Calc Plugin or Grades & GPA Plugin leave the
 * handles empty (gpacalc-<slug>), so the new JS never lands on markup the old plugin printed
 * while it's still active.
 *
 * Filled in as calculators move over. Never drop a shortcode from an entry.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

return array();
