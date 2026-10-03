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
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'gpa_register_shortcodes');
function gpa_register_shortcodes() {
    add_shortcode('gpa_table', 'gpa_render_table_shortcode');
}

function gpa_render_table_shortcode($atts) {
    $atts = shortcode_atts([
        'type' => 'sample-courses',
    ], $atts, 'gpa_table');

    switch ($atts['type']) {
        case 'sample-courses':
            return gpa_render_sample_courses_table();
        case 'quality-points':
            return gpa_render_quality_points_table();
        case 'content-sample':
            return gpa_render_content_sample_table();
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
