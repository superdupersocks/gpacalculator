<?php
/**
 * Calculator engine bootstrap. The main plugin file loads it with:
 *   require_once __DIR__ . '/includes/bootstrap.php';
 *
 * gpacalculator-manager ("Grade + GPA" in WP admin) is the one plugin for every calculator on the
 * site. The theme's calc-assets calculators and the Calculators plugin's shortcodes
 * (legacy-calcs-plugin.php) are served here as calculator types and keep their old shortcodes.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/calculator-registry.php';
require_once __DIR__ . '/calculator-assets.php';
require_once __DIR__ . '/shortcodes.php';
require_once __DIR__ . '/legacy-calcs-plugin.php';
require_once __DIR__ . '/calc-switch.php';
