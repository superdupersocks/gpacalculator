<?php
/**
 * Shortcodes for every registered calculator, including the old tags from Grades & GPA Plugin
 * and the theme, so Grades & GPA Plugin can be deactivated with no page edits.
 *
 * A tag is only taken when no other plugin has registered it, so while an old plugin is
 * still active it keeps serving its own shortcodes. Once it's deactivated, the engine
 * answers the same tags on the next page load.
 *
 * Output: <div class="gpacalc-mount" data-calc="slug" data-atts="{...}"></div>, which the
 * calculator's JS mounts into (core: mountsFor(slug)). A calculator can provide 'render'
 * for server-rendered HTML instead.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Shortcodes' ) ) {

	final class GPACalc_Shortcodes {

		public static function boot() {
			// After plugins register theirs on init (default priority 10).
			add_action( 'init', array( __CLASS__, 'register' ), 20 );
			add_action( 'admin_notices', array( __CLASS__, 'coverage_notice' ) );
		}

		public static function register() {
			foreach ( GPACalc_Registry::all() as $calc ) {
				foreach ( $calc['shortcodes'] as $tag ) {
					if ( ! shortcode_exists( $tag ) ) {
						add_shortcode( $tag, array( __CLASS__, 'render' ) );
					}
				}
			}
		}

		/** True when the engine (not an old plugin) answers this tag. */
		public static function owns( $tag ) {
			global $shortcode_tags;
			return isset( $shortcode_tags[ $tag ] ) && array( __CLASS__, 'render' ) === $shortcode_tags[ $tag ];
		}

		public static function render( $atts, $content = '', $tag = '' ) {
			$calc = GPACalc_Registry::for_shortcode( $tag );
			if ( ! $calc ) {
				return '';
			}
			$atts = array_merge( (array) $calc['atts'], is_array( $atts ) ? $atts : array() );
			GPACalc_Calculator_Assets::require_calc( $calc );
			if ( is_callable( $calc['render'] ) ) {
				return (string) call_user_func( $calc['render'], $atts, $content, $tag );
			}
			return sprintf(
				'<div class="gpacalc-mount" data-calc="%s" data-atts="%s"></div>',
				esc_attr( $calc['slug'] ),
				esc_attr( wp_json_encode( (object) $atts ) )
			);
		}

		/**
		 * Which plugins still serve shortcodes the engine can take over.
		 * Returns plugin folder => array( 'covered' => tags, 'missing' => tags ).
		 * 'covered': the engine has the calculator and its files; 'missing': not ported yet.
		 */
		public static function coverage() {
			global $shortcode_tags;
			$out = array();
			foreach ( (array) $shortcode_tags as $tag => $cb ) {
				if ( array( __CLASS__, 'render' ) === $cb ) {
					continue;
				}
				$plugin = self::plugin_of( $cb );
				if ( ! $plugin || 'gpacalculator-manager' === $plugin ) {
					continue;
				}
				$calc = GPACalc_Registry::for_shortcode( $tag );
				$key  = ( $calc && GPACalc_Calculator_Assets::is_moved( $calc ) ) ? 'covered' : 'missing';
				if ( ! isset( $out[ $plugin ] ) ) {
					$out[ $plugin ] = array( 'covered' => array(), 'missing' => array() );
				}
				$out[ $plugin ][ $key ][] = $tag;
			}
			return $out;
		}

		/** Plugin folder that defines a callback, or '' for core/theme/unknown. */
		private static function plugin_of( $cb ) {
			try {
				if ( is_array( $cb ) ) {
					$ref = new ReflectionMethod( $cb[0], $cb[1] );
				} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
					$ref = new ReflectionMethod( $cb );
				} else {
					$ref = new ReflectionFunction( $cb );
				}
				$file = wp_normalize_path( (string) $ref->getFileName() );
			} catch ( Exception $e ) {
				return '';
			}
			$dir = wp_normalize_path( WP_PLUGIN_DIR ) . '/';
			if ( 0 !== strpos( $file, $dir ) ) {
				return '';
			}
			$rel = substr( $file, strlen( $dir ) );
			return false === strpos( $rel, '/' ) ? '' : strstr( $rel, '/', true );
		}

		/** On the Plugins screen: which calculator plugins can be deactivated with no page edits. */
		public static function coverage_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'plugins' !== $screen->id ) {
				return;
			}
			self::register(); // Make sure our side is counted even if init order changed.
			foreach ( self::coverage() as $plugin => $c ) {
				if ( ! $c['covered'] && ! $c['missing'] ) {
					continue;
				}
				if ( ! $c['covered'] ) {
					continue; // Not a calculator plugin we're replacing.
				}
				if ( $c['missing'] ) {
					printf(
						'<div class="notice notice-warning"><p><strong>%s</strong>: GPA Calculator Manager covers %d of its shortcodes. Keep it active until these are ported: %s</p></div>',
						esc_html( $plugin ),
						count( $c['covered'] ),
						esc_html( '[' . implode( '], [', $c['missing'] ) . ']' )
					);
				} else {
					printf(
						'<div class="notice notice-success"><p><strong>%s</strong>: all %d of its shortcodes are covered by GPA Calculator Manager. You can deactivate it; no page edits needed.</p></div>',
						esc_html( $plugin ),
						count( $c['covered'] )
					);
				}
			}
		}
	}

	GPACalc_Shortcodes::boot();
}
