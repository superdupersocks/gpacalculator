<?php
/**
 * Calculator engine bootstrap. The main plugin file loads it with:
 *   require_once __DIR__ . '/includes/bootstrap.php';
 *
 * gpacalculator-manager (also called "Calc Plugin") is the one plugin for every calculator on the
 * site. Its own calculators, the theme's and Grades & GPA Plugin's are registered here as
 * calculator types (includes/calculators.php) and keep their old shortcodes.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/calculator-registry.php';
require_once __DIR__ . '/calculator-assets.php';
require_once __DIR__ . '/shortcodes.php';
