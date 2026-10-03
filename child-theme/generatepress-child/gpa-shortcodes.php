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
 * Worked GPA example (design: "B · Formula reads across" / "B · On a phone").
 * One real <table>: Course | Grade | → | Grade points | × | Credits | = | Quality points, the operator columns
 * aria-hidden, and the result (total quality points ÷ total credits = GPA) attached as the <tfoot>. Phones restyle the
 * same markup (components.css, section 4b). Totals and GPA are computed here, so the numbers always add up.
 */
function gpa_worked_example_data( $example ) {
    $examples = array(
        'college' => array(
            'caption' => 'Worked example: a four-course college semester, from letter grades to a 3.41 GPA',
            'courses' => array(
                array( 'ENG 101 — English Composition', 'A', 4.0, 3 ),
                array( 'MATH 121 — Calculus I', 'B+', 3.3, 4 ),
                array( 'PSY 201 — Intro to Psychology', 'A-', 3.7, 3 ),
                array( 'BIO 110 — General Biology', 'C+', 2.3, 2 ),
            ),
        ),
        'content' => array(
            'caption' => 'Worked example: a three-course semester, from letter grades to GPA',
            'courses' => array(
                array( 'English 101', 'A', 4.0, 3 ),
                array( 'Math 121', 'B+', 3.3, 4 ),
                array( 'Biology 110', 'B', 3.0, 3 ),
            ),
        ),
    );
    return isset( $examples[ $example ] ) ? $examples[ $example ] : $examples['college'];
}

function gpa_grade_band( $grade ) {
    $l = strtoupper( substr( trim( $grade ), 0, 1 ) );
    return in_array( $l, array( 'A', 'B', 'C', 'D', 'F' ), true ) ? strtolower( $l ) : 'c';
}

function gpa_render_worked_example( $example = 'college' ) {
    $d   = gpa_worked_example_data( $example );
    $op  = function ( $sym ) { return '<td class="gpa-ex__op" aria-hidden="true">' . $sym . '</td>'; };
    $num = function ( $n, $dec ) { return number_format( (float) $n, $dec, '.', '' ); };
    $qp_total = 0.0;
    $cr_total = 0;
    $rows     = '';
    foreach ( $d['courses'] as $c ) {
        list( $name, $grade, $pts, $cr ) = $c;
        $qp        = round( $pts * $cr, 1 );
        $qp_total += $qp;
        $cr_total += $cr;
        $rows .= '<tr>'
            . '<th scope="row" class="gpa-ex__course">' . esc_html( $name ) . '</th>'
            . '<td class="gpa-ex__grade"><span class="gpa-ex__badge gpa-ex__badge--' . gpa_grade_band( $grade ) . '">' . esc_html( str_replace( '-', '–', $grade ) ) . '</span></td>'
            . $op( '→' )
            . '<td class="gpa-ex__pts">' . $num( $pts, 1 ) . '</td>'
            . $op( '×' )
            . '<td class="gpa-ex__cr">' . (int) $cr . '<span class="gpa-ex__unit" aria-hidden="true"> cr</span></td>'
            . $op( '=' )
            . '<td class="gpa-ex__qp">' . $num( $qp, 1 ) . '</td>'
            . '</tr>';
    }
    $gpa = $cr_total ? $qp_total / $cr_total : 0;
    $th_op = '<th class="gpa-ex__op" aria-hidden="true"></th>';
    return '<figure class="gpa-ex">'
        . '<div class="gpa-ex__mhead" aria-hidden="true"><span>Grade → points × credits</span><span>Quality pts</span></div>'
        . '<table class="gpa-ex__table">'
        . '<caption class="screen-reader-text">' . esc_html( $d['caption'] ) . '</caption>'
        . '<thead><tr>'
        . '<th scope="col" class="gpa-ex__course">Course</th><th scope="col">Grade</th>' . $th_op
        . '<th scope="col">Grade points</th>' . $th_op . '<th scope="col">Credits</th>' . $th_op
        . '<th scope="col">Quality points</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '<tfoot><tr><td colspan="8"><div class="gpa-ex__result">'
        . '<div class="gpa-ex__tile"><span class="gpa-ex__label">Total quality points</span> <span class="gpa-ex__value">' . $num( $qp_total, 1 ) . '</span></div>'
        . '<span class="gpa-ex__rop" aria-hidden="true">÷</span>'
        . '<div class="gpa-ex__tile gpa-ex__tile--div"><span class="gpa-ex__label">Total credits</span> <span class="gpa-ex__value">' . (int) $cr_total . '</span></div>'
        . '<span class="gpa-ex__rop" aria-hidden="true">=</span>'
        . '<div class="gpa-ex__tile gpa-ex__tile--gpa"><span class="gpa-ex__label">GPA</span> <span class="gpa-ex__value">' . $num( $gpa, 2 ) . '</span></div>'
        . '</div></td></tr></tfoot>'
        . '</table></figure>';
}
