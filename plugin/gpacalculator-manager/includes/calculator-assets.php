<?php
/**
 * Calculator assets: serve every calculator's JS/CSS from this plugin.
 *
 * Calculators live in assets/calc-assets/ (theme calculators keep their old filenames, so
 * relative ES module imports like ./core/calc-core.js keep working). Pages, page URLs and
 * shortcodes don't change.
 *
 * For each calculator in the registry (includes/calculators.php):
 *  - If the theme or an old plugin already enqueued its handle, the same handle is repointed
 *    at the plugin file. Dependencies, footer placement, localized data and inline scripts stay
 *    attached to the handle, so the page behaves exactly as before.
 *  - If the page uses one of its shortcodes or is one of its page_ids, it is enqueued in <head>.
 *  - If the plugin file doesn't exist yet, nothing is touched and the old file keeps loading.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Calculator_Assets' ) ) {

	final class GPACalc_Calculator_Assets {

		const DIR           = 'assets/calc-assets/';
		const TOKENS_HANDLE = 'gpa-brand-tokens';

		/** @var string Any path in the plugin root; plugins_url() only uses its directory. */
		private static $root_file = '';

		/** @var string[] Script handles served by this loader (printed as type="module"). */
		private static $module_handles = array();

		public static function boot( $root_file ) {
			self::$root_file = $root_file;
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 999 );
			add_filter( 'script_loader_tag', array( __CLASS__, 'module_tag' ), 20, 2 );
		}

		public static function path( $file ) {
			return dirname( self::$root_file ) . '/' . self::DIR . ltrim( $file, '/' );
		}

		public static function url( $file ) {
			return plugins_url( self::DIR . ltrim( $file, '/' ), self::$root_file );
		}

		/** True when the calculator's files are in the plugin (it has been moved). */
		public static function is_moved( array $calc ) {
			foreach ( array( 'js', 'css' ) as $ext ) {
				if ( $calc[ $ext ] && ! is_readable( self::path( $calc[ $ext ] ) ) ) {
					return false;
				}
			}
			return (bool) ( $calc['js'] || $calc['css'] );
		}

		public static function enqueue() {
			foreach ( GPACalc_Registry::all() as $calc ) {
				self::load( $calc, self::wanted_here( $calc ) );
			}
		}

		/** Enqueue one calculator now (used by its shortcode when the page didn't announce it). */
		public static function require_calc( array $calc ) {
			self::load( $calc, true );
		}

		private static function load( array $calc, $wanted ) {
			if ( $calc['js'] ) {
				self::serve( 'script', $calc['js'], $calc['script_handle'], $wanted );
			}
			if ( $calc['css'] ) {
				self::serve( 'style', $calc['css'], $calc['style_handle'], $wanted );
			}
		}

		/**
		 * Point $handle at the plugin copy of $file. Only touches pages where the handle is
		 * already enqueued, or where the calculator is wanted.
		 */
		private static function serve( $kind, $file, $handle, $wanted ) {
			$path = self::path( $file );
			if ( ! is_readable( $path ) ) {
				return; // Not moved yet: the old copy stays in use.
			}
			$deps   = 'script' === $kind ? wp_scripts() : wp_styles();
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
				if ( ! in_array( $handle, self::$module_handles, true ) ) {
					self::$module_handles[] = $handle;
				}
				wp_enqueue_script( $handle );
			} else {
				wp_enqueue_style( $handle );
			}
		}

		private static function wanted_here( array $calc ) {
			if ( $calc['page_ids'] && is_page( $calc['page_ids'] ) ) {
				return true;
			}
			if ( $calc['shortcodes'] && is_singular() ) {
				$post = get_post();
				if ( $post ) {
					foreach ( $calc['shortcodes'] as $tag ) {
						// Only tags the engine serves: while an old plugin still owns a tag, it loads its own assets.
						if ( has_shortcode( $post->post_content, $tag ) && GPACalc_Shortcodes::owns( $tag ) ) {
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
