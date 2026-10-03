<?php
/**
 * Header + footer reorganization (Digant, 2026-10-03). Run through scripts/nav/nav_reorg.sh, which passes the mode.
 *
 *   wp eval-file nav_reorg.php inspect               current menus, locations, footer widgets, credits hook
 *   wp eval-file nav_reorg.php backup <file.json>    save locations, footer widgets and every menu item
 *   wp eval-file nav_reorg.php plan                  resolve every link; list calculators/hubs not linked
 *   wp eval-file nav_reorg.php apply <file.json>     needs the backup file; builds the new menus and points the
 *                                                    header location and the five footer widgets at them
 *   wp eval-file nav_reorg.php revert <file.json>    locations + footer widgets back as in the backup; deletes
 *                                                    the menus apply created
 *   wp eval-file nav_reorg.php legal                 add Terms + Data sources to the bottom bar once published
 *
 * The old menus are never edited or deleted, so revert is just re-pointing. Labels: one label per page everywhere.
 */

$mode = isset( $args[0] ) ? $args[0] : 'plan';
$file = isset( $args[1] ) ? $args[1] : '';

const NR_PREFIX = 'Nav 2026-10: ';

/* Footer widget => column. Widget ids as live on 2026-10-03 (footer-1 … footer-5). */
$NR_WIDGETS = array(
	'footer-1' => 'nav_menu-4',
	'footer-2' => 'nav_menu-6',
	'footer-3' => 'nav_menu-5',
	'footer-4' => 'nav_menu-7',
	'footer-5' => 'nav_menu-9',
);

$GPA = array(
	array( 'College GPA Calculator', 'page:college-gpa-calculator' ),
	array( 'High School GPA Calculator', 'page:high-school-gpa-calculator' ),
	array( 'Weighted GPA Calculator', 'page:weighted-gpa-calculator' ),
	array( 'Middle School GPA Calculator', 'page:middle-school-gpa-calculator' ),
	array( 'CGPA Calculator', 'page:cumulative-cgpa-calculator' ),
	array( 'Raise GPA Calculator', 'page:how-to-raise-gpa' ),
);
$GRADE = array(
	array( 'Grade Calculator', 'page:grade-calculator' ),
	array( 'Final Grade Calculator', 'page:final-grade-calculator' ),
	array( 'Weighted Grade Calculator', 'page:weighted-grade-calculator' ),
	array( 'Semester Grade Calculator', 'page:semester-grade-calculator' ),
	array( 'EZ Grader', 'page:ez-grader' ),
);

/* Top 6 tier A college pages by Search Console impressions, 2025-10-02 … 2026-10-01, both address forms
   and pre-rename slugs combined (data/nav/colleges-top6.csv). Short names from the admissions meta work. */
$COLLEGES = array(
	array( 'Browse all colleges', 'url:/admissions/' ),
	array( 'University of South Carolina', 'college:university-of-south-carolina-columbia' ),
	array( 'University of Arkansas', 'college:university-of-arkansas' ),
	array( 'Chico State', 'college:california-state-university-chico' ),
	array( 'George Mason', 'college:george-mason-university' ),
	array( 'UMKC', 'college:university-of-missouri-kansas-city' ),
	array( 'Kennesaw State University', 'college:kennesaw-state-university' ),
);

$MENUS = array(
	'header' => array(
		'name'  => NR_PREFIX . 'Header',
		'items' => array(
			array( 'GPA Calculators', 'url:#', $GPA ),
			array( 'Grade Calculators', 'url:#', $GRADE ),
			array( 'GPA Scale', 'page:gpa-scale' ),
			array( 'Grade Conversion', 'page:grade-conversion' ),
			array( 'Colleges', 'url:/admissions/' ),   // last (Digant 16:41)
		),
	),
	'footer-1' => array( 'name' => NR_PREFIX . 'Footer GPA Calculators', 'title' => 'GPA Calculators', 'items' => $GPA ),
	'footer-2' => array( 'name' => NR_PREFIX . 'Footer Grade Calculators', 'title' => 'Grade Calculators', 'items' => $GRADE ),
	'footer-3' => array(
		'name'  => NR_PREFIX . 'Footer Popular GPAs',
		'title' => 'Popular GPAs',
		'items' => array(
			array( '4.0 GPA', 'page:gpa-scale/4-0-gpa' ),
			array( '3.9 GPA', 'page:gpa-scale/3-9-gpa' ),
			array( '3.8 GPA', 'page:gpa-scale/3-8-gpa' ),
			array( '3.7 GPA', 'page:gpa-scale/3-7-gpa' ),
			array( '3.6 GPA', 'page:gpa-scale/3-6-gpa' ),
			array( '3.5 GPA', 'page:gpa-scale/3-5-gpa' ),
			array( '3.0 GPA', 'page:gpa-scale/3-0-gpa' ),
			array( 'All GPAs →', 'page:gpa-scale' ),
		),
	),
	'footer-4' => array( 'name' => NR_PREFIX . 'Footer Colleges', 'title' => 'Colleges', 'items' => $COLLEGES ),
	'footer-5' => array(
		'name'  => NR_PREFIX . 'Footer International',
		'title' => 'International',
		'items' => array(
			array( 'UK', 'page:grade-conversion/united-kingdom' ),
			array( 'Australia', 'page:grade-conversion/australia' ),
			array( 'Canada', 'page:grade-conversion/canada' ),
			array( 'India', 'page:grade-conversion/india' ),
			array( 'China', 'page:grade-conversion/china' ),
			array( 'France', 'page:grade-conversion/france' ),
			array( 'Germany', 'page:grade-conversion/germany' ),
			array( 'SGPA to CGPA', 'page:sgpa-to-cgpa-conversion-calculator' ),
			array( 'CGPA to Percentage', 'page:cgpa-to-percentage-calculator' ),
		),
	),
	'legal' => array(
		'name'  => NR_PREFIX . 'Footer bottom bar',
		'items' => array(
			array( 'About', 'page:about-us' ),
			array( 'Contact', 'page:contact-us' ),
			array( 'Privacy', 'page:privacy-policy' ),
			array( 'Terms', 'page:terms', null, 'optional' ),
			array( 'Data sources', 'page:data-sources', null, 'optional' ),
		),
	),
);

/** Resolve "page:path", "college:slug" or "url:/path/" to a menu-item spec, or a string error. */
function nr_resolve( $target ) {
	list( $kind, $ref ) = explode( ':', $target, 2 );
	if ( 'url' === $kind ) {
		return array( 'type' => 'custom', 'url' => '#' === $ref ? '#' : home_url( $ref ) );
	}
	$pt   = 'college' === $kind ? 'colleges' : 'page';
	$post = get_page_by_path( $ref, OBJECT, $pt );
	if ( ! $post ) {
		return "not found ($pt $ref)";
	}
	if ( 'publish' !== $post->post_status ) {
		return "not published ($pt $ref, {$post->post_status})";
	}
	if ( 'colleges' === $pt && 'C' === get_post_meta( $post->ID, 'admissions_tier', true ) ) {
		return "tier C ($ref)";
	}
	return array( 'type' => 'post_type', 'object' => $pt, 'id' => $post->ID, 'url' => get_permalink( $post ) );
}

function nr_items_flat( $items ) {
	$out = array();
	foreach ( $items as $it ) {
		$out[] = $it;
		if ( ! empty( $it[2] ) ) {
			foreach ( $it[2] as $c ) {
				$out[] = $c;
			}
		}
	}
	return $out;
}

function nr_snapshot() {
	$menus = array();
	foreach ( wp_get_nav_menus() as $m ) {
		$items = array();
		foreach ( (array) wp_get_nav_menu_items( $m->term_id, array( 'post_status' => 'any' ) ) as $i ) {
			$items[] = array( 'id' => $i->ID, 'parent' => (int) $i->menu_item_parent, 'title' => $i->title, 'url' => $i->url,
				'type' => $i->type, 'object' => $i->object, 'object_id' => (int) $i->object_id, 'order' => (int) $i->menu_order );
		}
		$menus[] = array( 'term_id' => $m->term_id, 'name' => $m->name, 'slug' => $m->slug, 'items' => $items );
	}
	return array(
		'taken'              => gmdate( 'c' ),
		'nav_menu_locations' => get_theme_mod( 'nav_menu_locations' ),
		'widget_nav_menu'    => get_option( 'widget_nav_menu' ),
		'sidebars_widgets'   => get_option( 'sidebars_widgets' ),
		'menus'              => $menus,
	);
}

function nr_check_widgets( $map ) {
	$sw   = get_option( 'sidebars_widgets' );
	$errs = array();
	foreach ( $map as $area => $wid ) {
		if ( empty( $sw[ $area ] ) || ! in_array( $wid, $sw[ $area ], true ) ) {
			$errs[] = "$wid is not in $area (" . ( empty( $sw[ $area ] ) ? 'empty' : implode( ',', $sw[ $area ] ) ) . ')';
		}
	}
	return $errs;
}

switch ( $mode ) {
	case 'inspect':
		$snap = nr_snapshot();
		echo "Locations: " . wp_json_encode( $snap['nav_menu_locations'] ) . "\n";
		foreach ( array( 'footer-1', 'footer-2', 'footer-3', 'footer-4', 'footer-5', 'footer-bar' ) as $a ) {
			echo "$a: " . ( isset( $snap['sidebars_widgets'][ $a ] ) ? implode( ',', (array) $snap['sidebars_widgets'][ $a ] ) : '-' ) . "\n";
		}
		foreach ( (array) $snap['widget_nav_menu'] as $k => $w ) {
			if ( is_array( $w ) ) {
				echo "widget nav_menu-$k: title=" . ( isset( $w['title'] ) ? $w['title'] : '' ) . ' menu=' . ( isset( $w['nav_menu'] ) ? $w['nav_menu'] : '' ) . "\n";
			}
		}
		foreach ( $snap['menus'] as $m ) {
			echo "\nMenu {$m['term_id']} {$m['name']} ({$m['slug']})\n";
			foreach ( $m['items'] as $i ) {
				echo '  ' . ( $i['parent'] ? '  ' : '' ) . "{$i['id']} {$i['title']} -> {$i['url']}\n";
			}
		}
		global $wp_filter;
		echo "\ngenerate_credits callbacks:";
		if ( isset( $wp_filter['generate_credits'] ) ) {
			foreach ( $wp_filter['generate_credits']->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $id => $cb ) {
					echo " [$prio] $id";
				}
			}
		}
		echo "\ntheme_mod generate_copyright: " . wp_json_encode( get_theme_mod( 'generate_copyright' ) ) . "\n";
		$gs = get_option( 'generate_settings' );
		foreach ( array( 'nav_dropdown_type', 'footer_widget_setting', 'nav_search' ) as $k ) {
			echo "generate_settings[$k]: " . wp_json_encode( isset( $gs[ $k ] ) ? $gs[ $k ] : null ) . "\n";
		}
		$els = get_posts( array( 'post_type' => 'gp_elements', 'post_status' => 'publish', 'numberposts' => -1 ) );
		foreach ( $els as $e ) {
			echo "GP element {$e->ID} {$e->post_title}: hook=" . get_post_meta( $e->ID, '_generate_hook', true ) . "\n";
		}
		break;

	case 'backup':
		if ( ! $file ) {
			WP_CLI::error( 'backup needs a file name' );
		}
		file_put_contents( $file, wp_json_encode( nr_snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		echo "saved $file (" . filesize( $file ) . " bytes)\n";
		break;

	case 'plan':
	case 'apply':
		$errors = nr_check_widgets( $NR_WIDGETS );
		$linked = array();
		foreach ( $MENUS as $key => $menu ) {
			echo "\n== {$menu['name']}" . ( isset( $menu['title'] ) ? " (widget title \"{$menu['title']}\")" : '' ) . "\n";
			foreach ( $menu['items'] as $it ) {
				$rows = array( array( $it, 0 ) );
				if ( ! empty( $it[2] ) ) {
					foreach ( $it[2] as $c ) {
						$rows[] = array( $c, 1 );
					}
				}
				foreach ( $rows as $r ) {
					$res = nr_resolve( $r[0][1] );
					$opt = isset( $r[0][3] ) && 'optional' === $r[0][3];
					if ( is_string( $res ) ) {
						echo ( $r[1] ? '    ' : '  ' ) . "{$r[0][0]}: " . ( $opt ? "skipped, $res" : "ERROR $res" ) . "\n";
						if ( ! $opt ) {
							$errors[] = "{$r[0][0]}: $res";
						}
					} else {
						echo ( $r[1] ? '    ' : '  ' ) . "{$r[0][0]} -> {$res['url']}\n";
						$pid = ! empty( $res['id'] ) ? $res['id'] : url_to_postid( $res['url'] );
						if ( $pid ) {
							$linked[ $pid ] = true;
						}
					}
				}
			}
		}
		// Calculators and hubs not linked from the header or footer.
		$tags = array( 'college-gpa-calculator', 'final-grade-calculator', 'gpa-calculator', 'gpa-scale', 'gpa_conversion',
			'grade-calculator', 'high-school-gpa-calc', 'high-school-gpa-calculator', 'middle-school-gpa-calculator',
			'raise-gpa-calculator', 'semester-gpa-calculator', 'semester-grade-calculator', 'sgpa-to-cgpa-calculator',
			'weighted-grade-calculator', 'country_grade', 'country_grade_scale', 'gpcm_calculator', 'formidable',
			'gpa_college_archive', 'weighted-gpa-calculator', 'cgpa-to-percentage' );
		$pages    = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) );
		$unlinked = array( 'calculator' => array(), 'university' => array(), 'country' => array() );
		foreach ( $pages as $p ) {
			if ( isset( $linked[ $p->ID ] ) || (int) get_option( 'page_on_front' ) === $p->ID ) {
				continue;
			}
			$found = array();
			foreach ( $tags as $t ) {
				if ( has_shortcode( $p->post_content, $t ) ) {
					$found[] = $t;
				}
			}
			if ( false !== strpos( $p->post_content, 'wp:formidable/' ) || false !== strpos( $p->post_content, 'class="frm' ) ) {
				$found[] = 'formidable-block';
			}
			if ( preg_match( '/<div id="(root|gpa-converter-app|gpa-conversion-app)"|gpacalc-mount/', $p->post_content ) ) {
				$found[] = 'inline-mount';
			}
			if ( ! $found ) {
				continue;
			}
			$bucket = in_array( 'gpcm_calculator', $found, true ) ? 'university'
				: ( array_intersect( $found, array( 'country_grade', 'country_grade_scale' ) ) ? 'country' : 'calculator' );
			$unlinked[ $bucket ][] = get_permalink( $p ) . ' [' . implode( ',', array_unique( $found ) ) . ']';
		}
		foreach ( $unlinked as $b => $list ) {
			echo "\nNot linked from header/footer, $b pages: " . count( $list ) . "\n";
			if ( 'university' !== $b ) {
				foreach ( $list as $l ) {
					echo "  $l\n";
				}
			}
		}
		if ( $errors ) {
			echo "\nProblems:\n  " . implode( "\n  ", $errors ) . "\n";
			if ( 'apply' === $mode ) {
				WP_CLI::error( 'nothing changed' );
			}
		}
		if ( 'plan' === $mode ) {
			echo "\nplan only, nothing changed\n";
			break;
		}
		if ( ! $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'apply needs the backup file from "backup"' );
		}
		// Rebuild our own menus (a re-run replaces them), then point the header, footer widgets and bottom bar.
		$ids = array();
		foreach ( $MENUS as $key => $menu ) {
			$old = wp_get_nav_menu_object( $menu['name'] );
			if ( $old ) {
				wp_delete_nav_menu( $old->term_id );
			}
			$mid = wp_create_nav_menu( $menu['name'] );
			if ( is_wp_error( $mid ) ) {
				WP_CLI::error( $mid->get_error_message() );
			}
			$pos = 0;
			foreach ( $menu['items'] as $it ) {
				$add = function ( $it, $parent ) use ( $mid, &$pos ) {
					$res = nr_resolve( $it[1] );
					if ( is_string( $res ) ) {
						return 0;
					}
					$data = array( 'menu-item-title' => $it[0], 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent,
						'menu-item-position' => ++$pos );
					if ( 'custom' === $res['type'] ) {
						$data += array( 'menu-item-type' => 'custom', 'menu-item-url' => $res['url'] );
					} else {
						$data += array( 'menu-item-type' => 'post_type', 'menu-item-object' => $res['object'], 'menu-item-object-id' => $res['id'] );
					}
					$id = wp_update_nav_menu_item( $mid, 0, $data );
					if ( is_wp_error( $id ) ) {
						WP_CLI::error( $id->get_error_message() );
					}
					return $id;
				};
				$pid = $add( $it, 0 );
				if ( ! empty( $it[2] ) ) {
					foreach ( $it[2] as $c ) {
						$add( $c, $pid );
					}
				}
			}
			$ids[ $key ] = $mid;
			echo "menu {$menu['name']} = $mid\n";
		}
		$loc                     = (array) get_theme_mod( 'nav_menu_locations' );
		$loc['primary']          = $ids['header'];
		$loc['gpa-footer-legal'] = $ids['legal'];
		set_theme_mod( 'nav_menu_locations', $loc );
		$w = get_option( 'widget_nav_menu' );
		foreach ( $NR_WIDGETS as $area => $wid ) {
			$n               = (int) substr( $wid, strlen( 'nav_menu-' ) );
			$w[ $n ]['title']    = $MENUS[ $area ]['title'];
			$w[ $n ]['nav_menu'] = $ids[ $area ];
		}
		update_option( 'widget_nav_menu', $w );
		echo "header location, bottom bar location and 5 footer widgets updated\n";
		break;

	case 'revert':
		if ( ! $file || ! is_readable( $file ) ) {
			WP_CLI::error( 'revert needs the backup file' );
		}
		$b = json_decode( file_get_contents( $file ), true );
		set_theme_mod( 'nav_menu_locations', $b['nav_menu_locations'] );
		update_option( 'widget_nav_menu', $b['widget_nav_menu'] );
		update_option( 'sidebars_widgets', $b['sidebars_widgets'] );
		$kept = wp_list_pluck( $b['menus'], 'term_id' );
		foreach ( wp_get_nav_menus() as $m ) {
			if ( 0 === strpos( $m->name, NR_PREFIX ) && ! in_array( $m->term_id, $kept, true ) ) {
				wp_delete_nav_menu( $m->term_id );
				echo "deleted menu {$m->name}\n";
			}
		}
		echo "locations and footer widgets restored from $file\n";
		break;

	case 'legal':
		$menu = wp_get_nav_menu_object( $MENUS['legal']['name'] );
		if ( ! $menu ) {
			WP_CLI::error( 'bottom bar menu not found; run apply first' );
		}
		$have = wp_list_pluck( (array) wp_get_nav_menu_items( $menu->term_id ), 'title' );
		$pos  = count( $have );
		foreach ( $MENUS['legal']['items'] as $it ) {
			if ( in_array( $it[0], $have, true ) ) {
				continue;
			}
			$res = nr_resolve( $it[1] );
			if ( is_string( $res ) ) {
				echo "{$it[0]}: $res\n";
				continue;
			}
			wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => $it[0], 'menu-item-status' => 'publish',
				'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $res['id'],
				'menu-item-position' => ++$pos ) );
			echo "added {$it[0]} -> {$res['url']}\n";
		}
		break;

	default:
		WP_CLI::error( "unknown mode $mode" );
}
