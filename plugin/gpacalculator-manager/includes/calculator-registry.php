<?php
/**
 * Calculator registry: the list of calculator types the engine serves.
 *
 * Entries come from includes/calculators.php and the 'gpacalc_calculators' filter.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Registry' ) ) {

	final class GPACalc_Registry {

		/** Calculator types. */
		const TYPES = array(
			'gpa'              => 'GPA (high school, college, cumulative)',
			'university-gpa'   => 'University GPA (institution-specific scales)',
			'grade'            => 'Grade (weighted categories, final exam)',
			'grade-conversion' => 'Grade conversion (country and system scales)',
			'other'            => 'Other',
		);

		const DEFAULTS = array(
			'title'         => '',
			'type'          => 'other',
			'source'        => 'new',
			'js'            => '',
			'css'           => '',
			'js_src'        => '',
			'css_src'       => '',
			'script_handle' => '',
			'style_handle'  => '',
			'shortcodes'    => array(),
			'page_ids'      => array(),
			'atts'          => array(),
			'render'        => null,
		);

		/** @var array|null */
		private static $all = null;

		/** @var array Extra entries added with register(). */
		private static $extra = array();

		public static function register( $slug, array $args ) {
			self::$extra[ sanitize_key( $slug ) ] = $args;
			self::$all = null;
		}

		/** All calculators, normalized: slug => args. */
		public static function all() {
			if ( null === self::$all ) {
				$manifest = include __DIR__ . '/calculators.php';
				$list     = apply_filters( 'gpacalc_calculators', array_merge( (array) $manifest, self::$extra ) );
				self::$all = array();
				foreach ( (array) $list as $slug => $args ) {
					$c = array_merge( self::DEFAULTS, (array) $args );
					$c['slug']          = $slug;
					$c['shortcodes']    = array_values( array_filter( (array) $c['shortcodes'] ) );
					$c['page_ids']      = array_map( 'intval', (array) $c['page_ids'] );
					$c['script_handle'] = $c['script_handle'] ? $c['script_handle'] : 'gpacalc-' . $slug;
					$c['style_handle']  = $c['style_handle'] ? $c['style_handle'] : 'gpacalc-' . $slug;
					self::$all[ $slug ] = $c;
				}
			}
			return self::$all;
		}

		public static function get( $slug ) {
			$all = self::all();
			return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
		}

		/** Calculator that answers to a shortcode tag, or null. */
		public static function for_shortcode( $tag ) {
			foreach ( self::all() as $c ) {
				if ( in_array( $tag, $c['shortcodes'], true ) ) {
					return $c;
				}
			}
			return null;
		}

		/** Test helper. */
		public static function reset() {
			self::$all   = null;
			self::$extra = array();
		}
	}
}

if ( ! function_exists( 'gpacalc_register_calculator' ) ) {
	/** Register a calculator from code (same args as includes/calculators.php). */
	function gpacalc_register_calculator( $slug, array $args ) {
		GPACalc_Registry::register( $slug, $args );
	}
}
