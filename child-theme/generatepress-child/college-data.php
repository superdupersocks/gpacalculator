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
    // IPEDS's wording for an admission factor -> [short status, whether the college uses it]; '' when unclear. The
    // same three words for every factor: IPEDS's "(Test Optional)" and "(Test Blind)" tags on the test rows only
    // repeat "considered if submitted" and "not considered" (test policies come in a later phase).
    function gpa_college_requirement_status( $label ) {
        $l = strtolower( trim( (string) $label ) );
        if ( 0 === strpos( $l, 'required' ) ) {
            return array( 'Required', true );
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
    // for-profit school with programs under two years", or "college"; "university" instead of "college" when the
    // name says it is one.
    function gpa_college_type_phrase( $post_id ) {
        $own  = trim( (string) get_field( 'owning', $post_id ) );
        $type = 'college';
        if ( preg_match( '/^(Public|Private nonprofit|Private for-profit), (4-year|2-year|less than 2 years)$/', $own, $m ) ) {
            $type = strtolower( $m[1] ) . ( 'less than 2 years' === $m[2] ? ' school with programs under two years' : ' ' . $m[2] . ' college' );
        } elseif ( preg_match( '/(Public|Private)\s*(\d)\s*Year/i', $own, $m ) ) {
            $type = strtolower( $m[1] ) . ' ' . $m[2] . '-year college';
        }
        if ( 'college' === substr( $type, -7 ) && false !== stripos( get_the_title( $post_id ), 'university' ) ) {
            $type = substr( $type, 0, -7 ) . 'university';
        }
        return $type;
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
                'answer'   => esc_html( gpa_college_cds_gpa_answer( $college, $cds ) ) . ' Source: ' . esc_html( $college . ' Common Data Set ' . $cds['year'] ) . '.',
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
                        . ( 'Not considered' === $status ? '' : ' Federal data doesn\'t include GPA averages, and ' . $name . ' hasn\'t published one we could verify, so we don\'t list an average GPA.' ),
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
                'answer'   => esc_html( gpa_college_cds_gpa_answer( $college, $cds_gpa ) ) . ' Source: ' . esc_html( $college . ' Common Data Set ' . $cds_gpa['year'] ) . '.',
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
     * The sources a page cites at its foot, one link each: [ 'what' => text, 'url', 'link' => link text ]. The federal
     * data (IPEDS, through College Navigator), the college's own Common Data Set when the page shows its GPA, and
     * College Scorecard when it shows Scorecard's SAT estimate: three at most.
     */
    function gpa_college_sources( $post_id ) {
        $f   = gpa_college_fresh( $post_id );
        $cds = gpa_college_cds_gpa( $post_id );
        $out = array();
        if ( $f ) {
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
            $out[]   = array(
                'what' => 'U.S. Department of Education, National Center for Education Statistics, IPEDS' . $release . ( '' !== $list ? ': ' . $list : '' ) . '.',
                'url'  => 'https://nces.ed.gov/collegenavigator/?id=' . rawurlencode( $f['unitid'] ),
                'link' => 'College Navigator: ' . ( '' !== $f['name'] ? $f['name'] : get_the_title( $post_id ) ),
            );
        }
        if ( $cds ) {
            $out[] = array(
                'what' => wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES ) . ': average high school GPA of first-year students'
                    . ( gpa_college_gpa_bands( $post_id ) ? ' and how their GPAs were spread' : '' ) . '.',
                'url'  => $cds['url'],
                'link' => wp_specialchars_decode( get_the_title( $post_id ), ENT_QUOTES ) . ' Common Data Set ' . $cds['year'],
            );
        }
        if ( $f && null !== $f['sat_avg'] ) {
            $out[] = array(
                'what' => 'Average SAT: an estimate by the U.S. Department of Education\'s College Scorecard' . ( '' !== $f['scorecard_release'] ? ' (' . $f['scorecard_release'] . ' release)' : '' ) . '.',
                'url'  => 'https://collegescorecard.ed.gov/data/',
                'link' => 'College Scorecard data',
            );
        }
        return $out;
    }
}

/* ------------------------------------------------------------------------------------------------------------------
 * Admissions Phase 3: the college page and the hub on the site's content design (hero band, quick facts, numbered
 * sections, Rank Math FAQ markup, Sources). single-colleges.php prints what gpa_college_view() returns; nothing here
 * adds a figure the page didn't already have, it only lays the same fields out.
 * ---------------------------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gpa_college_gpa_bands' ) ) {
    /**
     * How the college's first-year students' high school GPAs were spread, from the same Common Data Set (C11) the
     * page cites for the average: [ [range label, percent], ... ], highest range first. Empty bands are left out
     * (a blank is not 0%), and so is every band when the average itself isn't shown.
     */
    function gpa_college_gpa_bands( $post_id ) {
        if ( ! gpa_college_cds_gpa( $post_id ) ) {
            return array();
        }
        $bands = array(
            '400' => '4.0',
            '375' => '3.75–3.99',
            '350' => '3.50–3.74',
            '325' => '3.25–3.49',
            '300' => '3.00–3.24',
            '250' => '2.50–2.99',
            '200' => '2.00–2.49',
            '100' => '1.00–1.99',
            '000' => 'Below 1.0',
        );
        $out = array();
        foreach ( $bands as $key => $label ) {
            $v = trim( (string) get_post_meta( $post_id, 'cds_gpa_band_' . $key, true ) );
            if ( is_numeric( $v ) ) {
                $out[] = array( $label, (float) $v );
            }
        }
        return count( $out ) >= 3 ? $out : array();
    }
}

if ( ! function_exists( 'gpa_college_view' ) ) {
    /**
     * Everything the college page shows, worked out once: names, the hero's lines, quick facts, scores, admission
     * factors, credit and cost. Pages without the federal import ($v['fresh'] null) get the name, place and type only.
     */
    function gpa_college_view( $post_id ) {
        $name  = get_the_title( $post_id );
        $plain = wp_specialchars_decode( $name, ENT_QUOTES );
        $f     = gpa_college_fresh( $post_id );
        $loc   = trim( (string) get_field( 'location', $post_id ) );
        $v     = array(
            'id'       => $post_id,
            'name'     => $name,
            'plain'    => $plain,
            'former'   => trim( (string) get_post_meta( $post_id, 'former_name', true ) ),
            'location' => gpa_college_has( $loc ) ? $loc : '',
            'type'     => gpa_college_type_phrase( $post_id ),
            'fresh'    => $f,
            'cds'      => gpa_college_cds_gpa( $post_id ),
            'bands'    => array(),
            'rate'     => null,
            'open'     => false,
            'scores'   => array(),
            'factors'  => array(),
            'credits'  => array(),
        );
        $v['bands'] = $v['cds'] ? gpa_college_gpa_bands( $post_id ) : array();
        if ( ! $f ) {
            return $v;
        }
        $v['fall'] = $f['fall'];
        $v['open'] = $f['open'];
        if ( null !== $f['rate'] && '' !== $f['fall'] && ! $f['open'] ) {
            $v['rate'] = gpa_college_pct_txt( $f['rate'] );
        }
        // Score table rows: [test, section, [25th, 50th, 75th]]
        foreach ( array( 'sat' => 'SAT', 'act' => 'ACT' ) as $key => $test ) {
            foreach ( $f[ $key ] as $section => $p ) {
                $v['scores'][] = array( $test, $section, $p );
            }
        }
        // Admission factors, required first, then the ones the college weighs, then those it doesn't
        $order = array( 'Required' => 0, 'Recommended' => 1, 'Considered if submitted' => 2, 'Not considered' => 3 );
        foreach ( gpa_college_requirement_items() as $field => $item ) {
            if ( ! isset( $f['requirements'][ $field ] ) ) {
                continue;
            }
            list( $status, $used ) = gpa_college_requirement_status( $f['requirements'][ $field ] );
            if ( '' === $status ) {
                continue;
            }
            $v['factors'][] = array( 'label' => $item[0], 'about' => $item[1], 'status' => $status, 'used' => $used, 'rank' => $order[ $status ] );
        }
        usort( $v['factors'], function ( $a, $b ) {
            return $a['rank'] - $b['rank'];
        } );
        foreach ( array( 'ap' => 'Credit for AP exams', 'life' => 'Credit for life experience' ) as $key => $label ) {
            if ( in_array( $f[ $key ], array( 'Yes', 'No' ), true ) ) {
                $v['credits'][] = array( $label, $f[ $key ] );
            }
        }
        return $v;
    }
}

if ( ! function_exists( 'gpa_college_h1_sub' ) ) {
    // The H1's second line names what the page has.
    function gpa_college_h1_sub( array $v ) {
        if ( $v['cds'] ) {
            return 'Average GPA & Admissions';
        }
        if ( $v['rate'] ) {
            return $v['scores'] ? 'Acceptance Rate & Test Scores' : 'Acceptance Rate & Admissions';
        }
        if ( $v['open'] ) {
            return 'Admission Requirements';
        }
        return 'Admissions';
    }
}

if ( ! function_exists( 'gpa_college_intro' ) ) {
    // The hero's sentence or two: what kind of college it is and where (and its former name), then how selective it is.
    function gpa_college_intro( array $v ) {
        $f     = $v['fresh'];
        $type  = $v['type'];
        $parts = array();
        if ( 'college' !== $type || '' !== $v['location'] ) {
            $s = $v['plain'] . ( '' !== $v['former'] ? ' (formerly ' . $v['former'] . ')' : '' ) . ' is '
                . ( preg_match( '/^[aeiou]/i', $type ) ? 'an ' : 'a ' ) . $type . ( '' !== $v['location'] ? ' in ' . $v['location'] : '' );
            if ( $f && null !== $f['enrollment'] && '' !== $f['enrollment_year'] ) {
                $s .= ', with ' . number_format( $f['enrollment'] ) . ' undergraduates';
            }
            $parts[] = $s . '.';
        }
        if ( $v['rate'] ) {
            $parts[] = 'It admitted ' . $v['rate'] . ' of first-year applicants for ' . $f['fall'] . '.';
        } elseif ( $v['open'] ) {
            $parts[] = 'It has an open admission policy: anyone who applies is accepted.';
        }
        return implode( ' ', $parts );
    }
}

if ( ! function_exists( 'gpa_college_data_line' ) ) {
    // The small line under the hero's intro: where the figures come from (their years are on each figure).
    function gpa_college_data_line( array $v ) {
        $f = $v['fresh'];
        if ( ! $f ) {
            return '';
        }
        $who = 'the U.S. Department of Education' . ( $v['cds'] ? ' and the college\'s Common Data Set' : '' );
        return '' !== $f['fall'] ? ucfirst( $f['fall'] ) . ' admissions data from ' . $who : 'Data from ' . $who;
    }
}

if ( ! function_exists( 'gpa_college_quick_facts' ) ) {
    // The quick facts box under the hero: [label, HTML], only for figures the page has.
    function gpa_college_quick_facts( array $v ) {
        $f   = $v['fresh'];
        $out = array();
        if ( $v['rate'] ) {
            $out[] = array( 'Acceptance rate', esc_html( $v['rate'] ) . ' for ' . esc_html( $f['fall'] ) );
        } elseif ( $v['open'] ) {
            $out[] = array( 'Admission', 'open to anyone who applies' );
        }
        if ( $v['cds'] ) {
            $out[] = array( 'Average high school GPA', esc_html( $v['cds']['value'] ) . ( '' !== $v['cds']['basis'] ? ' (' . esc_html( $v['cds']['basis'] ) . ')' : '' ) . ', as reported by the college for ' . esc_html( $v['cds']['year'] ) );
        }
        if ( $f ) {
            $sat = array();
            foreach ( $f['sat'] as $section => $p ) {
                if ( '' !== gpa_college_range( $p ) ) {
                    $sat[] = gpa_college_range( $p ) . ' ' . ( 'Math' === $section ? 'math' : 'reading and writing' );
                }
            }
            if ( $sat ) {
                $out[] = array( 'SAT, middle 50%', esc_html( implode( ', ', $sat ) ) );
            }
            if ( isset( $f['act']['Composite'] ) && '' !== gpa_college_range( $f['act']['Composite'] ) ) {
                $out[] = array( 'ACT, middle 50%', esc_html( gpa_college_range( $f['act']['Composite'] ) ) . ' composite' );
            }
            if ( $f['net_price'] && '' !== $f['net_price_year'] ) {
                $out[] = array( 'Average net price', '$' . number_format( $f['net_price'] ) . ' a year (' . esc_html( $f['net_price_year'] ) . ')' );
            }
        }
        return $out;
    }
}

if ( ! function_exists( 'gpa_college_pct_cell' ) ) {
    // A share in a table: 74.7 -> "74.7%", 20.38 -> "20.4%", 0.98 -> "1%", 0.03 -> "<0.1%".
    function gpa_college_pct_cell( $pct ) {
        $pct = (float) $pct;
        if ( $pct > 0 && $pct < 0.05 ) {
            return '<0.1%';
        }
        return rtrim( rtrim( number_format( $pct, 1 ), '0' ), '.' ) . '%';
    }
}

if ( ! function_exists( 'gpa_college_sections' ) ) {
    /**
     * The page's numbered sections in order, each only when the college has data for it:
     * [ 'id' => anchor, 'title' => H2 text, 'html' => body ]. Every figure says who reported it and for which year;
     * the links to those sources are in the Sources list at the foot of the page (gpa_college_sources()).
     */
    function gpa_college_sections( array $v ) {
        $f   = $v['fresh'];
        $out = array();
        if ( ! $f ) {
            return $out;
        }
        $name = esc_html( $v['name'] );
        $ed   = 'the U.S. Department of Education';
        $fall = '' !== $f['fall'] ? ' for ' . esc_html( $f['fall'] ) : '';

        // GPA: the college's own Common Data Set, or how it weighs GPA (federal data has no GPA averages)
        if ( $v['cds'] ) {
            $g     = $v['cds'];
            $basis = array(
                'weighted'   => ' It\'s a weighted average, which adds points for honors, AP or IB classes, so it can be higher than 4.0.',
                'unweighted' => ' It\'s an unweighted average, on a 4.0 scale.',
                ''           => ' The college doesn\'t say whether it\'s weighted or unweighted.',
            );
            $html  = '<p>' . $name . ' reported an average high school GPA of <strong>' . esc_html( $g['value'] ) . '</strong> for its first-year students'
                . ( '' !== $g['submit'] ? ' who submitted one (' . esc_html( $g['submit'] ) . ' did)' : '' )
                . ', in its ' . esc_html( $g['year'] ) . ' Common Data Set.' . $basis[ $g['basis'] ] . '</p>';
            if ( $v['bands'] ) {
                // Ranges at the bottom that the college reported as 0% are summed up in the caption instead
                $bands = $v['bands'];
                $none  = '';
                while ( count( $bands ) > 3 && 0.0 === (float) end( $bands )[1] ) {
                    array_pop( $bands );
                    $none = ' None had a GPA below ' . strtok( end( $bands )[0], '–' ) . '.';
                }
                $rows = '';
                foreach ( $bands as $b ) {
                    $rows .= '<tr><td>' . esc_html( $b[0] ) . '</td><td><div class="gpa-college-barcell"><span class="gpa-college-bar" aria-hidden="true"><span style="width:' . esc_attr( min( 100, max( 0, $b[1] ) ) ) . '%"></span></span>'
                        . '<span class="gpa-college-bar__num">' . esc_html( gpa_college_pct_cell( $b[1] ) ) . '</span></div></td></tr>';
                }
                $html .= '<p>How their high school GPAs were spread:</p>'
                    . '<figure class="wp-block-table gpa-college-table gpa-college-bands"><table><thead><tr><th scope="col">High school GPA</th><th scope="col">Share of first-year students</th></tr></thead>'
                    . '<tbody>' . $rows . '</tbody></table>'
                    . '<figcaption>First-year students who submitted a high school GPA, as ' . $name . ' reported in its ' . esc_html( $g['year'] ) . ' Common Data Set.' . esc_html( $none ) . '</figcaption></figure>';
            }
            $html .= '<div class="gpa-callout gpa-callout--note"><p><strong>An average, not a cutoff</strong>Colleges calculate GPA in different ways, so this figure can\'t be compared directly with your own GPA.</p></div>';
            $out[] = array( 'id' => 'average-gpa', 'title' => $v['plain'] . ' average GPA', 'html' => $html );
        } elseif ( isset( $f['requirements']['admission_requirements_high_school_gpa'] ) ) {
            list( $status ) = gpa_college_requirement_status( $f['requirements']['admission_requirements_high_school_gpa'] );
            $said = array(
                'Required'                => ' requires a high school GPA from first-year applicants',
                'Recommended'             => ' recommends that first-year applicants send a high school GPA',
                'Considered if submitted' => ' doesn\'t require a high school GPA from first-year applicants but considers one if it\'s submitted',
                'Not considered'          => ' doesn\'t consider high school GPA when it decides on first-year applicants',
            );
            if ( isset( $said[ $status ] ) ) {
                $html = '<p>' . $name . $said[ $status ] . ', according to what it reported to ' . $ed . $fall . '.</p>';
                if ( 'Not considered' !== $status ) {
                    $html .= '<p>Federal data doesn\'t include GPA averages, and ' . $name . ' hasn\'t published one we could verify, so this page doesn\'t list an average GPA.</p>';
                }
                $out[] = array( 'id' => 'gpa-requirements', 'title' => $v['plain'] . ' GPA requirements', 'html' => $html );
            }
        }

        // Acceptance rate, or the open admission policy
        if ( $v['rate'] ) {
            $html  = '<p>' . ( $f['applicants'] && null !== $f['admits']
                    ? $name . ' admitted <strong>' . number_format( $f['admits'] ) . '</strong> of ' . number_format( $f['applicants'] ) . ' first-year applicants' . $fall . ', an acceptance rate of <strong>' . esc_html( $v['rate'] ) . '</strong>'
                    : $name . '\'s acceptance rate' . $fall . ' was <strong>' . esc_html( $v['rate'] ) . '</strong>' )
                . ', according to what it reported to ' . $ed . '.</p>';
            $html .= '<div class="gpa-college-meter" aria-hidden="true"><span style="width:' . esc_attr( min( 100, max( 0, (float) $f['rate'] ) ) ) . '%"></span></div>';
            $out[] = array( 'id' => 'acceptance-rate', 'title' => $v['plain'] . ' acceptance rate', 'html' => $html );
        } elseif ( $v['open'] ) {
            $out[] = array(
                'id'    => 'acceptance-rate',
                'title' => $v['plain'] . ' acceptance rate',
                'html'  => '<p>' . $name . ' has an open admission policy: it accepts any student who applies, so it doesn\'t report an acceptance rate'
                    . ( '' !== $f['open_year'] ? ' (' . esc_html( $f['open_year'] ) . ', as reported to ' . $ed . ')' : '' ) . '.</p>',
            );
        }

        // SAT and ACT: the middle 50% of first-year students who submitted scores, and Scorecard's SAT estimate
        if ( $v['scores'] || null !== $f['sat_avg'] ) {
            $html = '';
            if ( $v['scores'] ) {
                $mid = false;
                foreach ( $v['scores'] as $r ) {
                    $mid = $mid || null !== $r[2][1];
                }
                $cell = function ( $n ) {
                    return null !== $n ? esc_html( $n ) : '–';
                };
                $rows = '';
                foreach ( $v['scores'] as $r ) {
                    $rows .= '<tr><td>' . esc_html( $r[0] . ' ' . $r[1] ) . '</td><td>' . $cell( $r[2][0] ) . '</td>' . ( $mid ? '<td>' . $cell( $r[2][1] ) . '</td>' : '' ) . '<td>' . $cell( $r[2][2] ) . '</td></tr>';
                }
                $sent = array();
                foreach ( array( 'SAT' => $f['sat_submit'], 'ACT' => $f['act_submit'] ) as $test => $pct ) {
                    if ( null !== $pct && $f[ strtolower( $test ) ] ) {
                        $sent[] = round( $pct ) . '% submitted ' . $test . ' scores';
                    }
                }
                $html .= '<p>Scores of the first-year students who entered ' . $name . ( '' !== $f['fall'] ? ' in ' . esc_html( $f['fall'] ) : '' ) . ' and submitted them. Half of them scored between the 25th and 75th percentiles:</p>'
                    . '<figure class="wp-block-table gpa-college-table gpa-college-scores"><table><thead><tr><th scope="col">Test</th><th scope="col">25th<span class="gpa-college-wide"> percentile</span></th>'
                    . ( $mid ? '<th scope="col">Median</th>' : '' ) . '<th scope="col">75th<span class="gpa-college-wide"> percentile</span></th></tr></thead><tbody>' . $rows . '</tbody></table>'
                    . '<figcaption>' . ( $sent ? ucfirst( implode( ' and ', $sent ) ) . ', as ' : 'As ' ) . $name . ' reported to ' . $ed . '.</figcaption></figure>';
            }
            if ( null !== $f['sat_avg'] ) {
                $html .= '<p>' . ( $v['scores'] ? 'The' : 'For ' . $name . ', the' ) . ' U.S. Department of Education\'s College Scorecard estimates an average SAT score of <strong>' . esc_html( $f['sat_avg'] ) . '</strong> for admitted students.</p>';
            }
            $out[] = array( 'id' => 'sat-act-scores', 'title' => 'SAT and ACT scores', 'html' => $html );
        }

        // Admission factors, as IPEDS asks about them
        if ( $v['factors'] ) {
            $kind = array( 'Required' => 'required', 'Recommended' => 'recommended', 'Considered if submitted' => 'considered', 'Not considered' => 'not' );
            $rows = '';
            foreach ( $v['factors'] as $r ) {
                $rows .= '<tr><td><strong>' . esc_html( $r['label'] ) . '</strong><span class="gpa-college-table__about">' . esc_html( $r['about'] ) . '</span></td>'
                    . '<td><span class="gpa-college-status gpa-college-status--' . $kind[ $r['status'] ] . '">' . esc_html( $r['status'] ) . '</span></td></tr>';
            }
            $html = '<p>What ' . $name . ' looks at when it decides on first-year applicants, as it reported to ' . $ed . $fall . ':</p>'
                . '<figure class="wp-block-table gpa-college-table gpa-college-factors"><table><thead><tr><th scope="col">Admission factor</th><th scope="col">Status</th></tr></thead><tbody>' . $rows . '</tbody></table>'
                . '<figcaption>Requirements can differ by program and applicant type, so check with ' . $name . '\'s admissions office before you apply.</figcaption></figure>';
            if ( ! $v['scores'] && '' !== $f['fall'] && ! $f['open'] ) {
                $html .= '<p>' . $name . ' didn\'t report SAT or ACT scores for its ' . esc_html( $f['fall'] ) . ' first-year students.</p>';
            }
            $out[] = array( 'id' => 'admission-requirements', 'title' => 'Admission requirements', 'html' => $html );
        }

        // Credit for AP exams and for life experience
        if ( $v['credits'] ) {
            $year = '' !== $f['credits_year'] ? ' for ' . esc_html( $f['credits_year'] ) : '';
            $said = array();
            if ( in_array( $f['ap'], array( 'Yes', 'No' ), true ) ) {
                $said[] = $name . ( 'Yes' === $f['ap'] ? ' awards' : ' doesn\'t award' ) . ' college credit for Advanced Placement (AP) exams, according to what it reported to ' . $ed . $year . '.'
                    . ( 'Yes' === $f['ap'] ? ' The college decides which exams and scores earn credit.' : '' );
            }
            if ( 'Yes' === $f['life'] ) {
                $said[] = ( $said ? 'It also awards' : $name . ' awards' ) . ' credit for life experience, such as work or military service' . ( $said ? '' : ', according to what it reported to ' . $ed . $year ) . '.';
            } elseif ( 'No' === $f['life'] ) {
                $said[] = ( $said ? 'It doesn\'t' : $name . ' doesn\'t' ) . ' award credit for life experience' . ( $said ? '' : ', according to what it reported to ' . $ed . $year ) . '.';
            }
            $out[] = array( 'id' => 'credit', 'title' => in_array( $f['ap'], array( 'Yes', 'No' ), true ) ? 'AP credit' : 'Credit for life experience', 'html' => '<p>' . implode( '</p><p>', $said ) . '</p>' );
        }

        // Average net price
        if ( $f['net_price'] && '' !== $f['net_price_year'] ) {
            $out[] = array(
                'id'    => 'net-price',
                'title' => 'Average net price',
                'html'  => '<p>First-time, full-time undergraduates' . ( 'in-state' === $f['net_price_scope'] ? ' paying in-state tuition' : '' )
                    . ' who received grant or scholarship aid paid an average net price of <strong>$' . number_format( $f['net_price'] ) . '</strong> a year at ' . $name
                    . ' in ' . esc_html( $f['net_price_year'] ) . ', according to ' . $ed . '.</p><p>Net price is the full cost of attendance minus grants and scholarships.</p>',
            );
        }
        return $out;
    }
}

if ( ! function_exists( 'gpa_college_profile_styles' ) ) {
    /**
     * College pages and the /admissions/ hub use the content-page design: content-styles.css and admissions.css
     * instead of database-page.css, and the content template's body classes, which give them the hero band, the white
     * column and the section numbers in layout.css.
     */
    function gpa_college_profile_styles() {
        if ( ! is_singular( 'colleges' ) && ! is_post_type_archive( 'colleges' ) ) {
            return;
        }
        wp_dequeue_style( 'database-page' );
        wp_enqueue_style( 'gpa-content', get_stylesheet_directory_uri() . '/content-styles.css', array( 'gpa-components' ), gpa_asset_ver( 'content-styles.css' ) );
        wp_enqueue_style( 'gpa-admissions', get_stylesheet_directory_uri() . '/admissions.css', array( 'gpa-content' ), gpa_asset_ver( 'admissions.css' ) );
    }
    add_action( 'wp_enqueue_scripts', 'gpa_college_profile_styles', 20 );
}

if ( ! function_exists( 'gpa_college_profile_body_class' ) ) {
    function gpa_college_profile_body_class( $classes ) {
        if ( is_singular( 'colleges' ) || is_post_type_archive( 'colleges' ) ) {
            $classes = array_merge( $classes, array( 'content-page', 'gpa-template-content', 'gpa-hero-band' ) );
        }
        return $classes;
    }
    add_filter( 'body_class', 'gpa_college_profile_body_class' );
}

/* ------------------------------------------------------------------------------------------------------------------
 * Admissions Phase 3: the /admissions/ hub. archive-colleges.php prints the hero, the finder
 * (template-parts/college-db-archive.php, also the [gpa_college_archive] shortcode) and the notes on the data; the
 * finder's rows come from gpa_render_college_card(), on the first load and from the filter_colleges AJAX handler.
 * ---------------------------------------------------------------------------------------------------------------- */

if ( ! function_exists( 'gpa_college_hub_stats' ) ) {
    // The hub's live numbers: published colleges, colleges whose page shows a cited GPA, the fall most pages'
    // admissions figures are for ("fall 2024") and the data releases most pages use.
    function gpa_college_hub_stats() {
        static $stats = null;
        if ( null !== $stats ) {
            return $stats;
        }
        global $wpdb;
        $gpa   = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = 'cds_gpa' AND g.meta_value <> ''
             JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'cds_gpa_source_url' AND u.meta_value <> ''
             WHERE p.post_type = 'colleges' AND p.post_status = 'publish'"
        );
        $most  = function ( $key ) use ( $wpdb ) {
            return (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT m.meta_value FROM {$wpdb->postmeta} m
                 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'colleges' AND p.post_status = 'publish'
                 WHERE m.meta_key = %s AND m.meta_value <> ''
                 GROUP BY m.meta_value ORDER BY COUNT(*) DESC LIMIT 1",
                $key
            ) );
        };
        $year  = $most( 'adm_year' );
        $count = wp_count_posts( 'colleges' );
        $stats = array(
            'colleges'          => isset( $count->publish ) ? (int) $count->publish : 0,
            'gpa'               => $gpa,
            'fall'              => ctype_digit( $year ) ? 'fall ' . $year : '',
            'ipeds_release'     => $most( 'ipeds_release' ),
            'scorecard_release' => $most( 'scorecard_release' ),
        );
        return $stats;
    }
}

if ( ! function_exists( 'gpa_college_hub_search_sql' ) ) {
    /**
     * The hub's search (filter_colleges AJAX, query var gpa_hub_terms): every word has to be in the college's name,
     * its federal (IPEDS) name, a former name or its location ("Abilene, Texas"), so "Texas" finds the colleges in
     * Texas, not only the ones named after it.
     */
    function gpa_college_hub_search_sql( $search, $query ) {
        global $wpdb;
        $terms = (array) $query->get( 'gpa_hub_terms' );
        if ( ! $terms ) {
            return $search;
        }
        $and = array();
        foreach ( $terms as $term ) {
            $like  = '%' . $wpdb->esc_like( $term ) . '%';
            $and[] = $wpdb->prepare(
                "({$wpdb->posts}.post_title LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} gs WHERE gs.post_id = {$wpdb->posts}.ID AND gs.meta_key IN ('location', 'ipeds_name', 'former_name') AND gs.meta_value LIKE %s))",
                $like,
                $like
            );
        }
        return ' AND ' . implode( ' AND ', $and ) . ' ';
    }
}

if ( ! function_exists( 'gpa_render_college_card' ) ) {
    /**
     * One college in the hub's list: its name, place and type, then the figures its page shows (acceptance rate or
     * open admission, SAT and ACT middle 50%, and the average GPA with its label when the college published one we
     * verified). Each figure cell carries its own label, shown on phones; desktop shows them once, in the list's
     * header row. Pages still under review say so instead of showing figures. The class db-college-card is what
     * database-ajax.js counts.
     */
    function gpa_render_college_card( $post_id ) {
        $v    = gpa_college_view( $post_id );
        $f    = $v['fresh'];
        $own  = trim( (string) get_field( 'owning', $post_id ) );
        $meta = array_filter( array( $v['location'], gpa_college_has( $own ) ? $own : '' ) );
        $dash = '<span class="gpa-hub-row__none" title="Not reported">–</span>';

        $cells = '';
        if ( $f ) {
            if ( $v['rate'] ) {
                $rate = esc_html( $v['rate'] );
            } elseif ( $v['open'] ) {
                $rate = '<span class="gpa-hub-row__open">Open admission</span>';
            } else {
                $rate = $dash;
            }
            $sat = array();
            foreach ( $f['sat'] as $section => $p ) {
                if ( '' !== gpa_college_range( $p ) ) {
                    $sat[] = '<span class="gpa-hub-row__line">' . esc_html( gpa_college_range( $p ) ) . ' <small>' . ( 'Math' === $section ? 'Math' : 'R&amp;W' ) . '</small></span>';
                }
            }
            $act = isset( $f['act']['Composite'] ) ? gpa_college_range( $f['act']['Composite'] ) : '';
            // [label, short label shown on phones, value]
            foreach ( array(
                array( 'Acceptance rate', 'Acceptance', $rate ),
                array( 'SAT, middle 50%', 'SAT', $sat ? implode( '', $sat ) : $dash ),
                array( 'ACT, middle 50%', 'ACT', '' !== $act ? esc_html( $act ) : $dash ),
            ) as $cell ) {
                $cells .= '<div class="gpa-hub-row__fig"><span class="gpa-hub-row__label"><span class="gpa-hub-row__short" aria-hidden="true">' . esc_html( $cell[1] ) . '</span><span class="screen-reader-text">' . esc_html( $cell[0] ) . '</span></span><span class="gpa-hub-row__val">' . $cell[2] . '</span></div>';
            }
        } else {
            $cells = '<div class="gpa-hub-row__review">Figures under review</div>';
        }

        $gpa = '';
        if ( $v['cds'] ) {
            $gpa = '<span class="gpa-hub-row__gpa">Average GPA <strong>' . esc_html( $v['cds']['value'] ) . '</strong>'
                . ( '' !== $v['cds']['basis'] ? ' (' . esc_html( $v['cds']['basis'] ) . ')' : '' )
                . ', as reported by the college for ' . esc_html( $v['cds']['year'] ) . '</span>';
        }

        return '<div class="db-college-card gpa-hub-row" role="listitem" data-post-id="' . esc_attr( $post_id ) . '">'
            . '<div class="gpa-hub-row__college"><a class="gpa-hub-row__name" href="' . esc_url( get_permalink( $post_id ) ) . '">' . esc_html( $v['name'] ) . '</a>'
            . ( $meta ? '<span class="gpa-hub-row__meta"><span>' . implode( '</span> <span>', array_map( 'esc_html', $meta ) ) . '</span></span>' : '' )
            . $gpa . '</div>'
            . $cells
            . '</div>';
    }
}

if ( ! function_exists( 'gpa_college_hub_title' ) ) {
    // The hub's title and description (search results and social cards), from its live count.
    function gpa_college_hub_title() {
        return 'College Admissions Database: Acceptance Rates, SAT & ACT';
    }
    function gpa_college_hub_description() {
        $s = gpa_college_hub_stats();
        return 'Look up acceptance rates, SAT and ACT score ranges and admission requirements for ' . number_format( $s['colleges'] )
            . ' US colleges, with average GPAs where colleges report them.';
    }
    function gpa_college_hub_seo_title( $title ) {
        return is_post_type_archive( 'colleges' ) ? gpa_college_hub_title() : $title;
    }
    function gpa_college_hub_seo_description( $description ) {
        return is_post_type_archive( 'colleges' ) ? gpa_college_hub_description() : $description;
    }
    add_filter( 'pre_get_document_title', 'gpa_college_hub_seo_title', 20 );
    add_filter( 'rank_math/frontend/title', 'gpa_college_hub_seo_title', 20 );
    add_filter( 'rank_math/frontend/description', 'gpa_college_hub_seo_description', 20 );
    add_filter( 'rank_math/opengraph/facebook/og_title', 'gpa_college_hub_seo_title', 20 );
    add_filter( 'rank_math/opengraph/twitter/twitter_title', 'gpa_college_hub_seo_title', 20 );
    add_filter( 'rank_math/opengraph/facebook/og_description', 'gpa_college_hub_seo_description', 20 );
    add_filter( 'rank_math/opengraph/twitter/twitter_description', 'gpa_college_hub_seo_description', 20 );
    // Without Rank Math (local previews), print the description the plugin would
    add_action( 'wp_head', function () {
        if ( is_post_type_archive( 'colleges' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
            echo "\n" . '<meta name="description" content="' . esc_attr( gpa_college_hub_description() ) . '" />' . "\n";
        }
    }, 1 );
}
