<?php
/**
 * Calculator assets: serve calculator JS/CSS from this plugin instead of the theme.
 *
 * Calculators live in assets/calc-assets/ with the same filenames they had in
 * generatepress-child/calc-assets/, so relative ES module imports (./core/calc-core.js)
 * keep working. Pages, page URLs and shortcodes don't change.
 *
 * How a calculator moves from the theme:
 *  1. Copy its JS/CSS into assets/calc-assets/ (same filenames).
 *  2. Add it to calculators() with the script/style handles the theme already uses.
 * On every page where those handles are enqueued, the loader repoints the same handle at the
 * plugin file. Dependencies, footer placement, localized data and inline scripts stay
 * attached to the handle, so the page behaves exactly as before. If a plugin file is missing,
 * the theme's file keeps being used. Calculators can also load by shortcode or page ID.
 *
 * Loaded from the main plugin file with:
 *   require_once __DIR__ . '/includes/calculator-assets.php';
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Calculator_Assets' ) ) {

	final class GPACalc_Calculator_Assets {

		const DIR           = 'assets/calc-assets/';
		const TOKENS_HANDLE  = 'gpa-brand-tokens';

		/** @var string Any path in the plugin root; plugins_url() only uses its directory. */
		private static $root_file = '';

		/** @var string[] Script handles served by this loader (printed as type="module"). */
		private static $module_handles = array();

		public static function boot( $root_file ) {
			self::$root_file = $root_file;
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 999 );
			add_filter( 'script_loader_tag', array( __CLASS__, 'module_tag' ), 20, 2 );
		}

		/**
		 * Registry. slug => array(
		 *   'js'            => 'grade-calculator.js',   // file in assets/calc-assets/
		 *   'css'           => 'grade-calculator.css',
		 *   'script_handle' => 'theme-handle',          // the handle the theme enqueues today
		 *   'style_handle'  => 'theme-handle-css',
		 *   'shortcodes'    => array( 'grade_calculator' ), // optional: also load where used
		 *   'page_ids'      => array( 123 ),            // optional: also load on these pages
		 * )
		 * Filled in as calculators move over from the theme.
		 */
		public static function calculators() {
			$calculators = array();
			return (array) apply_filters( 'gpacalc_calculators', $calculators );
		}

		public static function path( $file ) {
			return dirname( self::$root_file ) . '/' . self::DIR . ltrim( $file, '/' );
		}

		public static function url( $file ) {
			return plugins_url( self::DIR . ltrim( $file, '/' ), self::$root_file );
		}

		public static function enqueue() {
			foreach ( self::calculators() as $slug => $calc ) {
				$wanted = self::wanted_here( $calc );
				if ( ! empty( $calc['js'] ) ) {
					self::serve( 'script', $calc['js'], isset( $calc['script_handle'] ) ? $calc['script_handle'] : 'gpacalc-' . $slug, $wanted );
				}
				if ( ! empty( $calc['css'] ) ) {
					self::serve( 'style', $calc['css'], isset( $calc['style_handle'] ) ? $calc['style_handle'] : 'gpacalc-' . $slug, $wanted );
				}
			}
		}

		/**
		 * Point $handle at the plugin copy of $file. Only touches pages where the theme already
		 * enqueued the handle, or where the registry asks for it ($wanted).
		 */
		private static function serve( $kind, $file, $handle, $wanted ) {
			$path = self::path( $file );
			if ( ! is_readable( $path ) ) {
				return; // Not moved yet: the theme copy stays in use.
			}
			$deps = 'script' === $kind ? wp_scripts() : wp_styles();
			$queued = 'script' === $kind ? wp_script_is( $handle, 'enqueued' ) : wp_style_is( $handle, 'enqueued' );
			if ( ! $queued && ! $wanted ) {
				return;
			}
			$src = self::url( $file );
			$ver = (string) filemtime( $path );

			if ( isset( $deps->registered[ $handle ] ) ) {
				// Same handle, new source: deps, footer group, localize data and inline code are kept.
				$deps->registered[ $handle ]->src = $src;
				$deps->registered[ $handle ]->ver = $ver;
			} elseif ( 'script' === $kind ) {
				wp_register_script( $handle, $src, array(), $ver, true );
			} else {
				wp_register_style( $handle, $src, array(), $ver );
			}

			if ( 'style' === $kind && isset( $deps->registered[ self::TOKENS_HANDLE ] )
				&& ! in_array( self::TOKENS_HANDLE, $deps->registered[ $handle ]->deps, true ) ) {
				$deps->registered[ $handle ]->deps[] = self::TOKENS_HANDLE;
			}

			if ( 'script' === $kind ) {
				self::$module_handles[] = $handle;
				wp_enqueue_script( $handle );
			} else {
				wp_enqueue_style( $handle );
			}
		}

		private static function wanted_here( $calc ) {
			if ( ! empty( $calc['page_ids'] ) && is_page( $calc['page_ids'] ) ) {
				return true;
			}
			if ( ! empty( $calc['shortcodes'] ) && is_singular() ) {
				$post = get_post();
				if ( $post ) {
					foreach ( (array) $calc['shortcodes'] as $tag ) {
						if ( has_shortcode( $post->post_content, $tag ) ) {
							return true;
						}
					}
				}
			}
			return false;
		}

		/** Calculators are ES modules. Idempotent with the theme's own script_loader_tag filter. */
		public static function module_tag( $tag, $handle ) {
			if ( ! in_array( $handle, self::$module_handles, true ) || false !== strpos( $tag, 'type="module"' ) ) {
				return $tag;
			}
			$tag = preg_replace( '/\stype=([\'"])text\/javascript\1/', '', $tag );
			// Only the tag with src; inline before/after/localize tags stay classic scripts.
			return preg_replace( '/<script\b(?=[^>]*\ssrc=)/', '<script type="module"', $tag );
		}
	}

	GPACalc_Calculator_Assets::boot( dirname( __DIR__ ) . '/gpacalculator-manager.php' );
}
