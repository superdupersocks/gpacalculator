<?php
/**
 * Template for single college admissions pages
 * Custom Post Type: colleges
 * Theme: GeneratePress Child Theme
 *
 * CSS classes prefixed with db- to avoid conflicts.
 * Requires: Advanced Custom Fields (ACF), database-page.css
 *
 * Updated: descriptive H1, missing data is hidden instead of shown as "N/A",
 * GPA shown with the same precision as the page title, acceptance rates stored
 * as fractions (0.81) are displayed correctly, FAQs only appear when there is data.
 */

get_header();

// Get all ACF fields
$college_name = get_the_title();
$post_id      = get_the_ID();

/**
 * True when a field holds real data (not blank, "N/A", "-", "Not Reported", etc.).
 */
$has = function ( $value ) {
    $v = strtolower( trim( (string) $value ) );
    return '' !== $v && ! in_array( $v, array( 'n/a', 'na', '-', '–', 'not reported', 'unavailable', 'none', 'null' ), true );
};

// Hero fields
$location              = get_field('location');
$owning                = get_field('owning');
$acceptance_rate       = get_field('acceptance_rate');
$enrollment            = get_field('enrollment');

// Key Highlights
$average_gpa           = get_field('average_gpa');
$sat_range             = get_field('sat_range');
$act_range             = get_field('act_range');
$admission_standards   = get_field('admission_standards');
$net_price_raw         = get_field('net_price');
$applicant_competition = get_field('applicant_competition');

// Clean net price for display
$net_price_clean   = str_replace(array('$', ','), '', (string) $net_price_raw);
$net_price_num     = is_numeric($net_price_clean) ? (int) $net_price_clean : 0;
$net_price_display = $net_price_num > 0 ? '$' . number_format($net_price_num) : '';

// SAT Score fields
$sat_reading_25        = get_field('sat_reading_25');
$sat_reading_75        = get_field('sat_reading_75');
$sat_math_25           = get_field('sat_math_25');
$sat_math_75           = get_field('sat_math_75');
$sat_composite_25      = get_field('sat_composite_25');
$sat_composite_75      = get_field('sat_composite_75');
$average_sat_score     = get_field('average_sat_score');
$applicants_submitting_sat = get_field('applicants_submitting_sat');

// ACT Score fields
$act_reading_25        = get_field('act_reading_25');
$act_reading_75        = get_field('act_reading_75');
$act_math_25           = get_field('act_math_25');
$act_math_75           = get_field('act_math_75');
$act_composite_25      = get_field('act_composite_25');
$act_composite_75      = get_field('act_composite_75');
$average_act_score     = get_field('average_act_score');
$applicant_submitting_act = get_field('applicant_submitting_act');

// Admission Requirements
$req_test_scores       = get_field('admission_requirements_test_scores');
$req_gpa               = get_field('admission_requirements_high_school_gpa');
$req_class_rank        = get_field('admission_requirements_high_school_class_rank');
$req_college_prep      = get_field('admission_requirements_completion_of_college_preparatory_program');
$req_recommendations   = get_field('admission_requirements_recommendations');
$req_competencies      = get_field('admission_requirements_demonstration_of_competencies');

// Credit Options
$ap_credit             = get_field('ap_credit');
$dual_credit           = get_field('dual_credit');
$credit_life           = get_field('credit_for_life_experiences');

// Acceptance rate: fix values stored as fractions (e.g. 0.81 => 81%)
$acceptance_raw      = trim( (string) $acceptance_rate );
$acceptance_rate_num = (float) preg_replace( '/[^0-9.]/', '', $acceptance_raw );
if ( $acceptance_rate_num > 0 && $acceptance_rate_num <= 1 && false === strpos( $acceptance_raw, '%' ) ) {
    $acceptance_rate_num *= 100;
}
$has_acceptance     = $acceptance_rate_num > 0;
$acceptance_display = $has_acceptance ? round( $acceptance_rate_num, 1 ) . '%' : '';
$acceptance_display = str_replace( '.0%', '%', $acceptance_display );

if ($acceptance_rate_num > 0 && $acceptance_rate_num < 20) {
    $acceptance_color_class = 'db-badge--red';
} elseif ($acceptance_rate_num < 50) {
    $acceptance_color_class = 'db-badge--orange';
} else {
    $acceptance_color_class = 'db-badge--green';
}

// GPA: same precision as the page title (3.94 stays 3.94)
if ( function_exists( 'gpa_college_gpa_txt' ) ) {
    $gpa_fmt = gpa_college_gpa_txt( $post_id );
} else {
    $gpa_fmt = gpa_fmt_gpa( $average_gpa );
}

// Ranges with a proper en dash, or '' when missing
$sat_range_display = $has( $sat_range ) ? str_replace( '-', '–', $sat_range ) : '';
$act_range_display = $has( $act_range ) ? str_replace( '-', '–', $act_range ) : '';

// Format enrollment number
$enrollment_clean = str_replace(',', '', (string) $enrollment);
$enrollment_num   = is_numeric($enrollment_clean) ? number_format((int) $enrollment_clean) : ( $has( $enrollment ) ? $enrollment : '' );

// Image URL (from CSV import via ACF)
$img_url = get_field('img_url');

// Hero background: prefer featured image, fall back to ACF img_url from CSV import
$hero_bg = '';
if (has_post_thumbnail()) {
    $hero_bg = get_the_post_thumbnail_url(get_the_ID(), 'full');
} elseif ( ! empty( $img_url ) ) {
    $hero_bg = $img_url;
}
$hero_style = $hero_bg
    ? 'background-image: url(\'' . esc_url( $hero_bg ) . '\'); background-size: cover; background-position: center;'
    : 'background-image: linear-gradient(135deg, #1E3A8A 0%, #3B82F6 50%, #8B5CF6 100%);';

// Key Highlights cards that actually have data
$highlight_cards = array();
if ( '' !== $gpa_fmt ) {
    $highlight_cards[] = array( 'class' => 'db-stat-card--blue', 'value' => $gpa_fmt, 'label' => 'Average GPA', 'icon' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>' );
}
if ( '' !== $sat_range_display ) {
    $highlight_cards[] = array( 'class' => 'db-stat-card--purple', 'value' => $sat_range_display, 'label' => 'SAT Range', 'icon' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline>' );
}
if ( '' !== $act_range_display ) {
    $highlight_cards[] = array( 'class' => 'db-stat-card--green', 'value' => $act_range_display, 'label' => 'ACT Range', 'icon' => '<path d="M9 11H15M9 15H13M8 2H16C17.1046 2 18 2.89543 18 4V20C18 21.1046 17.1046 22 16 22H8C6.89543 22 6 21.1046 6 20V4C6 2.89543 6.89543 2 8 2Z"></path><path d="M9 7H15"></path>' );
}
$has_highlight_badges = $has( $admission_standards ) || '' !== $net_price_display || $has( $applicant_competition );

// SAT / ACT table rows that actually have data
$score_row = function ( $label, $low, $high, $icon, $highlight = false ) use ( $has ) {
    if ( ! $has( $low ) && ! $has( $high ) ) {
        return null;
    }
    return array(
        'label'     => $label,
        'low'       => $has( $low ) ? $low : '—',
        'high'      => $has( $high ) ? $high : '—',
        'icon'      => $icon,
        'highlight' => $highlight,
    );
};
$icon_reading   = '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path>';
$icon_math      = '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>';
$icon_composite = '<circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline>';

$sat_rows = array_values( array_filter( array(
    $score_row( 'Reading', $sat_reading_25, $sat_reading_75, $icon_reading ),
    $score_row( 'Math', $sat_math_25, $sat_math_75, $icon_math ),
    $score_row( 'Composite', $sat_composite_25, $sat_composite_75, $icon_composite, true ),
) ) );
$act_rows = array_values( array_filter( array(
    $score_row( 'Reading', $act_reading_25, $act_reading_75, $icon_reading ),
    $score_row( 'Math', $act_math_25, $act_math_75, $icon_math ),
    $score_row( 'Composite', $act_composite_25, $act_composite_75, $icon_composite, true ),
) ) );
$has_sat = ! empty( $sat_rows ) || $has( $average_sat_score );
$has_act = ! empty( $act_rows ) || $has( $average_act_score );
?>

<style>
    .db-hero__title-sub { display: block; font-size: 0.5em; font-weight: 600; opacity: 0.9; margin-top: 0.35em; line-height: 1.25; }
    .db-scores__empty { padding: 16px 20px; color: #64748b; font-size: 0.95em; margin: 0; }
</style>

<div class="db-college-profile">

    <?php if ( function_exists( 'gpa_render_breadcrumb' ) ) { gpa_render_breadcrumb(); } // T10.6 ?>

    <!-- ============================================
         SECTION 1: HERO
         ============================================ -->
    <section class="db-hero" style="<?php echo esc_attr( $hero_style ); ?>">
        <div class="db-hero__overlay"></div>
        <div class="db-hero__content">
               <h1 class="db-hero__title"><?php echo esc_html($college_name); ?> <span class="db-hero__title-sub"><?php echo '' !== $gpa_fmt ? 'GPA Requirements &amp; Admissions' : 'Admissions &amp; Acceptance Rate'; ?></span></h1>
            <?php if ( $has( $location ) ) : ?>
                <div class="db-hero__location">
                    <svg class="db-hero__location-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                    <span><?php echo esc_html($location); ?></span>
                </div>
            <?php endif; ?>

            <div class="db-hero__badges">
                <?php if ( $has( $owning ) ) : ?>
                    <span class="db-badge db-badge--blue">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <?php echo esc_html($owning); ?>
                    </span>
                <?php endif; ?>

                <?php if ( $has_acceptance ) : ?>
                    <span class="db-badge <?php echo esc_attr($acceptance_color_class); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                        Acceptance Rate: <?php echo esc_html( $acceptance_display ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( '' !== $enrollment_num ) : ?>
                    <span class="db-badge db-badge--purple">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                        Enrollment: <?php echo esc_html($enrollment_num); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="db-container">

        <!-- ============================================
             SECTION 2: KEY HIGHLIGHTS (only cards with data)
             ============================================ -->
        <?php if ( ! empty( $highlight_cards ) || $has_highlight_badges ) : ?>
        <section class="db-card db-highlights">
            <div class="db-card__header">
                <h2 class="db-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
                    </svg>
                    Key Highlights
                </h2>
            </div>

            <?php if ( ! empty( $highlight_cards ) ) : ?>
            <div class="db-highlights__grid">
                <?php foreach ( $highlight_cards as $card ) : ?>
                    <div class="db-stat-card <?php echo esc_attr( $card['class'] ); ?>">
                        <div class="db-stat-card__icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $card['icon']; ?></svg>
                        </div>
                        <div class="db-stat-card__value"><?php echo esc_html( $card['value'] ); ?></div>
                        <div class="db-stat-card__label"><?php echo esc_html( $card['label'] ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ( $has_highlight_badges ) : ?>
            <div class="db-highlights__badges">
                <?php if ( $has( $admission_standards ) ) : ?>
                    <div class="db-highlight-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <div class="db-highlight-badge__text">
                            <span class="db-highlight-badge__label">Admission Standards</span>
                            <span class="db-highlight-badge__value"><?php echo esc_html($admission_standards); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( '' !== $net_price_display ) : ?>
                    <div class="db-highlight-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                        <div class="db-highlight-badge__text">
                            <span class="db-highlight-badge__label">Net Price</span>
                            <span class="db-highlight-badge__value"><?php echo esc_html($net_price_display); ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ( $has( $applicant_competition ) ) : ?>
                    <div class="db-highlight-badge">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                            <polyline points="17 6 23 6 23 12"></polyline>
                        </svg>
                        <div class="db-highlight-badge__text">
                            <span class="db-highlight-badge__label">Competition Level</span>
                            <span class="db-highlight-badge__value"><?php echo esc_html($applicant_competition); ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ============================================
             SECTION 3: ADMISSION SCORES (only rows with data)
             ============================================ -->
        <section class="db-card db-scores">
            <div class="db-card__header">
                <h2 class="db-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="20" x2="18" y2="10"></line>
                        <line x1="12" y1="20" x2="12" y2="4"></line>
                        <line x1="6" y1="20" x2="6" y2="14"></line>
                    </svg>
                    Admission Scores
                </h2>
            </div>

            <?php if ( ! $has_sat && ! $has_act ) : ?>
                <p class="db-scores__empty"><?php echo esc_html( $college_name ); ?> does not report SAT or ACT scores for admitted students. Check the admission requirements below, or the school's admissions office, to see whether test scores are required.</p>
            <?php else : ?>
            <div class="db-scores__grid">
                <?php
                $score_columns = array(
                    array( 'name' => 'SAT', 'has' => $has_sat, 'rows' => $sat_rows, 'avg' => $average_sat_score, 'submit' => $applicants_submitting_sat, 'color' => 'blue' ),
                    array( 'name' => 'ACT', 'has' => $has_act, 'rows' => $act_rows, 'avg' => $average_act_score, 'submit' => $applicant_submitting_act, 'color' => 'green' ),
                );
                foreach ( $score_columns as $col ) :
                ?>
                <div class="db-scores__column">
                    <div class="db-scores__column-header db-scores__column-header--<?php echo esc_attr( $col['color'] ); ?>">
                        <h3 class="db-scores__column-title"><?php echo esc_html( $col['name'] ); ?> Scores</h3>
                        <?php if ( $has( $col['avg'] ) ) : ?>
                            <div class="db-scores__average">
                                <span class="db-scores__average-label">Average <?php echo esc_html( $col['name'] ); ?> Score</span>
                                <span class="db-scores__average-value"><?php echo esc_html( $col['avg'] ); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ( $has( $col['submit'] ) ) : ?>
                            <div class="db-scores__submitting">
                                <?php echo esc_html( gpa_fmt_pct( $col['submit'] ) ); ?> of applicants submit <?php echo esc_html( $col['name'] ); ?> scores
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="db-scores__body">
                        <?php if ( ! empty( $col['rows'] ) ) : ?>
                        <table class="db-scores__table" aria-label="<?php echo esc_attr( $col['name'] . ' score percentiles for ' . $college_name ); ?>">
                            <thead>
                                <tr>
                                    <th scope="col">Section</th>
                                    <th scope="col">25th percentile</th>
                                    <th scope="col">75th percentile</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $col['rows'] as $row ) : ?>
                                <tr<?php echo $row['highlight'] ? ' class="db-scores__row--highlight"' : ''; ?>>
                                    <th scope="row" class="db-scores__row-label">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><?php echo $row['icon']; ?></svg>
                                        <?php echo esc_html( $row['label'] ); ?>
                                    </th>
                                    <td><?php echo esc_html( $row['low'] ); ?></td>
                                    <td><?php echo esc_html( $row['high'] ); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php elseif ( ! $col['has'] ) : ?>
                            <p class="db-scores__empty"><?php echo esc_html( $col['name'] ); ?> scores not reported.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

<!-- Tag ID: gpacalculator-net_incontent_midarticle -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_midarticle">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_midarticle", slotId: "gpacalculator-net_incontent_midarticle" });
  </script>
</div>


        <!-- ============================================
             SECTION 4: ADMISSION REQUIREMENTS
             ============================================ -->
        <?php
        // Build requirements array
        $requirements = array(
            array(
                'label'       => 'Test Scores',
                'value'       => $req_test_scores,
                'description' => 'Standardized test scores (SAT/ACT) as part of your application.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>',
            ),
            array(
                'label'       => 'High School GPA',
                'value'       => $req_gpa,
                'description' => 'Your cumulative high school grade point average.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"></path><path d="M6 12v5c3 3 8 3 12 0v-5"></path></svg>',
            ),
            array(
                'label'       => 'High School Class Rank',
                'value'       => $req_class_rank,
                'description' => 'Your ranking relative to other students in your graduating class.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>',
            ),
            array(
                'label'       => 'College Preparatory Program',
                'value'       => $req_college_prep,
                'description' => 'Completion of a college preparatory curriculum in high school.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>',
            ),
            array(
                'label'       => 'Recommendations',
                'value'       => $req_recommendations,
                'description' => 'Letters of recommendation from teachers, counselors, or other mentors.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>',
            ),
            array(
                'label'       => 'Demonstration of Competencies',
                'value'       => $req_competencies,
                'description' => 'Evidence of specific skills or competencies through portfolios, auditions, or other demonstrations.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
            ),
        );
        ?>

        <section class="db-card db-requirements">
            <div class="db-card__header">
                <h2 class="db-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                    Admission Requirements
                </h2>
            </div>

            <div class="db-requirements__grid">
                <?php foreach ($requirements as $req) :
                    $is_required = false;
                    $status_text = 'Not Required';
                    $value_lower = strtolower(trim($req['value'] ?? ''));

                    if (
                        $value_lower === 'required' ||
                        $value_lower === 'yes' ||
                        $value_lower === 'recommended' ||
                        $value_lower === 'considered'
                    ) {
                        $is_required = true;
                        $status_text = ucfirst($value_lower);
                    } elseif (!empty($req['value'])) {
                        $status_text = $req['value'];
                        if (
                            $value_lower !== 'not required' &&
                            $value_lower !== 'no' &&
                            $value_lower !== 'neither required nor recommended'
                        ) {
                            $is_required = true;
                        }
                    }
                ?>
                    <div class="db-requirement-item <?php echo $is_required ? 'db-requirement-item--required' : 'db-requirement-item--not-required'; ?>">
                        <div class="db-requirement-item__icon">
                            <?php echo $req['icon']; ?>
                        </div>
                        <div class="db-requirement-item__content">
                            <div class="db-requirement-item__header">
                                <span class="db-requirement-item__label"><?php echo esc_html($req['label']); ?></span>
                                <span class="db-requirement-item__status <?php echo $is_required ? 'db-requirement-item__status--green' : 'db-requirement-item__status--gray'; ?>">
                                    <?php if ($is_required) : ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                        </svg>
                                    <?php else : ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <line x1="15" y1="9" x2="9" y2="15"></line>
                                            <line x1="9" y1="9" x2="15" y2="15"></line>
                                        </svg>
                                    <?php endif; ?>
                                    <?php echo esc_html($status_text); ?>
                                </span>
                            </div>
                            <p class="db-requirement-item__description"><?php echo esc_html($req['description']); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="db-info-box db-info-box--blue">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="16" x2="12" y2="12"></line>
                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
                <p>Admission requirements may vary by program and applicant type. Always check the official <?php echo esc_html($college_name); ?> admissions page for the most current requirements and deadlines.</p>
            </div>
        </section>

        <!-- ============================================
             SECTION 5: CREDIT OPTIONS
             ============================================ -->
        <?php
        $credit_options = array(
            array(
                'label'       => 'AP Credit',
                'value'       => $ap_credit,
                'description' => 'Transfer Advanced Placement (AP) exam scores for college credit.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>',
            ),
            array(
                'label'       => 'Dual Credit',
                'value'       => $dual_credit,
                'description' => 'Earn college credits while still enrolled in high school through dual enrollment programs.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>',
            ),
            array(
                'label'       => 'Life Experience Credit',
                'value'       => $credit_life,
                'description' => 'Receive credit for knowledge gained through work, military service, or other life experiences.',
                'icon'        => '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M8 14s1.5 2 4 2 4-2 4-2"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="15" y1="9" x2="15.01" y2="9"></line></svg>',
            ),
        );
        ?>

        <section class="db-card db-credits">
            <div class="db-card__header">
                <h2 class="db-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                    Credit Options
                </h2>
            </div>

            <div class="db-credits__grid">
                <?php foreach ($credit_options as $credit) :
                    $is_available = strtolower(trim($credit['value'] ?? '')) === 'yes';
                ?>
                    <div class="db-credit-card <?php echo $is_available ? 'db-credit-card--available' : 'db-credit-card--unavailable'; ?>">
                        <div class="db-credit-card__icon-wrap <?php echo $is_available ? 'db-credit-card__icon-wrap--green' : 'db-credit-card__icon-wrap--gray'; ?>">
                            <?php echo $credit['icon']; ?>
                        </div>
                        <h3 class="db-credit-card__title"><?php echo esc_html($credit['label']); ?></h3>
                        <span class="db-credit-card__status <?php echo $is_available ? 'db-credit-card__status--green' : 'db-credit-card__status--gray'; ?>">
                            <?php if ($is_available) : ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                </svg>
                                Available
                            <?php else : ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                                Not Available
                            <?php endif; ?>
                        </span>
                        <p class="db-credit-card__description"><?php echo esc_html($credit['description']); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="db-info-box db-info-box--amber">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <p>Credit policies and limits vary by department. Contact the <?php echo esc_html($college_name); ?> admissions office or registrar for specific credit transfer policies and maximum credit allowances.</p>
            </div>
        </section>

        <!-- ============================================
             SECTION 6: FAQ (only questions we can answer with data)
             ============================================ -->
        <?php
        $sat_answer_value   = $has( $average_sat_score ) ? $average_sat_score : $sat_range_display;
        $sat_submit_display = $has( $applicants_submitting_sat ) ? gpa_fmt_pct( $applicants_submitting_sat ) : '';

        $faqs = array();

        if ( '' !== $gpa_fmt ) {
            $faqs[] = array(
                'question' => 'What GPA do I need to get into ' . $college_name . '?',
                'answer'   => 'The average GPA of admitted students at ' . $college_name . ' is <strong>' . esc_html($gpa_fmt) . '</strong>. While this is the average, ' . $college_name . ' considers your entire application holistically. A strong GPA combined with extracurricular activities, essays, and recommendations can strengthen your application. We recommend aiming for a GPA at or above the average to be competitive.',
            );
        }
        if ( '' !== $sat_answer_value ) {
            $faqs[] = array(
                'question' => 'What SAT score is required for ' . $college_name . '?',
                'answer'   => ( $has( $average_sat_score ) ? 'The average SAT score for admitted students at ' : 'The middle 50% SAT range for admitted students at ' ) . $college_name . ' is <strong>' . esc_html($sat_answer_value) . '</strong>. ' . ($sat_submit_display ? esc_html($sat_submit_display) . ' of applicants submit SAT scores. ' : '') . 'Keep in mind that admissions decisions are based on multiple factors beyond test scores. Check the admission requirements section above to see if test scores are required or optional for your application.',
            );
        }
        if ( $has_acceptance ) {
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college_name . '?',
                'answer'   => $college_name . ' has an acceptance rate of <strong>' . esc_html($acceptance_display) . '</strong>. ' . ($acceptance_rate_num < 20 ? 'This makes it a highly selective institution. Applicants should ensure every component of their application is as strong as possible.' : ($acceptance_rate_num < 50 ? 'This indicates a moderately selective admissions process. A solid academic record and well-rounded application will improve your chances.' : 'This means the school accepts a relatively high proportion of applicants. Focus on meeting the basic requirements and submitting a complete application.')) . ( '' !== $enrollment_num ? ' With an enrollment of ' . esc_html($enrollment_num) . ' students, competition can vary by program.' : '' ),
            );
        }
        if ( '' !== $net_price_display ) {
            $faqs[] = array(
                'question' => 'How much does it cost to attend ' . $college_name . '?',
                'answer'   => 'The estimated net price to attend ' . $college_name . ' is <strong>' . esc_html($net_price_display) . '</strong> per year. Net price represents the average cost after financial aid and scholarships are applied. Actual costs may vary based on your financial situation, residency status, and the financial aid package you receive. Contact the financial aid office for a personalized estimate.',
            );
        }
        if ( $has( $admission_standards ) ) {
            $faqs[] = array(
                'question' => 'How competitive is admission to ' . $college_name . '?',
                'answer'   => $college_name . ' has <strong>' . esc_html($admission_standards) . '</strong> admission standards' . ( $has( $applicant_competition ) ? ' with a <strong>' . esc_html($applicant_competition) . '</strong> level of applicant competition' : '') . '. ' . ('' !== $gpa_fmt ? 'The average admitted student has a GPA of ' . esc_html($gpa_fmt) . '. ' : '') . 'To improve your chances, focus on maintaining strong grades, preparing well for standardized tests, and building a well-rounded application with meaningful extracurricular activities.',
            );
        }
        ?>

        <?php if ( ! empty( $faqs ) ) : ?>
        <section class="db-card db-faq">
            <div class="db-card__header">
                <h2 class="db-card__title">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
                        <line x1="12" y1="17" x2="12.01" y2="17"></line>
                    </svg>
                    Frequently Asked Questions
                </h2>
            </div>

            <div class="db-faq__list">
                <?php foreach ($faqs as $index => $faq) : ?>
                    <div class="db-faq__item">
                        <button class="db-faq__question" aria-expanded="false" aria-controls="db-faq-answer-<?php echo esc_attr($index); ?>" onclick="this.classList.toggle('db-faq__question--active'); var answer = document.getElementById('db-faq-answer-<?php echo esc_js($index); ?>'); answer.classList.toggle('db-faq__answer--open'); this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');">
                            <span><?php echo esc_html($faq['question']); ?></span>
                            <svg class="db-faq__chevron" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        <div class="db-faq__answer" id="db-faq-answer-<?php echo esc_attr($index); ?>">
                            <div>
                                <p><?php echo wp_kses_post($faq['answer']); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

<!-- Tag ID: gpacalculator-net_incontent_bottom -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_bottom">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_bottom", slotId: "gpacalculator-net_incontent_bottom" });
  </script>
</div>


    </div><!-- .db-container -->

</div><!-- .db-college-profile -->

<?php get_footer(); ?>