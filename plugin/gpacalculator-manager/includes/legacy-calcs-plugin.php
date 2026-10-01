<?php
/**
 * Calculators plugin (calcs-plugin) merged in: its shortcodes become engine calculators.
 *
 * calcs-plugin keeps its shortcode list in the 'calcs_plugin_shortcodes' option (name, shortcode,
 * js_path, css_path), edited under WP admin > Calculators. That option stays in the database when
 * the plugin is deactivated, so the engine reads it and answers every one of those shortcodes
 * with the same markup, the same script/style handles (main-js-<tag>, main-css-<tag>) and:
 *  - the plugin's own copy of the file when it is in assets/calc-assets/ (same filename), or
 *  - the original js_path/css_path URL otherwise (theme calc-assets, external hosts).
 * So deactivating calcs-plugin needs no page edits.
 *
 * While calcs-plugin is active it registers these tags first and the engine stays out of the way
 * (see GPACalc_Shortcodes::register()).
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Legacy_Calcs' ) ) {

	final class GPACalc_Legacy_Calcs {

		const OPTION = 'calcs_plugin_shortcodes';

		/** @var bool calcs-plugin printed <div id="root"> once per page (include_once). */
		private static $root_printed = false;

		public static function boot() {
			add_filter( 'gpacalc_calculators', array( __CLASS__, 'calculators' ), 5 );
		}

		/** calcs-plugin's own fallback when the option was never saved. */
		public static function defaults() {
			return array(
				array(
					'name'      => 'Calculators United',
					'shortcode' => 'calculators-united',
					'js_path'   => 'https://percentager-calculators.netlify.app/assets/index-Dn-eo0qD.js',
					'css_path'  => 'https://percentager-calculators.netlify.app/assets/index-CjYIunhr.css',
				),
				array(
					'name'      => 'Calculator Derivative',
					'shortcode' => 'calculator-derivative',
					'js_path'   => 'https://derivativecalculator.netlify.app/assets/index-BTYI-ye5.js',
					'css_path'  => 'https://derivativecalculator.netlify.app/assets/index-CNmS3_Uq.css',
				),
			);
		}

		public static function entries() {
			$list = get_option( self::OPTION, self::defaults() );
			return is_array( $list ) ? $list : array();
		}

		/** Add one calculator per calcs-plugin shortcode. Manifest entries with the same slug win. */
		public static function calculators( $list ) {
			foreach ( self::entries() as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['shortcode'] ) ) {
					continue;
				}
				$tag  = trim( (string) $entry['shortcode'], " []\t\n" );
				$slug = 'calcs-' . sanitize_key( $tag );
				if ( '' === $tag || isset( $list[ $slug ] ) ) {
					continue;
				}
				$js_src  = isset( $entry['js_path'] ) ? (string) $entry['js_path'] : '';
				$css_src = isset( $entry['css_path'] ) ? (string) $entry['css_path'] : '';
				$js      = self::calc_asset_file( $js_src );

				$list[ $slug ] = array(
					'title'         => isset( $entry['name'] ) ? (string) $entry['name'] : $tag,
					'type'          => self::guess_type( $tag . ' ' . $js ),
					'source'        => 'calcs-plugin',
					'js'            => $js,
					'css'           => self::calc_asset_file( $css_src ),
					'js_src'        => $js_src,
					'css_src'       => $css_src,
					'script_handle' => 'main-js-' . self::handle_suffix( $tag ),
					'style_handle'  => 'main-css-' . self::handle_suffix( $tag ),
					'shortcodes'    => array( $tag ),
					'render'        => self::renderer( $tag ),
				);
			}
			return $list;
		}

		/** calcs-plugin used 'main-js-gpa-conversion' for [gpa_conversion]; every other tag as-is. */
		public static function handle_suffix( $tag ) {
			return 'gpa_conversion' === $tag ? 'gpa-conversion' : $tag;
		}

		/** "…/calc-assets/gpa-calculator.js?ver=1" -> "gpa-calculator.js"; anything else -> ''. */
		public static function calc_asset_file( $url ) {
			if ( preg_match( '#/calc-assets/([A-Za-z0-9._-]+\.(?:js|css))(?:[?\#]|$)#', (string) $url, $m ) ) {
				return $m[1];
			}
			return '';
		}

		public static function guess_type( $text ) {
			$t = strtolower( $text );
			if ( false !== strpos( $t, 'scale' ) || false !== strpos( $t, 'conversion' ) ) {
				return 'grade-conversion';
			}
			if ( false !== strpos( $t, 'sgpa' ) || false !== strpos( $t, 'cgpa' ) ) {
				return 'university-gpa';
			}
			if ( false !== strpos( $t, 'gpa' ) ) {
				return 'gpa';
			}
			if ( false !== strpos( $t, 'grade' ) ) {
				return 'grade';
			}
			return 'other';
		}

		/** Same markup calcs-plugin printed for this tag. */
		public static function renderer( $tag ) {
			if ( 'gpa-scale' === $tag ) {
				return array( __CLASS__, 'render_gpa_scale' );
			}
			if ( 'gpa_conversion' === $tag ) {
				return array( __CLASS__, 'render_gpa_conversion' );
			}
			return array( __CLASS__, 'render_root' );
		}

		/**
		 * Generic calculators mount into <div id="root">. calcs-plugin printed it with include_once,
		 * so only the first calculator shortcode on a page gets one; that's kept. When the plugin has a
		 * prerendered <name>.html next to the calculator, it's printed inside (the theme's prerender
		 * does the same for the old path).
		 */
		public static function render_root( $atts, $content = '', $tag = '' ) {
			if ( self::$root_printed ) {
				return '';
			}
			self::$root_printed = true;
			$markup = '';
			$calc   = GPACalc_Registry::for_shortcode( $tag );
			if ( $calc && $calc['js'] ) {
				$html = GPACalc_Calculator_Assets::path( preg_replace( '/\.js$/', '.html', $calc['js'] ) );
				if ( is_readable( $html ) ) {
					$markup = (string) file_get_contents( $html );
				}
			}
			return '<div id="root">' . $markup . "</div>\n";
		}

		public static function render_gpa_scale( $atts ) {
			$atts = shortcode_atts( array( 'gpa' => '', 'letter' => '', 'percent' => '' ), $atts, 'gpa-scale' );
			$data = '';
			foreach ( array( 'gpa' => 'data-default-gpa', 'letter' => 'data-default-letter', 'percent' => 'data-default-percent' ) as $key => $attr ) {
				if ( ! empty( $atts[ $key ] ) ) {
					$data .= ' ' . $attr . '="' . esc_attr( $atts[ $key ] ) . '"';
				}
			}
			return '<div id="gpa-converter-app" ' . $data . "></div>\n";
		}

		public static function render_gpa_conversion( $atts ) {
			$atts = shortcode_atts( array( 'country' => '' ), $atts, 'gpa_conversion' );
			$data = empty( $atts['country'] ) ? '' : ' data-country="' . esc_attr( $atts['country'] ) . '"';
			return '<div id="gpa-conversion-app" ' . $data . "></div>\n";
		}

		/** Test helper. */
		public static function reset() {
			self::$root_printed = false;
		}
	}

	GPACalc_Legacy_Calcs::boot();
}
