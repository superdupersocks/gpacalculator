<?php
/**
 * Per-page switch between the old calculators and the new ones built on the shared core (core v2 +
 * engines + profiles). Every switch is OFF until it is turned on in Grade + GPA > New calculators, so
 * uploading this plugin changes no page. Turning a switch off is the one-click fallback to the old
 * calculator; nothing else changes (pages, shortcodes, the old files and every saved calculation stay).
 *
 *   ?calc=old  shows the old calculator on a switched page (anyone; for checking the fallback)
 *   ?calc=new  previews the new calculator on a page that is still off (logged-in editors only)
 *
 * Page calculators (College) swap their theme handles for the new module on the page itself.
 * University calculators ([gpcm_calculator id="…"]) keep their shortcode and profile JSON; when their
 * switch is on, the shortcode prints the same JSON for the new GPA engine instead of the old one.
 *
 * @package gpacalculator-manager
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'GPACalc_Switch' ) ) {

	final class GPACalc_Switch {

		const OPTION = 'gpcm_calc_v2_on'; // list of switch ids that are on
		const STYLES = array( 'core/calc-core.css', 'gpa/gpa-app.css' );

		/** Every calculator that has a new version, by switch id. */
		public static function all() {
			return apply_filters( 'gpacalc_switches', array(
				'college'  => array(
					'label'       => 'College GPA calculator',
					'url'         => '/college-gpa-calculator/',
					'pages'       => array( 'college-gpa-calculator' ),
					'entry'       => 'gpa/college-gpa.js',
					'old_scripts' => array( 'main-js-college-gpa-calculator' ),
					'old_styles'  => array( 'main-css-college-gpa-calculator' ),
					'homescreen'  => true, // web app manifest for "Add to home screen" (Calculator Design Standard)
				),
				'uni-ucla' => array(
					'label' => 'UCLA GPA calculator',
					'url'   => '/ucla-gpa-calculator/',
					'gpcm'  => 'ucla',
					'entry' => 'gpa/uni-gpa.js',
				),
			) );
		}

		public static function boot() {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1000 );
			add_filter( 'script_loader_tag', array( __CLASS__, 'module_tag' ), 20, 2 );
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
			add_action( 'rest_api_init', array( __CLASS__, 'manifest_route' ) );
			add_action( 'wp_footer', array( __CLASS__, 'drop_late' ), 1 );
			add_action( 'wp_print_footer_scripts', array( __CLASS__, 'drop_late' ), 1 );
		}

		/* ---------- Add to home screen: a minimal web app manifest per calculator page (no service worker) ---------- */

		/** The page whose new calculator opts into the home-screen hint (its manifest goes in the head). */
		private static $manifest_page = 0;

		public static function manifest_route() {
			register_rest_route( 'gpacalc/v1', '/manifest/(?P<id>\\d+)', array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'manifest' ),
			) );
		}

		/** The manifest for one calculator page: start_url is that page, so the home-screen icon opens it. */
		public static function manifest_data( $page_id ) {
			$url  = get_permalink( $page_id );
			$icon = GPACalc_Calculator_Assets::url( 'a2hs/badge-%d.png' );
			return array(
				'name'             => 'GPA Calculator',
				'short_name'       => 'GPA Calc',
				'start_url'        => add_query_arg( 'source', 'homescreen', $url ),
				'scope'            => '/',
				'display'          => 'standalone',
				'background_color' => '#ffffff',
				'theme_color'      => '#ffffff',
				'icons'            => array(
					array( 'src' => sprintf( $icon, 192 ), 'sizes' => '192x192', 'type' => 'image/png' ),
					array( 'src' => sprintf( $icon, 512 ), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
				),
			);
		}

		public static function manifest( $request ) {
			$id   = (int) $request['id'];
			$post = get_post( $id );
			if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
				return new WP_Error( 'gpacalc_no_page', 'No such calculator page.', array( 'status' => 404 ) );
			}
			$res = new WP_REST_Response( self::manifest_data( $id ) );
			$res->header( 'Content-Type', 'application/manifest+json; charset=utf-8' );
			$res->header( 'Cache-Control', 'public, max-age=86400' );
			return $res;
		}

		public static function manifest_link() {
			if ( self::$manifest_page ) {
				printf( "<link rel=\"manifest\" href=\"%s\">\n", esc_url( rest_url( 'gpacalc/v1/manifest/' . self::$manifest_page ) ) );
			}
		}

		/** Is the new version showing for this switch on this request? */
		public static function is_new( $id ) {
			$q = isset( $_GET['calc'] ) ? sanitize_key( wp_unslash( $_GET['calc'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			if ( 'old' === $q ) {
				return false;
			}
			if ( 'new' === $q && current_user_can( 'edit_pages' ) ) {
				return true;
			}
			return in_array( $id, (array) get_option( self::OPTION, array() ), true );
		}

		/** Switch id for a university profile slug, or ''. */
		public static function gpcm_switch( $slug ) {
			foreach ( self::all() as $id => $s ) {
				if ( isset( $s['gpcm'] ) && $s['gpcm'] === $slug ) {
					return $id;
				}
			}
			return '';
		}

		/** Page calculators switched to the new version on this request (their old handles stay out). */
		private static $swapped = array();

		private static function drop_old( array $s ) {
			foreach ( (array) $s['old_scripts'] as $h ) {
				wp_dequeue_script( $h );
			}
			foreach ( (array) $s['old_styles'] as $h ) {
				wp_dequeue_style( $h );
			}
		}

		/**
		 * The old shortcode ([college-gpa-calculator], from the Calculators plugin or the engine) enqueues its
		 * script and style while the content renders, after wp_enqueue_scripts. Drop them again just before
		 * the footer prints them, or the old calculator takes over the page (live check, Oct 3 16:58).
		 */
		public static function drop_late() {
			foreach ( self::$swapped as $s ) {
				self::drop_old( $s );
			}
		}

		/** Page calculators: swap the old handles for the new module where the switch is on. */
		public static function enqueue() {
			self::$swapped = array();
			foreach ( self::all() as $id => $s ) {
				if ( empty( $s['pages'] ) || ! is_page( $s['pages'] ) || ! self::is_new( $id ) ) {
					continue;
				}
				self::drop_old( $s );
				self::$swapped[ $id ] = $s;
				self::load( $id, $s );
				if ( ! empty( $s['homescreen'] ) && ! self::$manifest_page ) {
					self::$manifest_page = (int) get_queried_object_id();
					add_action( 'wp_head', array( __CLASS__, 'manifest_link' ), 5 );
				}
			}
		}

		/** Enqueue the shared CSS and the calculator's entry module. */
		public static function load( $id, array $s ) {
			$deps = array();
			foreach ( array( 'gpa-design-tokens', 'gpa-components' ) as $h ) {
				if ( isset( wp_styles()->registered[ $h ] ) ) {
					$deps[] = $h;
				}
			}
			foreach ( self::STYLES as $i => $file ) {
				$h = 'gpacalc-v2-' . $i;
				wp_register_style( $h, GPACalc_Calculator_Assets::url( $file ), $deps, self::ver( $file ) );
				wp_enqueue_style( $h );
				$deps = array( $h );
			}
			$h = 'gpacalc-v2-' . $id;
			wp_register_script( $h, GPACalc_Calculator_Assets::url( $s['entry'] ), array(), self::ver( $s['entry'] ), true );
			wp_enqueue_script( $h );
		}

		private static function ver( $file ) {
			$p = GPACalc_Calculator_Assets::path( $file );
			return is_readable( $p ) ? (string) filemtime( $p ) : GPCM_VERSION_FALLBACK;
		}

		/** The new modules are ES modules (they import the core and engine relative to themselves). */
		public static function module_tag( $tag, $handle ) {
			if ( 0 !== strpos( $handle, 'gpacalc-v2-' ) || false !== strpos( $tag, 'type="module"' ) ) {
				return $tag;
			}
			$tag = preg_replace( '/\stype=([\'"])text\/javascript\1/', '', $tag );
			return preg_replace( '/<script\b(?=[^>]*\ssrc=)/', '<script type="module"', $tag );
		}

		/**
		 * University shortcode markup for the new engine: the same profile JSON, in a host the new
		 * module mounts into (no shadow DOM, so the site tokens and component library apply).
		 */
		public static function gpcm_markup( $slug, $json ) {
			$id = self::gpcm_switch( $slug );
			$all = self::all();
			self::load( $id, $all[ $id ] );
			return '<div class="gpcm-host gpacalc-mount" data-calc="uni" data-gpcm-profile-id="' . esc_attr( $slug ) . '" data-gpac-engine="v2">' .
				'<script type="application/json" data-gpcm-profile>' . $json . '</script></div>';
		}

		/* ---------- Admin: Grade + GPA > New calculators ---------- */

		public static function menu() {
			add_submenu_page( 'gpcm-calculators', 'New calculators', 'New calculators', 'manage_options', 'gpacalc-switch', array( __CLASS__, 'page' ) );
		}

		public static function page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$all = self::all();
			if ( isset( $_POST['gpacalc_switch_save'] ) ) {
				check_admin_referer( 'gpacalc_switch' );
				$on = array();
				foreach ( (array) ( isset( $_POST['on'] ) ? wp_unslash( $_POST['on'] ) : array() ) as $id ) {
					$id = sanitize_key( $id );
					if ( isset( $all[ $id ] ) ) {
						$on[] = $id;
					}
				}
				update_option( self::OPTION, $on, false );
				echo '<div class="notice notice-success"><p>Saved. Purge the page cache so visitors get the change.</p></div>';
			}
			$on = (array) get_option( self::OPTION, array() );
			echo '<div class="wrap"><h1>New calculators</h1>';
			echo '<p>Each calculator shows its new version only when it is ticked here. Untick it to go back to the old one. Pages, shortcodes and students’ saved calculations are not changed either way.</p>';
			echo '<p>Check a page before turning it on: add <code>?calc=new</code> to its address while logged in. On a page that is on, <code>?calc=old</code> shows the old version.</p>';
			echo '<form method="post">';
			wp_nonce_field( 'gpacalc_switch' );
			echo '<table class="widefat striped" style="max-width:720px"><tbody>';
			foreach ( $all as $id => $s ) {
				printf(
					'<tr><td><label><input type="checkbox" name="on[]" value="%1$s"%2$s> %3$s</label></td><td><a href="%4$s?calc=new" target="_blank">Preview new</a> · <a href="%4$s?calc=old" target="_blank">Old</a></td></tr>',
					esc_attr( $id ),
					in_array( $id, $on, true ) ? ' checked' : '',
					esc_html( $s['label'] ),
					esc_url( home_url( $s['url'] ) )
				);
			}
			echo '</tbody></table><p><button class="button button-primary" name="gpacalc_switch_save" value="1">Save</button></p></form></div>';
		}
	}

	if ( ! defined( 'GPCM_VERSION_FALLBACK' ) ) {
		define( 'GPCM_VERSION_FALLBACK', '1' );
	}
	GPACalc_Switch::boot();
}
