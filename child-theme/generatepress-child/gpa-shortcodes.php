<?php
/**
 * GPA Calculator — server-rendered shortcodes for content fragments that
 * need to stay live across all pages.
 *
 * Why shortcodes (not block patterns) for these:
 *   Block patterns get COPIED into post_content on insert and become frozen.
 *   When pattern HTML changes, existing pages don't update. Shortcodes are
 *   resolved on every render, so PHP edits propagate everywhere instantly.
 *
 * Usage in patterns / posts:
 *   [gpa_table type="sample-courses"]
 *   [gpa_table type="quality-points"]
 *   [gpa_table type="content-sample"]
 *   [gpa_table type="worked-example"]                 (the College GPA example; example="content" for the 3-course one)
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'gpa_register_shortcodes');
function gpa_register_shortcodes() {
    add_shortcode('gpa_table', 'gpa_render_table_shortcode');
}

function gpa_render_table_shortcode($atts) {
    $atts = shortcode_atts([
        'type'    => 'sample-courses',
        'example' => 'college',
    ], $atts, 'gpa_table');

    switch ($atts['type']) {
        case 'sample-courses':
            return gpa_render_sample_courses_table();
        case 'quality-points':
        case 'worked-example':
            return gpa_render_worked_example( isset( $atts['example'] ) ? $atts['example'] : 'college' );
        case 'content-sample':
            return gpa_render_worked_example( 'content' );
        default:
            return '';
    }
}

function gpa_render_sample_courses_table() {
    return '<figure class="wp-block-table calc-table"><table class="has-fixed-layout">'
        . '<caption class="screen-reader-text">Sample semester courses with letter grades and credit hours used for GPA calculation</caption>'
        . '<thead><tr><th scope="col">Course</th><th scope="col">Grade</th><th scope="col">Credits</th></tr></thead>'
        . '<tbody>'
        . '<tr><td>ENG 101</td><td>A</td><td>3</td></tr>'
        . '<tr><td>MATH 121</td><td>B+</td><td>4</td></tr>'
        . '<tr><td>PSY 201</td><td>A&ndash;</td><td>3</td></tr>'
        . '<tr><td>BIO 110</td><td>C+</td><td>2</td></tr>'
        . '</tbody></table></figure>';
}

function gpa_render_quality_points_table() {
    return '<figure class="wp-block-table calc-table calc-table-gray"><table class="has-fixed-layout">'
        . '<caption class="screen-reader-text">Quality points worked out per course, with totals used in the GPA formula</caption>'
        . '<thead><tr>'
        . '<th scope="col">Course</th><th scope="col">Credits</th><th scope="col">Grade</th>'
        . '<th scope="col">Grade Points</th><th scope="col">Quality Points</th>'
        . '</tr></thead>'
        . '<tbody>'
        . '<tr><td>ENG 101</td><td>3</td><td>A</td><td>4.0</td><td>12.0</td></tr>'
        . '<tr><td>MATH 121</td><td>4</td><td>B+</td><td>3.3</td><td>13.2</td></tr>'
        . '<tr><td>PSY 201</td><td>3</td><td>A&ndash;</td><td>3.7</td><td>11.1</td></tr>'
        . '<tr><td>BIO 110</td><td>2</td><td>C+</td><td>2.3</td><td>4.6</td></tr>'
        . '<tr><th scope="row">Total</th><td><strong>12</strong></td><td>&mdash;</td><td>&mdash;</td><td><strong>40.9</strong></td></tr>'
        . '</tbody></table></figure>';
}

function gpa_render_content_sample_table() {
    return '<figure class="wp-block-table content-table content-table-gray"><table class="has-fixed-layout">'
        . '<caption class="screen-reader-text">Sample semester GPA calculation with grade points and quality points per course</caption>'
        . '<thead><tr>'
        . '<th scope="col">Course</th><th scope="col">Grade</th><th scope="col">Credits</th>'
        . '<th scope="col">Grade Points</th><th scope="col">Quality Points</th>'
        . '</tr></thead>'
        . '<tbody>'
        . '<tr><td>English 101</td><td>A</td><td>3</td><td>4.0</td><td>12.0</td></tr>'
        . '<tr><td>Math 121</td><td>B+</td><td>4</td><td>3.3</td><td>13.2</td></tr>'
        . '<tr><td>Biology 110</td><td>B</td><td>3</td><td>3.0</td><td>9.0</td></tr>'
        . '<tr><th scope="row">Total</th><td>&mdash;</td><td><strong>10</strong></td><td>&mdash;</td><td><strong>34.2</strong></td></tr>'
        . '</tbody></table></figure>';
}

/**
 * Worked examples (component library: "Calculation example"). Design: "B · Formula reads across" / "B · On a phone".
 * One real <table> (caption, thead, th scope): the row cells, then operator cells (aria-hidden) that make each row read
 * as a formula, and the result strip as the <tfoot>. Phones restyle the same markup (components.css 4b).
 * Every number in the result is computed here, so an example always adds up.
 *
 *   [gpa_example type="gpa" rows="ENG 101 — English Composition|A|3; MATH 121 — Calculus I|B+|4"]
 *        Course|Grade|Credits[|Grade points]   points default to the 4.0 scale (A+ and A = 4.0 … F = 0.0)
 *   [gpa_example type="weighted" rows="English 10|Regular|A|1; Biology|AP|A-|1"]
 *        Course|Level|Grade|Credits            Honors +0.5, AP/IB/Dual +1.0 (boosts="honors:0.5,ap:1"); shows both GPAs
 *   [gpa_example type="grade" rows="Homework|92|20; Quizzes|84|30; Tests|78|50"]
 *        Category|Score %|Weight %             course grade = sum of score × weight ÷ total weight
 *   Optional: caption="…" (screen readers and search), example="college|content" (built-in GPA examples).
 */
function gpa_points_for( $grade ) {
    $map = array( 'A+' => 4.0, 'A' => 4.0, 'A-' => 3.7, 'B+' => 3.3, 'B' => 3.0, 'B-' => 2.7, 'C+' => 2.3, 'C' => 2.0,
        'C-' => 1.7, 'D+' => 1.3, 'D' => 1.0, 'D-' => 0.7, 'F' => 0.0 );
    $g = strtoupper( str_replace( array( '–', '−' ), '-', trim( $grade ) ) );
    return isset( $map[ $g ] ) ? $map[ $g ] : null;
}

function gpa_grade_band( $grade ) {
    $l = strtoupper( substr( trim( $grade ), 0, 1 ) );
    return in_array( $l, array( 'A', 'B', 'C', 'D', 'F' ), true ) ? strtolower( $l ) : 'c';
}

function gpa_ex_num( $n, $dec ) { return number_format( (float) $n, $dec, '.', '' ); }
function gpa_ex_trim( $n ) { return rtrim( rtrim( gpa_ex_num( $n, 2 ), '0' ), '.' ); }

function gpa_ex_rows( $raw ) {
    $rows = array();
    foreach ( preg_split( '/\s*;\s*/', (string) $raw ) as $line ) {
        if ( '' === trim( $line ) ) { continue; }
        $rows[] = array_map( 'trim', explode( '|', $line ) );
    }
    return $rows;
}

/* The shared renderer: $cols = array( array( label, kind ) ), kind course|op|cell|qp|badge|tag; $rows = cell arrays. */
function gpa_ex_render( $caption, $mhead, $cols, $rows, $tiles, $note = '' ) {
    $head = '';
    foreach ( $cols as $c ) {
        $head .= 'op' === $c[1]
            ? '<th class="gpa-ex__op" aria-hidden="true"></th>'
            : '<th scope="col"' . ( 'course' === $c[1] ? ' class="gpa-ex__course"' : '' ) . '>' . esc_html( $c[0] ) . '</th>';
    }
    $body = '';
    foreach ( $rows as $r ) {
        $body .= '<tr>';
        foreach ( $cols as $k => $c ) {
            $v = $r[ $k ];
            switch ( $c[1] ) {
                case 'course': $body .= '<th scope="row" class="gpa-ex__course">' . esc_html( $v ) . '</th>'; break;
                case 'op':     $body .= '<td class="gpa-ex__op" aria-hidden="true">' . $v . '</td>'; break;
                case 'qp':     $body .= '<td class="gpa-ex__qp">' . $v . '</td>'; break;
                default:       $body .= '<td class="gpa-ex__' . esc_attr( $c[1] ) . '">' . $v . '</td>';
            }
        }
        $body .= '</tr>';
    }
    $strip = '';
    foreach ( $tiles as $k => $t ) {
        if ( $k ) { $strip .= '<span class="gpa-ex__rop" aria-hidden="true">' . $t[2] . '</span>'; }
        $mod = $k === count( $tiles ) - 1 ? ' gpa-ex__tile--gpa' : ( $k ? ' gpa-ex__tile--div' : '' );
        $strip .= '<div class="gpa-ex__tile' . $mod . '"><span class="gpa-ex__label">' . esc_html( $t[0] ) . '</span> <span class="gpa-ex__value">' . esc_html( $t[1] ) . '</span></div>';
    }
    if ( '' !== $note ) { $strip .= '<p class="gpa-ex__note">' . wp_kses_post( $note ) . '</p>'; }
    $strip = '<div class="gpa-ex__result">' . $strip . '</div>';
    return '<figure class="gpa-ex">'
        . '<div class="gpa-ex__mhead" aria-hidden="true"><span>' . esc_html( $mhead[0] ) . '</span><span>' . esc_html( $mhead[1] ) . '</span></div>'
        . '<table class="gpa-ex__table"><caption class="screen-reader-text">' . esc_html( $caption ) . '</caption>'
        . '<thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody>'
        . '<tfoot><tr><td colspan="' . count( $cols ) . '">' . $strip . '</td></tr></tfoot></table></figure>';
}

function gpa_example_shortcode( $atts ) {
    $a = shortcode_atts( array( 'type' => 'gpa', 'rows' => '', 'caption' => '', 'example' => '', 'boosts' => 'honors:0.5,ap:1,ib:1,dual:1,de:1' ), $atts, 'gpa_example' );
    $presets = array(
        'college' => 'ENG 101 — English Composition|A|3; MATH 121 — Calculus I|B+|4; PSY 201 — Intro to Psychology|A-|3; BIO 110 — General Biology|C+|2',
        'content' => 'English 101|A|3; Math 121|B+|4; Biology 110|B|3',
    );
    if ( '' === $a['rows'] && isset( $presets[ $a['example'] ?: 'college' ] ) && 'gpa' === $a['type'] ) {
        $a['rows'] = $presets[ $a['example'] ?: 'college' ];
        if ( '' === $a['caption'] ) {
            $a['caption'] = 'college' === ( $a['example'] ?: 'college' )
                ? 'Worked example: a four-course college semester, from letter grades to a 3.41 GPA'
                : 'Worked example: a three-course semester, from letter grades to GPA';
        }
    }
    $rows = gpa_ex_rows( $a['rows'] );
    if ( ! $rows ) { return ''; }
    $badge = function ( $g ) { return '<span class="gpa-ex__badge gpa-ex__badge--' . gpa_grade_band( $g ) . '">' . esc_html( str_replace( '-', '–', $g ) ) . '</span>'; };

    if ( 'grade' === $a['type'] ) {
        $sum = 0.0; $wsum = 0.0; $out = array();
        foreach ( $rows as $r ) {
            list( $cat, $score, $w ) = array_pad( $r, 3, '0' );
            $score = (float) $score; $w = (float) $w; $part = round( $score * $w / 100, 1 );
            $sum += $part; $wsum += $w;
            $out[] = array( $cat, gpa_ex_trim( $score ) . '%', '×', gpa_ex_trim( $w ) . '%', '=', gpa_ex_num( $part, 1 ) );
        }
        $grade = $wsum ? $sum / $wsum * 100 : 0;
        return gpa_ex_render( $a['caption'] ?: 'Worked example: a course grade from weighted categories',
            array( 'Score × weight', 'Points' ),
            array( array( 'Category', 'course' ), array( 'Score', 'cell' ), array( '', 'op' ), array( 'Weight', 'cell' ), array( '', 'op' ), array( 'Points', 'qp' ) ),
            $out,
            array( array( 'Total points', gpa_ex_num( $sum, 1 ), '' ), array( 'Total weight', gpa_ex_trim( $wsum ) . '%', '÷' ), array( 'Course grade', gpa_ex_num( $grade, 1 ) . '%', '=' ) ) );
    }

    if ( 'weighted' === $a['type'] ) {
        $boosts = array();
        foreach ( explode( ',', $a['boosts'] ) as $pair ) {
            $kv = array_map( 'trim', explode( ':', $pair ) );
            if ( 2 === count( $kv ) ) { $boosts[ strtolower( $kv[0] ) ] = (float) $kv[1]; }
        }
        $qp = 0.0; $uqp = 0.0; $cr = 0.0; $out = array();
        foreach ( $rows as $r ) {
            list( $course, $level, $grade, $credits ) = array_pad( $r, 4, '1' );
            $base = gpa_points_for( $grade );
            if ( null === $base ) { return ''; }
            $key = strtolower( preg_replace( '/[^a-z]/i', '', explode( '/', $level )[0] ) );
            $boost = ( $base > 0 && isset( $boosts[ $key ] ) ) ? $boosts[ $key ] : 0.0;
            $pts = $base + $boost; $c = (float) $credits; $q = round( $pts * $c, 1 );
            $qp += $q; $uqp += round( $base * $c, 1 ); $cr += $c;
            $tag = '<span class="gpa-ex__tag">' . esc_html( $level ) . ( $boost ? ' +' . gpa_ex_trim( $boost ) : '' ) . '</span>';
            $out[] = array( $course, $badge( $grade ), $tag, '→', gpa_ex_num( $pts, 1 ), '×', gpa_ex_trim( $c ) . '<span class="gpa-ex__unit" aria-hidden="true"> cr</span>', '=', gpa_ex_num( $q, 1 ) );
        }
        $gpa = $cr ? $qp / $cr : 0; $ugpa = $cr ? $uqp / $cr : 0;
        return gpa_ex_render( $a['caption'] ?: 'Worked example: weighted GPA with Honors and AP boosts',
            array( 'Grade + level → points × credits', 'Quality pts' ),
            array( array( 'Course', 'course' ), array( 'Grade', 'grade' ), array( 'Level', 'level' ), array( '', 'op' ), array( 'Weighted points', 'pts' ), array( '', 'op' ), array( 'Credits', 'cr' ), array( '', 'op' ), array( 'Quality points', 'qp' ) ),
            $out,
            array( array( 'Total quality points', gpa_ex_num( $qp, 1 ), '' ), array( 'Total credits', gpa_ex_trim( $cr ), '÷' ), array( 'Weighted GPA', gpa_ex_num( $gpa, 2 ), '=' ) ),
            'Unweighted GPA, same grades without the level boost: <strong>' . gpa_ex_num( $uqp, 1 ) . ' ÷ ' . gpa_ex_trim( $cr ) . ' = ' . gpa_ex_num( $ugpa, 2 ) . '</strong>' );
    }

    // type="gpa"
    $qp = 0.0; $cr = 0.0; $out = array();
    foreach ( $rows as $r ) {
        list( $course, $grade, $credits ) = array_pad( $r, 3, '1' );
        $pts = isset( $r[3] ) && '' !== $r[3] ? (float) $r[3] : gpa_points_for( $grade );
        if ( null === $pts ) { return ''; }
        $c = (float) $credits; $q = round( $pts * $c, 1 ); $qp += $q; $cr += $c;
        $out[] = array( $course, $badge( $grade ), '→', gpa_ex_num( $pts, 1 ), '×', gpa_ex_trim( $c ) . '<span class="gpa-ex__unit" aria-hidden="true"> cr</span>', '=', gpa_ex_num( $q, 1 ) );
    }
    $gpa = $cr ? $qp / $cr : 0;
    return gpa_ex_render( $a['caption'] ?: 'Worked example: from letter grades to GPA',
        array( 'Grade → points × credits', 'Quality pts' ),
        array( array( 'Course', 'course' ), array( 'Grade', 'grade' ), array( '', 'op' ), array( 'Grade points', 'pts' ), array( '', 'op' ), array( 'Credits', 'cr' ), array( '', 'op' ), array( 'Quality points', 'qp' ) ),
        $out,
        array( array( 'Total quality points', gpa_ex_num( $qp, 1 ), '' ), array( 'Total credits', gpa_ex_trim( $cr ), '÷' ), array( 'GPA', gpa_ex_num( $gpa, 2 ), '=' ) ) );
}
add_action( 'init', function () { add_shortcode( 'gpa_example', 'gpa_example_shortcode' ); } );

/* Kept for [gpa_table type="worked-example|quality-points|content-sample"] (page 22 uses it). */
function gpa_render_worked_example( $example = 'college' ) {
    return gpa_example_shortcode( array( 'type' => 'gpa', 'example' => $example ) );
}

/* ==========================================================================
   /gpa-scale/ hub: quick converter and "Look up a GPA" cards
   [gpa_scale_converter]  letter grade -> grade points + % range + link to that GPA's page
   [gpa_scale_lookup]     every published /gpa-scale/<x-y>-gpa/ page from 2.0 up, as crawlable <a> cards
                          in two groups (unweighted 4.0 scale; weighted above 4.0). Groups with no pages are left out.
   Data: the same scale as the hub's letter-grade table; each GPA page's letter and % from its own text.
   ========================================================================== */

/** The hub table's scale: letter => [grade points, percentage range]. */
function gpa_scale_letter_rows() {
	return array(
		'A+' => array( '4.0', '97–100%' ), 'A' => array( '4.0', '93–96%' ), 'A−' => array( '3.7', '90–92%' ),
		'B+' => array( '3.3', '87–89%' ), 'B' => array( '3.0', '83–86%' ), 'B−' => array( '2.7', '80–82%' ),
		'C+' => array( '2.3', '77–79%' ), 'C' => array( '2.0', '73–76%' ), 'C−' => array( '1.7', '70–72%' ),
		'D+' => array( '1.3', '67–69%' ), 'D' => array( '1.0', '63–66%' ), 'D−' => array( '0.7', '60–62%' ),
		'F'  => array( '0.0', 'Below 60%' ),
	);
}

/**
 * The site-wide rule for a GPA's letter and percentage (chart: A+ 97–100 and A 93–96 both 4.0).
 * Letter: the nearest chart letter (both letters on a tie, e.g. 3.5 = "B+/A−"). Percentage: interpolated between
 * the midpoints of the chart letters' ranges and shown with "≈"; a GPA that is a chart value shows that range.
 * Returns [ letter, percentage text, rounded percentage or null ]. Mirrors gpaScaleFigures() in gpa-scale-tools.js.
 */
function gpa_scale_figures( $gpa ) {
	$g      = round( (float) $gpa, 2 );
	$points = array( array( 4.0, 'A', 94.5, '93–100%' ), array( 3.7, 'A−', 91, '90–92%' ), array( 3.3, 'B+', 88, '87–89%' ),
		array( 3.0, 'B', 84.5, '83–86%' ), array( 2.7, 'B−', 81, '80–82%' ), array( 2.3, 'C+', 78, '77–79%' ),
		array( 2.0, 'C', 74.5, '73–76%' ), array( 1.7, 'C−', 71, '70–72%' ), array( 1.3, 'D+', 68, '67–69%' ),
		array( 1.0, 'D', 64.5, '63–66%' ), array( 0.7, 'D−', 61, '60–62%' ), array( 0.0, 'F', 50, 'Below 60%' ) );
	if ( $g > 4.0 ) {
		return array( 'Weighted', '', null );
	}
	foreach ( $points as $pt ) {
		if ( abs( $pt[0] - $g ) < 0.001 ) {
			return array( $pt[1], $pt[3], null );
		}
	}
	for ( $i = 0; $i < count( $points ) - 1; $i++ ) {
		list( $hi, $hl, $hp ) = $points[ $i ];
		list( $lo, $ll, $lp ) = $points[ $i + 1 ];
		if ( $g < $hi && $g > $lo ) {
			$dh     = $hi - $g;
			$dl     = $g - $lo;
			$letter = abs( $dh - $dl ) < 0.001 ? $ll . '/' . $hl : ( $dh < $dl ? $hl : $ll );
			$pct    = (int) floor( $lp + ( $hp - $lp ) * ( $g - $lo ) / ( $hi - $lo ) + 0.5 );
			return array( $letter, '≈' . $pct . '%', $pct );
		}
	}
	return array( 'F', 'Below 60%', null );
}

/** Published GPA pages under the hub: [ '3.8' => permalink, ... ], highest first. Filterable for previews. */
function gpa_scale_gpa_pages() {
	static $pages = null;
	if ( null !== $pages ) {
		return $pages;
	}
	$pages = array();
	$hub   = get_page_by_path( 'gpa-scale' );
	if ( $hub ) {
		foreach ( get_pages( array( 'parent' => $hub->ID, 'post_status' => 'publish' ) ) as $p ) {
			if ( preg_match( '#^([0-9])-([0-9])-gpa$#', $p->post_name, $m ) ) {
				$pages[ $m[1] . '.' . $m[2] ] = get_permalink( $p );
			}
		}
	}
	$pages = apply_filters( 'gpa_scale_gpa_pages', $pages );
	uksort( $pages, function ( $a, $b ) { return (float) $b <=> (float) $a; } );
	return $pages;
}

function gpa_scale_enqueue_tools() {
	wp_enqueue_script( 'gpa-scale-tools', get_stylesheet_directory_uri() . '/gpa-scale-tools.js', array(), gpa_asset_ver( 'gpa-scale-tools.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

add_action( 'init', function () {
	add_shortcode( 'gpa_scale_converter', 'gpa_scale_converter_shortcode' );
	add_shortcode( 'gpa_scale_lookup', 'gpa_scale_lookup_shortcode' );
} );

function gpa_scale_converter_shortcode() {
	$pages   = gpa_scale_gpa_pages();
	$default = 'A';
	$opts    = '';
	foreach ( gpa_scale_letter_rows() as $letter => $row ) {
		$url   = isset( $pages[ $row[0] ] ) ? $pages[ $row[0] ] : '';
		$opts .= sprintf(
			'<option value="%1$s" data-points="%2$s" data-range="%3$s" data-url="%4$s"%5$s>%1$s</option>',
			esc_attr( $letter ), esc_attr( $row[0] ), esc_attr( $row[1] ), esc_url( $url ), selected( $letter, $default, false )
		);
	}
	list( $pts, $range ) = gpa_scale_letter_rows()[ $default ];
	$url = isset( $pages[ $pts ] ) ? $pages[ $pts ] : '';
	gpa_scale_enqueue_tools();
	return '<div class="gpa-quickconv" id="gpa-scale-converter" data-gpa-quickconv>'
		. '<label class="gpa-quickconv__field"><span class="gpa-quickconv__label">Letter grade</span>'
		. '<select class="gpa-quickconv__select" aria-describedby="gpa-quickconv-out">' . $opts . '</select></label>'
		. '<div class="gpa-quickconv__out" id="gpa-quickconv-out" aria-live="polite">'
		. '<div class="gpa-quickconv__stat"><span class="gpa-quickconv__label">Grade points</span><strong data-out="points">' . esc_html( $pts ) . '</strong></div>'
		. '<div class="gpa-quickconv__stat"><span class="gpa-quickconv__label">% range</span><strong data-out="range">' . esc_html( $range ) . '</strong></div>'
		. '<a class="gpa-quickconv__link" data-out="link" href="' . esc_url( $url ) . '"' . ( $url ? '' : ' hidden' ) . '>What a <span data-out="gpa">' . esc_html( $pts ) . '</span> GPA means <span aria-hidden="true">&rarr;</span></a>'
		. '</div></div>';
}

function gpa_scale_lookup_shortcode() {
	$pages   = gpa_scale_gpa_pages();
	$popular = array( '3.0', '3.5', '4.0' );
	$cards   = array();
	foreach ( $pages as $gpa => $url ) {
		$g = (float) $gpa;
		if ( $g < 2.0 ) {
			continue;
		}
		list( $letter, $pct ) = gpa_scale_figures( $g );
		$weighted = $g > 4.0;
		$sub      = $weighted ? 'AP/Honors scale' : $letter . ', ' . $pct;
		$pop      = in_array( $gpa, $popular, true );
		$tag      = $weighted ? '<span class="gpa-lookup__tag is-weighted">Weighted</span>' : ( $pop ? '<span class="gpa-lookup__tag">Popular</span>' : '' );
		$cards[]  = sprintf(
			'<li><a class="gpa-lookup__card%1$s%2$s" href="%3$s"><span class="gpa-lookup__num">%4$s</span><span class="gpa-lookup__sub">%5$s</span>%6$s</a></li>',
			$pop ? ' is-popular' : '', $weighted ? ' is-weighted' : '', esc_url( $url ), esc_html( $gpa ), esc_html( $sub ), $tag
		);
	}
	return '<div class="gpa-lookup"><ul class="gpa-lookup__grid">' . implode( '', $cards ) . '</ul></div>';
}

/**
 * [gpa_scale_view] on a /gpa-scale/<x-y>-gpa/ page: Unweighted | Weighted toggle, a summary box for each view and,
 * in the weighted view, a mini converter (AP/IB +1.0, Honors +0.5) -> estimated unweighted GPA, letter and %.
 * Both views are in the HTML (crawlable); gpa-scale-tools.js switches them and moves the chart marker.
 * Attributes: home_anchor (the page's homepage link text).
 */
function gpa_scale_view_shortcode( $atts ) {
	$atts  = shortcode_atts( array( 'home_anchor' => 'GPA calculator' ), $atts, 'gpa_scale_view' );
	$found = function_exists( 'gpa_scale_page_gpa' ) ? gpa_scale_page_gpa() : null;
	if ( ! $found ) {
		return '';
	}
	$g        = $found[0] / 10;
	$gs       = number_format( $g, 1 );
	$weighted = $g > 4.0;
	gpa_scale_enqueue_tools();

	// Weighted tab: estimator. Example load: 24 classes, 6 AP/IB (+1.0), 0 Honors.
	$ex_total = 24;
	$ex_ap    = $weighted ? (int) min( 24, ceil( ( $g - 4.0 ) * 24 ) + 6 ) : 6;
	$ex_g     = max( 0, min( 4.0, $g - $ex_ap / $ex_total ) );
	list( $ex_letter, $ex_pct ) = gpa_scale_figures( $ex_g );
	$num = function ( $name, $label, $value ) {
		return '<label class="gpa-view__field"><span class="gpa-view__label">' . $label . '</span>'
			. '<input type="number" inputmode="numeric" min="0" max="80" step="1" value="' . (int) $value . '" data-in="' . $name . '"></label>';
	};
	$weighted_tab = '<div class="gpa-view__panel" data-view="weighted"' . ( $weighted ? '' : ' hidden' ) . '>'
		. '<p class="gpa-view__note">A ' . esc_html( $gs ) . ' weighted GPA includes extra points for Honors, AP or IB classes, so the unweighted GPA behind it is lower. Enter your classes to estimate it:</p>'
		. '<div class="gpa-view__conv">'
		. $num( 'total', 'Total classes', $ex_total ) . $num( 'ap', 'AP / IB classes (+1.0)', $ex_ap ) . $num( 'honors', 'Honors classes (+0.5)', 0 )
		. '</div>'
		. '<dl class="gpa-view__tiles gpa-view__result" aria-live="polite">'
		. '<div class="gpa-view__tile is-headline"><dt>Est. unweighted GPA</dt><dd data-out="uw">' . esc_html( number_format( $ex_g, 2 ) ) . '</dd></div>'
		. '<div class="gpa-view__tile"><dt>Letter grade</dt><dd data-out="letter">' . esc_html( $ex_letter ) . '</dd></div>'
		. '<div class="gpa-view__tile"><dt>Percentage</dt><dd data-out="pct">' . esc_html( $ex_pct ) . '</dd></div>'
		. '</dl>'
		. '<p class="gpa-view__note gpa-view__fine">Uses +1.0 for AP/IB and +0.5 for Honors, the most common boosts; your school may differ. '
		. 'Our <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( $atts['home_anchor'] ) . '</a> works from your own classes and grades, and the '
		. '<a href="' . esc_url( home_url( '/weighted-gpa-calculator/' ) ) . '">Weighted GPA calculator</a> shows both numbers.</p>'
		. '</div>';

	// Unweighted tab: static summary of this page's GPA (no calculator).
	$unweighted_tab = '';
	if ( ! $weighted ) {
		list( $letter, $pct ) = gpa_scale_figures( $g );
		$rows    = gpa_scale_letter_rows();
		$ranges  = array();
		foreach ( explode( '/', $letter ) as $l ) {
			if ( isset( $rows[ $l ] ) ) { $ranges[] = $l . ' = ' . $rows[ $l ][1]; }
		}
		$typical = number_format( $g + 0.25, 2 );
		$unweighted_tab = '<div class="gpa-view__panel" data-view="unweighted">'
			. '<dl class="gpa-view__tiles">'
			. '<div class="gpa-view__tile"><dt>Letter grade</dt><dd>' . esc_html( $letter ) . '</dd></div>'
			. '<div class="gpa-view__tile"><dt>Percentage</dt><dd>' . esc_html( $pct ) . '<small>' . esc_html( implode( ' · ', $ranges ) ) . '</small></dd></div>'
			. '<div class="gpa-view__tile"><dt>Typical weighted GPA</dt><dd>≈' . esc_html( $typical ) . '<small>¼ of classes AP/IB</small></dd></div>'
			. '</dl>'
			. '</div>';
	}
	$conv_link = '<p class="gpa-view__note gpa-view__other">Look up any letter grade on the <a href="' . esc_url( home_url( '/gpa-scale/#gpa-scale-converter' ) ) . '">GPA scale</a>, or use <a href="' . esc_url( home_url( '/grade-conversion/' ) ) . '">Grade Conversion</a>.</p>';

	$toggle = $weighted ? '' : '<div class="gpa-view__toggle" role="group" aria-label="Read this GPA as">'
		. '<button type="button" class="gpa-view__btn" aria-pressed="true" data-view-btn="unweighted">Unweighted</button>'
		. '<button type="button" class="gpa-view__btn" aria-pressed="false" data-view-btn="weighted">Weighted</button>'
		. '</div>';
	return '<div class="gpa-view" data-gpa-view data-gpa="' . esc_attr( $gs ) . '" data-active="' . ( $weighted ? 'weighted' : 'unweighted' ) . '">'
		. $toggle . $unweighted_tab . $weighted_tab . $conv_link . '</div>';
}
add_action( 'init', function () { add_shortcode( 'gpa_scale_view', 'gpa_scale_view_shortcode' ); } );
