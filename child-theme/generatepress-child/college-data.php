<?php
/**
 * College profiles (/admissions/<slug>/): the federal data from Admissions Phase 2, checkpoint E.
 *
 * scripts/admissions/phase2_e_live.sh writes each confidently matched college's IPEDS figures into its post, with
 * `ipeds_unitid` and the year of each figure (data/admissions/audit/phase2_e_import.csv). Those pages show every
 * figure with its year and cite their sources; pages the import hasn't reached keep their older fields.
 * gpa_college_faqs() builds the questions once, for the page and its FAQPage JSON-LD, so the two always match.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'gpa_college_has' ) ) {
    // True when a field holds real data (not blank, "N/A", "-", "Not Reported", ...).
    function gpa_college_has( $value ) {
        $v = strtolower( trim( (string) $value ) );
        return '' !== $v && ! in_array( $v, array( 'n/a', 'na', '-', '–', 'not reported', 'unavailable', 'none', 'null' ), true );
    }
}

if ( ! function_exists( 'gpa_college_fresh' ) ) {
    /**
     * The imported federal data of a college post, or null when the import hasn't reached it. Percentiles are
     * [25th, 50th, 75th] (null where not reported), keyed by section, only for sections with a 25th or 75th.
     */
    function gpa_college_fresh( $post_id ) {
        static $cache = array();
        $post_id = (int) $post_id;
        if ( array_key_exists( $post_id, $cache ) ) {
            return $cache[ $post_id ];
        }
        $meta = function ( $key ) use ( $post_id ) {
            return trim( (string) get_post_meta( $post_id, $key, true ) );
        };
        $num = function ( $key ) use ( $meta ) {
            $v = str_replace( array( ',', '$', '%' ), '', $meta( $key ) );
            return is_numeric( $v ) ? $v + 0 : null;
        };
        $unitid = $meta( 'ipeds_unitid' );
        if ( ! ctype_digit( $unitid ) ) {
            $cache[ $post_id ] = null;
            return null;
        }
        $sections = function ( array $parts ) use ( $num ) {
            $out = array();
            foreach ( $parts as $label => $key ) {
                $p = array( $num( $key . '_25' ), $num( $key . '_50' ), $num( $key . '_75' ) );
                if ( null !== $p[0] || null !== $p[2] ) {
                    $out[ $label ] = $p;
                }
            }
            return $out;
        };
        $requirements = array();
        foreach ( gpa_college_requirement_items() as $field => $item ) {
            $label = $meta( $field );
            if ( '' !== $label ) {
                $requirements[ $field ] = $label;
            }
        }
        $year = $meta( 'adm_year' );
        $f    = array(
            'unitid'            => $unitid,
            'name'              => $meta( 'ipeds_name' ),
            'control'           => $meta( 'college_control' ),
            'level'             => $meta( 'college_level' ),
            'fall'              => ctype_digit( $year ) ? 'fall ' . $year : '',
            'open'              => 'Yes' === $meta( 'adm_open_admission' ),
            'open_year'         => $meta( 'open_admission_year' ),
            'rate'              => $num( 'acceptance_rate' ),
            'rate_txt'          => $meta( 'acceptance_rate' ),
            'applicants'        => $num( 'adm_applicants' ),
            'admits'            => $num( 'adm_admits' ),
            'sat'               => $sections( array( 'Reading and Writing' => 'sat_reading', 'Math' => 'sat_math' ) ),
            'act'               => $sections( array( 'Composite' => 'act_composite', 'English' => 'act_english', 'Math' => 'act_math' ) ),
            'sat_submit'        => $num( 'applicants_submitting_sat' ),
            'act_submit'        => $num( 'applicant_submitting_act' ),
            'sat_avg'           => $num( 'average_sat_score' ),
            'enrollment'        => $num( 'enrollment' ),
            'enrollment_year'   => $meta( 'enrollment_year' ),
            'net_price'         => $num( 'net_price' ),
            'net_price_year'    => $meta( 'net_price_year' ),
            'net_price_scope'   => $meta( 'net_price_scope' ),
            'requirements'      => $requirements,
            'ap'                => $meta( 'ap_credit' ),
            'life'              => $meta( 'credit_for_life_experiences' ),
            'credits_year'      => $meta( 'credits_year' ),
            'ipeds_release'     => $meta( 'ipeds_release' ),
            'scorecard_release' => $meta( 'scorecard_release' ),
        );
        $cache[ $post_id ] = $f;
        return $f;
    }
}

if ( ! function_exists( 'gpa_college_requirement_items' ) ) {
    // The admission factors IPEDS asks about, in page order: field => [label, what it means].
    function gpa_college_requirement_items() {
        return array(
            'admission_requirements_high_school_gpa'                           => array( 'High School GPA', 'Your cumulative high school grade point average.' ),
            'admission_requirements_secondary_school_record'                   => array( 'High School Record', 'Your transcript: the courses you took and the grades you earned.' ),
            'admission_requirements_high_school_class_rank'                    => array( 'High School Class Rank', 'Your ranking relative to other students in your graduating class.' ),
            'admission_requirements_completion_of_college_preparatory_program' => array( 'College Preparatory Program', 'Completion of a college preparatory curriculum in high school.' ),
            'admission_requirements_recommendations'                           => array( 'Recommendations', 'Letters of recommendation from teachers, counselors, or other mentors.' ),
            'admission_requirements_personal_statement_or_essay'               => array( 'Essay or Personal Statement', 'A personal statement or essay written for your application.' ),
            'admission_requirements_test_scores'                               => array( 'Test Scores', 'SAT or ACT scores.' ),
            'admission_requirements_other_test'                                => array( 'Other Tests', 'Other admission tests, such as the Wonderlic or WISC-III.' ),
            'admission_requirements_demonstration_of_competencies'             => array( 'Demonstration of Competencies', 'Evidence of specific skills through portfolios, auditions, or other demonstrations.' ),
            'admission_requirements_work_experience'                           => array( 'Work Experience', 'Paid or volunteer work.' ),
            'admission_requirements_legacy_status'                             => array( 'Legacy Status', 'Whether a parent or other relative attended the college.' ),
        );
    }
}

if ( ! function_exists( 'gpa_college_requirement_status' ) ) {
    // IPEDS's wording for an admission factor -> [short status, whether the college uses it]; '' when unclear.
    function gpa_college_requirement_status( $label ) {
        $l = strtolower( trim( (string) $label ) );
        if ( 0 === strpos( $l, 'required' ) ) {
            return array( 'Required', true );
        }
        if ( false !== strpos( $l, 'test optional' ) ) {
            return array( 'Test optional', true );
        }
        if ( false !== strpos( $l, 'test blind' ) ) {
            return array( 'Test blind', false );
        }
        if ( 0 === strpos( $l, 'not required' ) || 0 === strpos( $l, 'considered' ) ) {
            return array( 'Considered if submitted', true );
        }
        if ( 0 === strpos( $l, 'not considered' ) ) {
            return array( 'Not considered', false );
        }
        if ( 0 === strpos( $l, 'recommended' ) ) {
            return array( 'Recommended', true );
        }
        return array( '', false );
    }
}

if ( ! function_exists( 'gpa_college_pct_txt' ) ) {
    // 3.6 -> "3.6%", 43 -> "43%": one decimal under 10%, so very selective colleges keep their precision.
    function gpa_college_pct_txt( $pct ) {
        $pct = (float) $pct;
        if ( round( $pct, 1 ) < 10 ) {
            return rtrim( rtrim( number_format( $pct, 1 ), '0' ), '.' ) . '%';
        }
        return round( $pct ) . '%';
    }
}

if ( ! function_exists( 'gpa_college_range' ) ) {
    // [25th, 50th, 75th] -> "640–720", or '' without both ends.
    function gpa_college_range( array $p ) {
        return ( null !== $p[0] && null !== $p[2] ) ? $p[0] . '–' . $p[2] : '';
    }
}

if ( ! function_exists( 'gpa_college_type_phrase' ) ) {
    // "public 4-year college", "private nonprofit 4-year college", "private for-profit 2-year college", "private
    // for-profit school with programs under two years", or "college".
    function gpa_college_type_phrase( $post_id ) {
        $own = trim( (string) get_field( 'owning', $post_id ) );
        if ( preg_match( '/^(Public|Private nonprofit|Private for-profit), (4-year|2-year|less than 2 years)$/', $own, $m ) ) {
            return strtolower( $m[1] ) . ( 'less than 2 years' === $m[2] ? ' school with programs under two years' : ' ' . $m[2] . ' college' );
        }
        if ( preg_match( '/(Public|Private)\s*(\d)\s*Year/i', $own, $m ) ) {
            return strtolower( $m[1] ) . ' ' . $m[2] . '-year college';
        }
        return 'college';
    }
}

if ( ! function_exists( 'gpa_college_faqs' ) ) {
    /**
     * The page's questions: [ 'question' => text, 'answer' => HTML ]. Only questions the page's own data answers.
     * The FAQ section prints them and the FAQPage JSON-LD carries the same answers as plain text.
     */
    function gpa_college_faqs( $post_id ) {
        $college = get_the_title( $post_id );
        $fresh   = gpa_college_fresh( $post_id );
        return $fresh ? gpa_college_faqs_fresh( $post_id, $college, $fresh ) : gpa_college_faqs_legacy( $post_id, $college );
    }
}

if ( ! function_exists( 'gpa_college_faqs_fresh' ) ) {
    // Questions for a page with the imported federal data: each answer gives its year and source.
    function gpa_college_faqs_fresh( $post_id, $college, array $f ) {
        $name = esc_html( $college );
        $ed   = 'the U.S. Department of Education';
        $faqs = array();

        // GPA: the college's own Common Data Set when we have it, else how it weighs GPA (IPEDS has no GPA figures).
        $cds = function_exists( 'gpa_college_cds_gpa' ) ? gpa_college_cds_gpa( $post_id ) : null;
        if ( $cds ) {
            $faqs[] = array(
                'question' => 'What is the average high school GPA at ' . $college . '?',
                'answer'   => esc_html( gpa_college_cds_gpa_answer( $college, $cds ) ) . ' Source: <a href="' . esc_url( $cds['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $college . ' Common Data Set ' . $cds['year'] ) . '</a>.',
            );
        } elseif ( isset( $f['requirements']['admission_requirements_high_school_gpa'] ) && '' !== $f['fall'] ) {
            list( $status ) = gpa_college_requirement_status( $f['requirements']['admission_requirements_high_school_gpa'] );
            $said = array(
                'Required'                => $name . ' requires a high school GPA to be considered for admission',
                'Considered if submitted' => $name . ' doesn\'t require a high school GPA for admission but considers one if submitted',
                'Not considered'          => $name . ' doesn\'t consider high school GPA in admission, even if submitted',
            );
            if ( isset( $said[ $status ] ) ) {
                $faqs[] = array(
                    'question' => 'What GPA do you need to get into ' . $college . '?',
                    'answer'   => $said[ $status ] . ', according to what it reported to ' . $ed . ' for ' . $f['fall'] . '.'
                        . ( 'Not considered' === $status ? '' : ' Federal data doesn\'t include GPA averages, and we haven\'t found one published by ' . $name . ' itself, so we don\'t list an average GPA.' ),
                );
            }
        }

        // Acceptance rate, or the open admission policy.
        if ( null !== $f['rate'] && '' !== $f['fall'] ) {
            $counts = ( $f['applicants'] && null !== $f['admits'] )
                ? ': it admitted ' . number_format( $f['admits'] ) . ' of ' . number_format( $f['applicants'] ) . ' first-year applicants'
                : '';
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college . '?',
                'answer'   => $name . '\'s acceptance rate was <strong>' . esc_html( gpa_college_pct_txt( $f['rate'] ) ) . '</strong> for ' . $f['fall'] . $counts . ', according to what it reported to ' . $ed . ' (IPEDS).',
            );
        } elseif ( $f['open'] ) {
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college . '?',
                'answer'   => $name . ' has an open admission policy: it accepts any student who applies, so it doesn\'t report an acceptance rate'
                    . ( '' !== $f['open_year'] ? ' (' . esc_html( $f['open_year'] ) . ', as reported to ' . $ed . ')' : '' ) . '.',
            );
        }

        // Test scores of the students who enrolled and submitted them.
        $who = function ( $test, $pct ) use ( $name, $f ) {
            return 'The middle 50% of ' . $name . '\'s ' . $f['fall'] . ' first-year students who submitted ' . $test . ' scores'
                . ( null !== $pct ? ' (' . esc_html( round( $pct ) ) . '% of them)' : '' );
        };
        $sat = array();
        $med = array();
        foreach ( $f['sat'] as $section => $p ) {
            if ( '' !== gpa_college_range( $p ) ) {
                $sat[] = '<strong>' . gpa_college_range( $p ) . '</strong> in ' . $section;
                $med[] = null !== $p[1] ? $p[1] . ' (' . $section . ')' : '';
            }
        }
        if ( $sat && '' !== $f['fall'] ) {
            $med = array_filter( $med );
            $faqs[] = array(
                'question' => 'What SAT scores do ' . $college . ' students have?',
                'answer'   => $who( 'SAT', $f['sat_submit'] ) . ' scored ' . implode( ' and ', $sat ) . '.'
                    . ( count( $med ) === count( $sat ) ? ' The median scores were ' . implode( ' and ', $med ) . '.' : '' ),
            );
        }
        if ( isset( $f['act']['Composite'] ) && '' !== gpa_college_range( $f['act']['Composite'] ) && '' !== $f['fall'] ) {
            $p      = $f['act']['Composite'];
            $faqs[] = array(
                'question' => 'What ACT scores do ' . $college . ' students have?',
                'answer'   => $who( 'ACT', $f['act_submit'] ) . ' had composite scores of <strong>' . gpa_college_range( $p ) . '</strong>'
                    . ( null !== $p[1] ? ', with a median of ' . $p[1] : '' ) . '.',
            );
        }

        // AP credit.
        $year = '' !== $f['credits_year'] ? ' for ' . esc_html( $f['credits_year'] ) : '';
        if ( 'Yes' === $f['ap'] ) {
            $faqs[] = array(
                'question' => 'Does ' . $college . ' accept AP credit?',
                'answer'   => 'Yes. ' . $name . ' awards college credit for Advanced Placement (AP) exams, according to what it reported to ' . $ed . $year . '. The college decides which exams and scores earn credit.',
            );
        } elseif ( 'No' === $f['ap'] ) {
            $faqs[] = array(
                'question' => 'Does ' . $college . ' accept AP credit?',
                'answer'   => 'No. ' . $name . ' reported to ' . $ed . ' that it doesn\'t award credit for Advanced Placement (AP) exams' . $year . '.',
            );
        }

        // Net price.
        if ( $f['net_price'] && '' !== $f['net_price_year'] ) {
            $faqs[] = array(
                'question' => 'What is the average net price at ' . $college . '?',
                'answer'   => 'In ' . esc_html( $f['net_price_year'] ) . ', first-time, full-time undergraduates'
                    . ( 'in-state' === $f['net_price_scope'] ? ' paying in-state tuition' : '' )
                    . ' who received grant or scholarship aid paid an average net price of <strong>$' . number_format( $f['net_price'] ) . '</strong> a year at ' . $name
                    . ', according to ' . $ed . ' (IPEDS). Net price is the cost of attendance minus grants and scholarships.',
            );
        }
        return $faqs;
    }
}

if ( ! function_exists( 'gpa_college_faqs_legacy' ) ) {
    // Questions for a page the import hasn't reached, from its older fields (as the page showed them before E).
    function gpa_college_faqs_legacy( $post_id, $college ) {
        $has  = 'gpa_college_has';
        $name = esc_html( $college );

        $acceptance_raw = trim( (string) get_field( 'acceptance_rate', $post_id ) );
        $acceptance_num = (float) preg_replace( '/[^0-9.]/', '', $acceptance_raw );
        if ( $acceptance_num > 0 && $acceptance_num <= 1 && false === strpos( $acceptance_raw, '%' ) ) {
            $acceptance_num *= 100;
        }
        $acceptance_display = $acceptance_num > 0 ? str_replace( '.0%', '%', round( $acceptance_num, 1 ) . '%' ) : '';

        $gpa_fmt   = function_exists( 'gpa_college_gpa_txt' ) ? gpa_college_gpa_txt( $post_id ) : '';
        $cds_gpa   = function_exists( 'gpa_college_cds_gpa' ) ? gpa_college_cds_gpa( $post_id ) : null;
        $sat_range = get_field( 'sat_range', $post_id );
        $sat_range = $has( $sat_range ) ? str_replace( '-', '–', $sat_range ) : '';
        $avg_sat   = get_field( 'average_sat_score', $post_id );
        $sat_value = $has( $avg_sat ) ? $avg_sat : $sat_range;
        $sat_pct   = get_field( 'applicants_submitting_sat', $post_id );
        $sat_pct   = $has( $sat_pct ) ? gpa_fmt_pct( $sat_pct ) : '';

        $enrollment       = (string) get_field( 'enrollment', $post_id );
        $enrollment_clean = str_replace( ',', '', $enrollment );
        $enrollment_num   = is_numeric( $enrollment_clean ) ? number_format( (int) $enrollment_clean ) : ( $has( $enrollment ) ? $enrollment : '' );
        $price_clean      = str_replace( array( '$', ',' ), '', (string) get_field( 'net_price', $post_id ) );
        $price_display    = ( is_numeric( $price_clean ) && (int) $price_clean > 0 ) ? '$' . number_format( (int) $price_clean ) : '';
        $standards        = get_field( 'admission_standards', $post_id );
        $competition      = get_field( 'applicant_competition', $post_id );

        $faqs = array();
        if ( $cds_gpa ) {
            $faqs[] = array(
                'question' => 'What is the average high school GPA at ' . $college . '?',
                'answer'   => esc_html( gpa_college_cds_gpa_answer( $college, $cds_gpa ) ) . ' Source: <a href="' . esc_url( $cds_gpa['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $college . ' Common Data Set ' . $cds_gpa['year'] ) . '</a>.',
            );
        } elseif ( '' !== $gpa_fmt ) {
            $faqs[] = array(
                'question' => 'What GPA do I need to get into ' . $college . '?',
                'answer'   => 'The average GPA of admitted students at ' . $name . ' is <strong>' . esc_html( $gpa_fmt ) . '</strong>. While this is the average, ' . $name . ' considers your entire application holistically. A strong GPA combined with extracurricular activities, essays, and recommendations can strengthen your application. We recommend aiming for a GPA at or above the average to be competitive.',
            );
        }
        if ( '' !== $sat_value ) {
            $faqs[] = array(
                'question' => 'What SAT score is required for ' . $college . '?',
                'answer'   => ( $has( $avg_sat ) ? 'The average SAT score for admitted students at ' : 'The middle 50% SAT range for admitted students at ' ) . $name . ' is <strong>' . esc_html( $sat_value ) . '</strong>. ' . ( $sat_pct ? esc_html( $sat_pct ) . ' of applicants submit SAT scores. ' : '' ) . 'Keep in mind that admissions decisions are based on multiple factors beyond test scores. Check the admission requirements section above to see if test scores are required or optional for your application.',
            );
        }
        if ( $acceptance_num > 0 ) {
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college . '?',
                'answer'   => $name . ' has an acceptance rate of <strong>' . esc_html( $acceptance_display ) . '</strong>. ' . ( $acceptance_num < 20 ? 'This makes it a highly selective institution. Applicants should ensure every component of their application is as strong as possible.' : ( $acceptance_num < 50 ? 'This indicates a moderately selective admissions process. A solid academic record and well-rounded application will improve your chances.' : 'This means the school accepts a relatively high proportion of applicants. Focus on meeting the basic requirements and submitting a complete application.' ) ) . ( '' !== $enrollment_num ? ' With an enrollment of ' . esc_html( $enrollment_num ) . ' students, competition can vary by program.' : '' ),
            );
        }
        if ( '' !== $price_display ) {
            $faqs[] = array(
                'question' => 'How much does it cost to attend ' . $college . '?',
                'answer'   => 'The estimated net price to attend ' . $name . ' is <strong>' . esc_html( $price_display ) . '</strong> per year. Net price represents the average cost after financial aid and scholarships are applied. Actual costs may vary based on your financial situation, residency status, and the financial aid package you receive. Contact the financial aid office for a personalized estimate.',
            );
        }
        if ( $has( $standards ) ) {
            $faqs[] = array(
                'question' => 'How competitive is admission to ' . $college . '?',
                'answer'   => $name . ' has <strong>' . esc_html( $standards ) . '</strong> admission standards' . ( $has( $competition ) ? ' with a <strong>' . esc_html( $competition ) . '</strong> level of applicant competition' : '' ) . '. ' . ( '' !== $gpa_fmt ? 'The average admitted student has a GPA of ' . esc_html( $gpa_fmt ) . '. ' : '' ) . 'To improve your chances, focus on maintaining strong grades, preparing well for standardized tests, and building a well-rounded application with meaningful extracurricular activities.',
            );
        }
        return $faqs;
    }
}

if ( ! function_exists( 'gpa_college_faq_text' ) ) {
    // An answer's HTML as the plain text the FAQPage JSON-LD carries.
    function gpa_college_faq_text( $html ) {
        return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
    }
}

if ( ! function_exists( 'gpa_college_sources' ) ) {
    /**
     * The sources a page with the imported data cites at its foot: [ 'what' => text, 'url', 'link' => link text ].
     * The college's own Common Data Set, when the page shows its GPA, is cited in that FAQ answer instead.
     */
    function gpa_college_sources( $post_id ) {
        $f = gpa_college_fresh( $post_id );
        if ( ! $f ) {
            return array();
        }
        $parts = array();
        if ( '' !== $f['fall'] ) {
            $parts[] = 'admissions, test scores and admission factors for ' . $f['fall'];
        }
        if ( null !== $f['enrollment'] && '' !== $f['enrollment_year'] ) {
            $parts[] = 'undergraduate enrollment for fall ' . $f['enrollment_year'];
        }
        if ( $f['net_price'] && '' !== $f['net_price_year'] ) {
            $parts[] = 'net price for ' . $f['net_price_year'];
        }
        if ( '' !== $f['credits_year'] ) {
            $parts[] = ( $f['open'] ? 'admission policy and ' : '' ) . 'credit policies for ' . $f['credits_year'];
        }
        $last    = array_pop( $parts );
        $list    = $parts ? implode( ', ', $parts ) . ' and ' . $last : (string) $last;
        $release = '' !== $f['ipeds_release'] ? ' (' . $f['ipeds_release'] . ' data)' : '';
        $out     = array(
            array(
                'what' => 'U.S. Department of Education, National Center for Education Statistics, IPEDS' . $release . ( '' !== $list ? ': ' . $list : '' ) . '.',
                'url'  => 'https://nces.ed.gov/collegenavigator/?id=' . rawurlencode( $f['unitid'] ),
                'link' => 'College Navigator: ' . ( '' !== $f['name'] ? $f['name'] : get_the_title( $post_id ) ),
            ),
        );
        if ( null !== $f['sat_avg'] ) {
            $out[] = array(
                'what' => 'Average SAT: an estimate by the U.S. Department of Education\'s College Scorecard' . ( '' !== $f['scorecard_release'] ? ' (' . $f['scorecard_release'] . ' release)' : '' ) . '.',
                'url'  => 'https://collegescorecard.ed.gov/data/',
                'link' => 'College Scorecard data',
            );
        }
        return $out;
    }
}
