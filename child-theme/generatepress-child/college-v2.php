<?php
/**
 * College page template v2 (Admissions step 3, Digant's plan of 2026-10-03), from the mockup
 * https://claude.ai/artifact/Gpp2EBVcTycaKF7xnoRVTZ: a "How does my GPA compare?" box (an open-admission version for
 * colleges that take everyone), data-driven FAQs that replace the old ones and hide below three questions, similar
 * colleges in the same state with a link to the state's colleges, and the college's official admissions link.
 *
 * Behind a switch by tier (post meta `admissions_tier`, A, B or C, from step 2): the option `gpa_admissions_v2_tiers`
 * lists the tiers that get it (`wp option update gpa_admissions_v2_tiers '["A"]' --format=json`; delete the option to
 * switch every page back). Editors can preview any page with ?gpa_v2=1 while logged in (page caches skip logged-in
 * users). Every figure still comes from college-data.php; nothing here adds a number the page didn't already have.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'gpa_college_v2' ) ) {
    // True when the college page gets template v2: its tier is switched on, or an editor previews it.
    function gpa_college_v2( $post_id ) {
        static $cache = array();
        $post_id = (int) $post_id;
        if ( ! isset( $cache[ $post_id ] ) ) {
            $tiers = array_intersect( (array) get_option( 'gpa_admissions_v2_tiers', array() ), array( 'A', 'B', 'C' ) );
            $tier  = trim( (string) get_post_meta( $post_id, 'admissions_tier', true ) );
            $on    = '' !== $tier && in_array( $tier, $tiers, true );
            if ( ! $on && isset( $_GET['gpa_v2'] ) && '1' === $_GET['gpa_v2'] && current_user_can( 'edit_posts' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
                $on = true;
            }
            $cache[ $post_id ] = (bool) apply_filters( 'gpa_college_v2', $on, $post_id, $tier );
        }
        return $cache[ $post_id ];
    }
}

if ( ! function_exists( 'gpa_college_state' ) ) {
    // The state in a college's location ("Cambridge, Massachusetts" -> "Massachusetts"), or ''.
    function gpa_college_state( $location ) {
        $parts = array_map( 'trim', explode( ',', (string) $location ) );
        return count( $parts ) > 1 ? end( $parts ) : '';
    }
}

if ( ! function_exists( 'gpa_college_index' ) ) {
    /**
     * Every published college in one query, for the similar-colleges list: post ID => [title, location, rate (number
     * or null, only with its fall), open, tier, noindex]. Cached for a day and cleared when a college is saved.
     */
    function gpa_college_index() {
        static $index = null;
        if ( null !== $index ) {
            return $index;
        }
        $index = get_transient( 'gpa_college_index_v2' );
        if ( is_array( $index ) ) {
            return $index;
        }
        global $wpdb;
        $keys = array( 'location', 'acceptance_rate', 'adm_year', 'adm_open_admission', 'admissions_tier', 'rank_math_robots', 'ipeds_unitid', 'enrollment', 'college_level' );
        $rows = $wpdb->get_results(
            "SELECT p.ID, p.post_title, m.meta_key, m.meta_value FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('" . implode( "','", $keys ) . "')
             WHERE p.post_type = 'colleges' AND p.post_status = 'publish'",
            ARRAY_A
        );
        $raw = array();
        foreach ( $rows as $r ) {
            $id = (int) $r['ID'];
            if ( ! isset( $raw[ $id ] ) ) {
                $raw[ $id ] = array( 'title' => $r['post_title'] );
            }
            if ( null !== $r['meta_key'] ) {
                $raw[ $id ][ $r['meta_key'] ] = (string) $r['meta_value'];
            }
        }
        $index = array();
        foreach ( $raw as $id => $m ) {
            $rate = isset( $m['acceptance_rate'] ) ? str_replace( array( ',', '%' ), '', $m['acceptance_rate'] ) : '';
            $open = isset( $m['adm_open_admission'] ) && 'Yes' === $m['adm_open_admission'];
            $year = isset( $m['adm_year'] ) && ctype_digit( $m['adm_year'] );
            $fed  = isset( $m['ipeds_unitid'] ) && ctype_digit( $m['ipeds_unitid'] );
            $index[ $id ] = array(
                'title'    => $m['title'],
                'location' => isset( $m['location'] ) ? trim( $m['location'] ) : '',
                'rate'     => ( $fed && $year && ! $open && is_numeric( $rate ) ) ? (float) $rate : null,
                'open'     => $fed && $open,
                'tier'     => isset( $m['admissions_tier'] ) ? $m['admissions_tier'] : '',
                'noindex'  => isset( $m['rank_math_robots'] ) && false !== strpos( $m['rank_math_robots'], 'noindex' ),
                'size'     => isset( $m['enrollment'] ) && is_numeric( str_replace( ',', '', $m['enrollment'] ) ) ? (int) str_replace( ',', '', $m['enrollment'] ) : 0,
                'level'    => isset( $m['college_level'] ) ? $m['college_level'] : '',
            );
        }
        set_transient( 'gpa_college_index_v2', $index, DAY_IN_SECONDS );
        return $index;
    }

    add_action( 'save_post_colleges', function () {
        delete_transient( 'gpa_college_index_v2' );
    } );
}

if ( ! function_exists( 'gpa_college_similar' ) ) {
    /**
     * Up to six colleges in the same state and of the same kind (4-year or 2-year) that search engines can index: the
     * closest in acceptance rate and size for a college with a rate, other open-admission colleges of a similar size for
     * an open-admission one. Each: [id, title, note ("Hard · 16%
     * admitted" or "Open admission")]. Empty without a state or with fewer than four matches (Digant: 4–6).
     */
    function gpa_college_similar( array $v, $limit = 6 ) {
        $state = gpa_college_state( $v['location'] );
        $f     = $v['fresh'];
        if ( '' === $state || ! $f || ( ! $v['rate'] && ! $v['open'] ) ) {
            return array();
        }
        $own  = (float) $f['rate'];
        $size = (int) $f['enrollment'];
        $out  = array();
        foreach ( gpa_college_index() as $id => $c ) {
            if ( (int) $id === (int) $v['id'] || $c['noindex'] || gpa_college_state( $c['location'] ) !== $state ) {
                continue;
            }
            if ( ( $v['open'] ? ! $c['open'] : null === $c['rate'] ) || ( '' !== $f['level'] && '' !== $c['level'] && $c['level'] !== $f['level'] ) ) {
                continue;
            }
            // Distance: acceptance-rate points apart, plus 8 points for each doubling or halving of undergraduate
            // enrollment (so a 17,000-student university isn't matched with a 300-student college at the same rate)
            $d = $v['open'] ? 0 : abs( $c['rate'] - $own );
            if ( $size > 0 && $c['size'] > 0 ) {
                $d += 8 * abs( log( $c['size'] / $size, 2 ) );
            }
            $out[] = array( 'id' => (int) $id, 'title' => $c['title'], 'rate' => $c['rate'], 'd' => $d, 'tier' => $c['tier'] );
        }
        usort( $out, function ( $a, $b ) {
            // Closest first; among equals, the page with more data (tier A before B before C), then by name
            return array( $a['d'], $a['tier'], $a['title'] ) <=> array( $b['d'], $b['tier'], $b['title'] );
        } );
        $out = array_slice( $out, 0, $limit );
        if ( count( $out ) < 4 ) {
            return array();
        }
        foreach ( $out as &$c ) {
            $level     = gpa_college_difficulty( array( 'open' => null === $c['rate'], 'rate' => null === $c['rate'] ? null : gpa_college_pct_txt( $c['rate'] ) ) );
            $c['note'] = $level ? ( '' !== $level['rate'] ? $level['label'] . ' · ' . $level['rate'] . ' admitted' : $level['label'] ) : '';
        }
        return $out;
    }
}

if ( ! function_exists( 'gpa_college_test_policy' ) ) {
    // How the college treats SAT and ACT scores, from the admission factor IPEDS asks about: 'Required',
    // 'Considered if submitted', 'Recommended', 'Not considered', or '' when it didn't say.
    function gpa_college_test_policy( array $v ) {
        $f = $v['fresh'];
        if ( ! $f || ! isset( $f['requirements']['admission_requirements_test_scores'] ) ) {
            return '';
        }
        list( $status ) = gpa_college_requirement_status( $f['requirements']['admission_requirements_test_scores'] );
        return $status;
    }
}

if ( ! function_exists( 'gpa_college_compare_data' ) ) {
    /**
     * What the compare box needs, or null when the page has nothing to compare against (no rate, no open admission,
     * no cited GPA and no scores). The GPA is compared only with a cited average whose basis (weighted or unweighted)
     * the college stated, and only on the same basis. The SAT total range is the two section ranges added together,
     * which the box labels as approximate.
     */
    function gpa_college_compare_data( array $v ) {
        $f = $v['fresh'];
        if ( ! $f ) {
            return null;
        }
        $policy = gpa_college_test_policy( $v );
        $d      = array(
            'name'   => $v['plain'],
            'open'   => (bool) $v['open'],
            'rate'   => $v['rate'] ? (float) $f['rate'] : null,
            'rateTxt' => $v['rate'] ? $v['rate'] : '',
            'gpa'    => ( $v['cds'] && '' !== $v['cds']['basis'] ) ? (float) $v['cds']['value'] : null,
            'basis'  => $v['cds'] ? $v['cds']['basis'] : '',
            'gpaYear' => $v['cds'] ? $v['cds']['year'] : '',
            'gpaUnstated' => $v['cds'] && '' === $v['cds']['basis'],
            'tests'  => 'Not considered' === $policy ? 'blind' : ( 'Required' === $policy ? 'required' : ( '' === $policy ? '' : 'optional' ) ),
            'act'    => null,
            'sat'    => null,
            'fall'   => $f['fall'],
        );
        if ( 'blind' !== $d['tests'] ) {
            if ( isset( $f['act']['Composite'] ) && null !== $f['act']['Composite'][0] && null !== $f['act']['Composite'][2] ) {
                $d['act'] = $f['act']['Composite'];
            }
            $rw = isset( $f['sat']['Reading and Writing'] ) ? $f['sat']['Reading and Writing'] : null;
            $m  = isset( $f['sat']['Math'] ) ? $f['sat']['Math'] : null;
            if ( $rw && $m && null !== $rw[0] && null !== $m[0] && null !== $rw[2] && null !== $m[2] ) {
                $d['sat'] = array( $rw[0] + $m[0], ( null !== $rw[1] && null !== $m[1] ) ? $rw[1] + $m[1] : null, $rw[2] + $m[2] );
            }
        }
        if ( ! $d['open'] && null === $d['rate'] && null === $d['gpa'] && ! $d['act'] && ! $d['sat'] ) {
            return null;
        }
        return $d;
    }
}

if ( ! function_exists( 'gpa_college_compare_box' ) ) {
    // The compare section's body. The verdict is worked out in college-compare.js from the data-college attribute;
    // without JavaScript the box still shows the college's own figures.
    function gpa_college_compare_box( array $v ) {
        $d = gpa_college_compare_data( $v );
        if ( ! $d ) {
            return '';
        }
        $name  = esc_html( $v['name'] );
        $calc  = '<p class="gpa-compare__help">Don\'t know your GPA? <a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( gpa_college_calc_anchor( $v ) ) . '</a></p>';
        // "Colleges where a {GPA} fits" links the GPA-band list on the hub (/admissions/?gpa=3.5) for the GPA typed, and
        // shows only while that GPA has a list (college-compare.js); then it leads and "Plan the grades I need" follows.
        $fits  = (string) apply_filters( 'gpa_college_fits_url', '', $v );
        $ctas  = '<div class="gpa-compare__ctas">'
            . ( '' !== $fits && ! $d['open'] ? '<a class="gpa-compare__cta gpa-compare__cta--primary gpa-compare__cta--fits" href="' . esc_url( $fits ) . '" data-bands="' . esc_attr( implode( ',', array_keys( gpa_college_band_lists() ) ) ) . '" hidden><span>Colleges where <span class="gpa-compare__fits">your GPA</span> fits</span></a>' : '' )
            . '<a class="gpa-compare__cta gpa-compare__cta--primary gpa-compare__cta--plan" href="' . esc_url( home_url( '/how-to-raise-gpa/' ) ) . '">Plan the grades I need</a>'
            . '</div>';
        $fine  = '<p class="gpa-compare__fine">A guide based on reported data, not a prediction. ' . $name . ' reviews each application.</p>';
        if ( $d['open'] ) {
            return '<div class="gpa-compare gpa-compare--open"><div class="gpa-compare__result">'
                . '<p class="gpa-compare__verdict"><span class="gpa-compare__label">Where you stand</span><strong>Any GPA meets the admission requirement</strong></p>'
                . '<p class="gpa-compare__text">' . $name . ' has an open admission policy: it accepts any student who applies, so your GPA and test scores won\'t keep you out. Some programs can set their own requirements, so check the one you want with the college.</p>'
                . str_replace( 'gpa-compare__help', 'gpa-compare__help gpa-compare__help--open', $calc ) . $ctas . $fine
                . '</div></div>';
        }
        $tests = '';
        if ( $d['act'] || $d['sat'] ) {
            $first = $d['act'] ? 'ACT' : 'SAT';
            $tests = '<div class="gpa-compare__field"><label for="gpa-compare-score">Test score <span>(optional)</span></label>'
                . '<input id="gpa-compare-score" type="text" inputmode="numeric" autocomplete="off" placeholder="' . ( 'ACT' === $first ? 'e.g. 26' : 'e.g. 1200' ) . '">'
                . '<div class="gpa-compare__toggle" role="group" aria-label="Test">'
                . ( $d['act'] ? '<button type="button" data-test="ACT" aria-pressed="' . ( 'ACT' === $first ? 'true' : 'false' ) . '">ACT</button>' : '' )
                . ( $d['sat'] ? '<button type="button" data-test="SAT" aria-pressed="' . ( 'SAT' === $first ? 'true' : 'false' ) . '">SAT</button>' : '' )
                . '</div></div>';
        }
        return '<div class="gpa-compare" data-college="' . esc_attr( wp_json_encode( $d ) ) . '">'
            . '<div class="gpa-compare__top">'
            . '<div class="gpa-compare__inputs">'
            . '<div class="gpa-compare__field"><label for="gpa-compare-gpa">Your GPA</label>'
            . '<input id="gpa-compare-gpa" type="text" inputmode="decimal" autocomplete="off" placeholder="e.g. 3.4">'
            . '<div class="gpa-compare__toggle" role="group" aria-label="GPA scale">'
            . '<button type="button" data-scale="unweighted" aria-pressed="true">Unweighted</button>'
            . '<button type="button" data-scale="weighted" aria-pressed="false">Weighted</button>'
            . '</div></div>'
            . $tests
            . '</div>'
            . $calc
            . '</div>'
            . '<div class="gpa-compare__result" aria-live="polite">'
            . '<p class="gpa-compare__verdict"><span class="gpa-compare__label">Where you stand</span><strong>Enter your GPA</strong></p>'
            . '<p class="gpa-compare__text">' . gpa_college_compare_static( $v, $d ) . '</p>'
            . '<div class="gpa-compare__range" hidden><div class="gpa-compare__scale"><span></span><span class="gpa-compare__mid"></span><span></span></div>'
            . '<div class="gpa-compare__track"><span class="gpa-compare__band"></span><span class="gpa-compare__you"></span></div>'
            . '<p class="gpa-compare__you-label"></p></div>'
            . '<p class="gpa-compare__note" hidden>Weighted GPAs above 4.0 can\'t be compared directly. Many colleges recalculate on their own scale, so use your unweighted GPA here.</p>'
            . $ctas . $fine
            . '</div></div>';
    }

    // The box's text before anyone types: the figures it compares against.
    function gpa_college_compare_static( array $v, array $d ) {
        $bits = array();
        if ( null !== $d['gpa'] ) {
            $bits[] = 'an average ' . esc_html( $d['basis'] ) . ' GPA of ' . esc_html( $v['cds']['value'] ) . ' (' . esc_html( $d['gpaYear'] ) . ')';
        }
        if ( $d['rate'] ) {
            $bits[] = 'an acceptance rate of ' . esc_html( $d['rateTxt'] ) . ' for ' . esc_html( $d['fall'] );
        }
        if ( $d['act'] ) {
            $bits[] = 'an ACT middle 50% of ' . (int) $d['act'][0] . '–' . (int) $d['act'][2];
        }
        if ( ! $bits ) {
            return '';
        }
        $last = array_pop( $bits );
        return 'Type your GPA to compare it with what ' . esc_html( $v['name'] ) . ' reported: ' . ( $bits ? implode( ', ', $bits ) . ' and ' : '' ) . $last . '.';
    }
}

if ( ! function_exists( 'gpa_college_gpa_pivot' ) ) {
    // The GPA the "Can I get in with a X GPA?" question asks about: a common search near the college's average, on an
    // edge of the Common Data Set's GPA ranges, so "below 3.5" adds up whole ranges.
    function gpa_college_gpa_pivot( array $v ) {
        if ( $v['open'] ) {
            return '2.0';
        }
        if ( $v['cds'] ) {
            $avg = (float) $v['cds']['value'];
            return $avg >= 3.6 ? '3.5' : '3.0'; // band edges in the Common Data Set's GPA ranges
        }
        $rate = $v['fresh'] && null !== $v['fresh']['rate'] ? (float) $v['fresh']['rate'] : 50;
        return $rate < 25 ? '3.5' : '3.0';
    }
}

if ( ! function_exists( 'gpa_college_faqs_v2' ) ) {
    /**
     * Template v2's questions, each answered only from the page's own figures (with who reported them and for which
     * year), in the order people search them. Fewer than three: none, so the page shows no FAQ section and no FAQPage.
     */
    function gpa_college_faqs_v2( $post_id ) {
        $v = gpa_college_view( $post_id );
        $f = $v['fresh'];
        if ( ! $f ) {
            return array();
        }
        $college = $v['name'];
        $name    = esc_html( $college );
        $ed      = 'the U.S. Department of Education';
        $fall    = '' !== $f['fall'] ? ' for ' . esc_html( $f['fall'] ) : '';
        $faqs    = array();
        $gpa_req = isset( $f['requirements']['admission_requirements_high_school_gpa'] ) ? gpa_college_requirement_status( $f['requirements']['admission_requirements_high_school_gpa'] )[0] : '';
        $policy  = gpa_college_test_policy( $v );
        $level   = gpa_college_difficulty( $v );

        // Can I get in with a X GPA?
        $pivot = gpa_college_gpa_pivot( $v );
        $q     = 'Can I get into ' . $college . ' with a ' . $pivot . ' GPA?';
        if ( $v['open'] ) {
            $faqs[] = array( 'question' => $q, 'answer' => 'Yes. ' . $name . ' has an open admission policy: it accepts any student who applies, so a ' . $pivot . ' GPA meets its admission requirement. Some programs can set their own requirements, so check the one you want with the college.' );
        } elseif ( $v['cds'] && $v['bands'] ) {
            $below = 0.0;
            foreach ( $v['bands'] as $b ) {
                if ( (float) strtok( $b[0], '–' ) < (float) $pivot || 'Below 1.0' === $b[0] ) {
                    $below += $b[1];
                }
            }
            $faqs[] = array(
                'question' => $q,
                'answer'   => esc_html( gpa_college_pct_cell( $below ) ) . ' of ' . $name . '\'s first-year students who submitted a GPA had one below ' . $pivot . ', as the college reported in its ' . esc_html( $v['cds']['year'] ) . ' Common Data Set'
                    . ( $v['rate'] ? ', and it admitted ' . esc_html( $v['rate'] ) . ' of applicants' . $fall : '' ) . '. Colleges calculate GPA in different ways, so compare on the same scale and look at your courses and test scores too.',
            );
        } elseif ( $v['cds'] && '' !== $v['cds']['basis'] ) {
            $above = (float) $pivot >= (float) $v['cds']['value'];
            $faqs[] = array(
                'question' => $q,
                'answer'   => $name . '\'s first-year students averaged ' . ( 'unweighted' === $v['cds']['basis'] ? 'an ' : 'a ' ) . esc_html( $v['cds']['basis'] ) . ' GPA of ' . esc_html( $v['cds']['value'] ) . ', as reported by the college for ' . esc_html( $v['cds']['year'] ) . ', so on the same ' . esc_html( $v['cds']['basis'] ) . ' scale a ' . $pivot . ' is ' . ( $above ? 'at or above' : 'below' ) . ' that average'
                    . ( $v['rate'] ? ' at a college that admitted ' . esc_html( $v['rate'] ) . ' of applicants' . $fall : '' ) . '. An average isn\'t a cutoff: your courses, test scores and the rest of your application count too.',
            );
        } elseif ( $v['rate'] && in_array( $gpa_req, array( 'Required', 'Recommended', 'Considered if submitted' ), true ) ) {
            $faqs[] = array(
                'question' => $q,
                'answer'   => $name . ' admitted ' . esc_html( $v['rate'] ) . ' of first-year applicants' . $fall . '. It ' . ( 'Required' === $gpa_req ? 'requires' : ( 'Recommended' === $gpa_req ? 'recommends' : 'considers' ) ) . ' a high school GPA but hasn\'t published an average we could verify, so there\'s no admitted-student GPA to compare a ' . $pivot . ' against. Your transcript and test scores are the clearer guide.',
            );
        }

        // What GPA do you need?
        if ( $v['cds'] ) {
            $faqs[] = array(
                'question' => 'What GPA do you need to get into ' . $college . '?',
                'answer'   => $name . ' doesn\'t set a minimum GPA we could verify. Its first-year students averaged ' . esc_html( $v['cds']['value'] ) . ( '' !== $v['cds']['basis'] ? ' (' . esc_html( $v['cds']['basis'] ) . ')' : '' ) . ', as reported by the college in its ' . esc_html( $v['cds']['year'] ) . ' Common Data Set.'
                    . ( '' === $v['cds']['basis'] ? ' The college doesn\'t say whether that average is weighted or unweighted.' : '' ),
            );
        } elseif ( '' !== $gpa_req && '' !== $f['fall'] ) {
            $said = array(
                'Required'                => $name . ' requires a high school GPA from first-year applicants',
                'Recommended'             => $name . ' recommends that first-year applicants send a high school GPA',
                'Considered if submitted' => $name . ' doesn\'t require a high school GPA but considers one if submitted',
                'Not considered'          => $name . ' doesn\'t consider high school GPA in admission',
            );
            if ( isset( $said[ $gpa_req ] ) ) {
                $faqs[] = array(
                    'question' => 'What GPA do you need to get into ' . $college . '?',
                    'answer'   => $said[ $gpa_req ] . ', according to what it reported to ' . $ed . $fall . '.'
                        . ( 'Not considered' === $gpa_req ? '' : ' It hasn\'t published an average GPA we could verify, so we don\'t list one.' ),
                );
            }
        }

        // Acceptance rate, or the open admission policy
        if ( $v['rate'] ) {
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college . '?',
                'answer'   => $name . '\'s acceptance rate was <strong>' . esc_html( $v['rate'] ) . '</strong>' . $fall
                    . ( $f['applicants'] && null !== $f['admits'] ? ': it admitted ' . number_format( $f['admits'] ) . ' of ' . number_format( $f['applicants'] ) . ' first-year applicants' : '' )
                    . ', according to what it reported to ' . $ed . '.',
            );
        } elseif ( $v['open'] ) {
            $faqs[] = array(
                'question' => 'What is the acceptance rate at ' . $college . '?',
                'answer'   => $name . ' has an open admission policy: it accepts any student who applies, so it doesn\'t report an acceptance rate'
                    . ( '' !== $f['open_year'] ? ' (' . esc_html( $f['open_year'] ) . ', as reported to ' . $ed . ')' : '' ) . '.',
            );
        }

        // Is it hard to get into?
        if ( $level && $v['rate'] ) {
            $how = array(
                'very-hard'   => 'Yes. Fewer than 1 in 10 applicants got in, which makes it one of the hardest colleges to get into.',
                'hard'        => 'Yes. Fewer than 1 in 4 applicants got in, so it\'s highly selective.',
                'moderate'    => 'It\'s selective: fewer than half of applicants got in.',
                'fairly-easy' => 'It\'s moderately accessible: more than half of applicants got in.',
                'easy'        => 'Not for most prepared students: at least 3 in 4 applicants got in.',
            );
            $faqs[] = array(
                'question' => 'Is ' . $college . ' hard to get into?',
                'answer'   => $how[ $level['key'] ] . ' ' . $name . ' admitted ' . esc_html( $v['rate'] ) . ' of first-year applicants' . $fall . '.',
            );
        }

        // Does it require the SAT or ACT?
        $sent = array();
        foreach ( array( 'SAT' => $f['sat_submit'], 'ACT' => $f['act_submit'] ) as $test => $pct ) {
            if ( null !== $pct && $f[ strtolower( $test ) ] ) {
                $sent[] = round( $pct ) . '% sent ' . $test . ' scores';
            }
        }
        $sent = $sent ? ' Of its ' . esc_html( $f['fall'] ) . ' first-year students, ' . implode( ' and ', $sent ) . '.' : '';
        $said = array(
            'Required'                => 'Yes. ' . $name . ' requires SAT or ACT scores from first-year applicants, according to what it reported to ' . $ed . $fall . '.' . $sent,
            'Recommended'             => 'No, but it recommends them. ' . $name . ' told ' . $ed . ' it recommends SAT or ACT scores' . $fall . '.' . $sent,
            'Considered if submitted' => 'No. ' . $name . ' is test optional: it considers SAT or ACT scores if you send them, according to what it reported to ' . $ed . $fall . '.' . $sent,
            'Not considered'          => 'No. ' . $name . ' doesn\'t consider SAT or ACT scores, even if you send them (test blind), according to what it reported to ' . $ed . $fall . '.',
        );
        if ( isset( $said[ $policy ] ) && ! $v['open'] ) {
            $faqs[] = array( 'question' => 'Does ' . $college . ' require the SAT or ACT?', 'answer' => $said[ $policy ] );
        }

        // SAT and ACT scores, in one answer
        $parts = array();
        $sat   = array();
        foreach ( $f['sat'] as $section => $p ) {
            if ( '' !== gpa_college_range( $p ) ) {
                $sat[] = '<strong>' . gpa_college_range( $p ) . '</strong> in ' . ( 'Math' === $section ? 'math' : 'reading and writing' );
            }
        }
        if ( $sat ) {
            $parts[] = 'SAT ' . implode( ' and ', $sat );
        }
        if ( isset( $f['act']['Composite'] ) && '' !== gpa_college_range( $f['act']['Composite'] ) ) {
            $parts[] = 'ACT composite <strong>' . gpa_college_range( $f['act']['Composite'] ) . '</strong>';
        }
        if ( $parts && '' !== $f['fall'] && 'Not considered' !== $policy ) {
            $faqs[] = array(
                'question' => 'What SAT and ACT scores do ' . $college . ' students have?',
                'answer'   => 'The middle 50% of ' . $name . '\'s ' . esc_html( $f['fall'] ) . ' first-year students who sent scores had ' . implode( '; ', $parts ) . ', according to what the college reported to ' . $ed . '. A quarter scored below that range.',
            );
        }

        // How much does GPA matter?
        if ( $v['factors'] && '' !== $gpa_req && ! $v['open'] ) {
            $by = array();
            foreach ( $v['factors'] as $r ) {
                $by[ $r['status'] ][] = strtolower( $r['label'] );
            }
            $list = function ( $items ) {
                $items = array_map( function ( $s ) {
                    return str_replace( array( 'sat or act', 'high school gpa' ), array( 'SAT or ACT', 'high school GPA' ), $s );
                }, $items );
                $last = array_pop( $items );
                return $items ? implode( ', ', $items ) . ' and ' . $last : $last;
            };
            $lead = array(
                'Required'                => 'A lot: ',
                'Recommended'             => 'It counts: ',
                'Considered if submitted' => 'It\'s one factor among several: ',
                'Not considered'          => 'Not as a number: ',
            );
            $a = isset( $lead[ $gpa_req ] ) ? $lead[ $gpa_req ] : '';
            if ( ! empty( $by['Required'] ) ) {
                $a .= $name . ' requires ' . esc_html( $list( $by['Required'] ) ) . '. ';
            }
            if ( ! empty( $by['Considered if submitted'] ) ) {
                $a .= 'It considers ' . esc_html( $list( $by['Considered if submitted'] ) ) . ' if you send them. ';
            }
            if ( ! empty( $by['Not considered'] ) ) {
                $a .= 'It doesn\'t consider ' . esc_html( $list( $by['Not considered'] ) ) . '. ';
            }
            $faqs[] = array(
                'question' => 'How much does GPA matter at ' . $college . '?',
                'answer'   => trim( $a ) . ' That\'s what it reported to ' . $ed . $fall . '.',
            );
        }

        // Questions only some colleges get, each where its own data answers it (Digant, 2026-10-03 07:54: different
        // per college, but meaningful), in this order, as many as fit under ten questions in all
        $extra = array();
        if ( $v['bands'] ) {
            $high = 0.0;
            $top  = $v['bands'][0];
            foreach ( $v['bands'] as $b ) {
                if ( in_array( $b[0], array( '4.0', '3.75–3.99', '3.50–3.74' ), true ) ) {
                    $high += $b[1];
                }
                if ( $b[1] > $top[1] ) {
                    $top = $b;
                }
            }
            $extra[] = array(
                'question' => 'What GPA do most ' . $college . ' students have?',
                'answer'   => '<strong>' . esc_html( gpa_college_pct_cell( $high ) ) . '</strong> of ' . $name . '\'s first-year students who submitted a high school GPA had 3.50 or higher, and the largest group (' . esc_html( gpa_college_pct_cell( $top[1] ) ) . ') had '
                    . ( '4.0' === $top[0] ? 'a 4.0' : esc_html( $top[0] ) ) . ', as the college reported in its ' . esc_html( $v['cds']['year'] ) . ' Common Data Set. The college doesn\'t say whether these GPAs are weighted or unweighted.',
            );
        }
        $factor = function ( $key ) use ( $f ) {
            return isset( $f['requirements'][ $key ] ) ? gpa_college_requirement_status( $f['requirements'][ $key ] )[0] : '';
        };
        if ( ! $v['open'] && '' !== $f['fall'] ) {
            $legacy = $factor( 'admission_requirements_legacy_status' );
            if ( 'Considered if submitted' === $legacy || 'Not considered' === $legacy ) {
                $extra[] = array(
                    'question' => 'Does ' . $college . ' consider legacy status?',
                    'answer'   => ( 'Not considered' === $legacy
                        ? 'No. ' . $name . ' doesn\'t consider whether a parent or other relative attended'
                        : 'Yes. ' . $name . ' considers whether a parent or other relative attended, though it isn\'t required' )
                        . ', according to what it reported to ' . $ed . $fall . '.',
                );
            }
            $essay = $factor( 'admission_requirements_personal_statement_or_essay' );
            $said  = array(
                'Required'                => 'Yes. ' . $name . ' requires a personal statement or essay from first-year applicants',
                'Recommended'             => 'It recommends one. ' . $name . ' recommends that first-year applicants send a personal statement or essay',
                'Considered if submitted' => 'No, but it considers one. ' . $name . ' doesn\'t require a personal statement or essay but considers one if you send it',
                'Not considered'          => 'No. ' . $name . ' doesn\'t consider a personal statement or essay',
            );
            if ( isset( $said[ $essay ] ) ) {
                $extra[] = array(
                    'question' => 'Does ' . $college . ' require an essay?',
                    'answer'   => $said[ $essay ] . ', according to what it reported to ' . $ed . $fall . '.',
                );
            }
            if ( 'Required' === $factor( 'admission_requirements_demonstration_of_competencies' ) ) {
                $extra[] = array(
                    'question' => 'Does ' . $college . ' require a portfolio or audition?',
                    'answer'   => 'Yes, for admission: ' . $name . ' requires applicants to show specific skills, such as through a portfolio or an audition, according to what it reported to ' . $ed . $fall . '. Check what your program asks for with the college.',
                );
            }
        }
        // Room for AP credit and net price, which close the list
        $closing = ( in_array( $f['ap'], array( 'Yes', 'No' ), true ) ? 1 : 0 ) + ( $f['net_price'] && '' !== $f['net_price_year'] ? 1 : 0 );
        $faqs    = array_merge( $faqs, array_slice( $extra, 0, max( 0, 10 - count( $faqs ) - $closing ) ) );

        // AP credit and net price, as before
        $year = '' !== $f['credits_year'] ? ' for ' . esc_html( $f['credits_year'] ) : '';
        if ( 'Yes' === $f['ap'] || 'No' === $f['ap'] ) {
            $faqs[] = array(
                'question' => 'Does ' . $college . ' accept AP credit?',
                'answer'   => 'Yes' === $f['ap']
                    ? 'Yes. ' . $name . ' awards college credit for Advanced Placement (AP) exams, according to what it reported to ' . $ed . $year . '. The college decides which exams and scores earn credit.'
                    : 'No. ' . $name . ' reported to ' . $ed . ' that it doesn\'t award credit for Advanced Placement (AP) exams' . $year . '.',
            );
        }
        if ( $f['net_price'] && '' !== $f['net_price_year'] ) {
            $faqs[] = array(
                'question' => 'What is the average net price at ' . $college . '?',
                'answer'   => 'First-time, full-time undergraduates' . ( 'in-state' === $f['net_price_scope'] ? ' paying in-state tuition' : '' )
                    . ' who received grant or scholarship aid paid an average of <strong>$' . number_format( $f['net_price'] ) . '</strong> a year at ' . $name . ' in ' . esc_html( $f['net_price_year'] )
                    . ', according to ' . $ed . '. Net price is the cost of attendance minus grants and scholarships.',
            );
        }
        return count( $faqs ) >= 3 ? $faqs : array();
    }
}

if ( ! function_exists( 'gpa_college_similar_section' ) ) {
    // "Similar colleges in {State}": one heading over 4–6 link cards (name, then difficulty and admit rate). Unnumbered
    // and out of the TOC (layout.css 10). "See all colleges in {State} →" joins it once the state hubs exist (the
    // gpa_college_state_hub_url filter returns their URL); until then it links the hub filtered to the state.
    function gpa_college_similar_section( array $v ) {
        $similar = gpa_college_similar( $v );
        $st      = strtoupper( trim( (string) get_post_meta( $v['id'], 'college_state', true ) ) );
        $names   = gpa_college_state_names();
        $state   = isset( $names[ $st ] ) ? $names[ $st ] : gpa_college_state( $v['location'] );
        // "See all colleges in {State} →" (Digant, 2026-10-03 08:09): the hub filtered to the state (/admissions/?state=MA,
        // noindex like every filtered view) until the state hubs ship, then their URL through gpa_college_state_hub_url.
        // Shown even when there are no similar colleges.
        $hub = isset( $names[ $st ] ) ? add_query_arg( 'state', $st, get_post_type_archive_link( 'colleges' ) ) : '';
        $hub = (string) apply_filters( 'gpa_college_state_hub_url', $hub, $state, $v );
        $all = '' !== $hub ? '<p class="gpa-college-similar__all' . ( $similar ? '' : ' gpa-college-similar__all--solo' ) . '"><a href="' . esc_url( $hub ) . '">See all colleges in ' . esc_html( $state ) . ' →</a></p>' : '';
        if ( ! $similar ) {
            return $all;
        }
        $items = '';
        foreach ( $similar as $c ) {
            $items .= '<li><a class="gpa-college-similar__card" href="' . esc_url( get_permalink( $c['id'] ) ) . '"><span class="gpa-college-similar__name">' . esc_html( $c['title'] ) . '</span>'
                . ( '' !== $c['note'] ? '<span class="gpa-college-similar__note">' . esc_html( $c['note'] ) . '</span>' : '' ) . '</a></li>';
        }
        return '<h2 id="similar-colleges" class="gpa-no-number">Similar colleges in ' . esc_html( $state ) . '</h2>'
            . '<p class="gpa-college-similar__sub">' . ( $v['open'] ? 'Also open to anyone who applies.' : 'With a similar acceptance rate and size.' ) . '</p>'
            . '<ul class="gpa-college-similar">' . $items . '</ul>' . $all;
    }
}

if ( ! function_exists( 'gpa_college_state_names' ) ) {
    // Postal code => state name, for college_state (IPEDS): the states, DC and the territories with colleges
    function gpa_college_state_names() {
        return array(
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
            'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
            'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts',
            'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
            'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
            'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
            'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
            'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
            'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming', 'PR' => 'Puerto Rico', 'GU' => 'Guam',
            'VI' => 'U.S. Virgin Islands', 'AS' => 'American Samoa', 'MP' => 'Northern Mariana Islands', 'FM' => 'Micronesia',
            'MH' => 'Marshall Islands', 'PW' => 'Palau',
        );
    }
}

if ( ! function_exists( 'gpa_college_calc_anchor' ) ) {
    // The compare box's link to the homepage GPA calculator: one of six anchors, picked by the college's IPEDS ID (the
    // post ID without one), so the anchors spread evenly and each page keeps its own (Digant, 2026-10-03 05:49).
    function gpa_college_calc_anchor( array $v ) {
        $anchors = array( 'Check my GPA', 'Check your GPA', 'GPA calculator', 'Online GPA calculator', 'Calculate your GPA', 'Calculate my GPA' );
        $unitid  = trim( (string) get_post_meta( $v['id'], 'ipeds_unitid', true ) );
        $key     = ctype_digit( $unitid ) ? (int) $unitid : (int) $v['id'];
        return $anchors[ $key % count( $anchors ) ];
    }
}

if ( ! function_exists( 'gpa_college_location_line' ) ) {
    // The line under the H1: "Cambridge, MA · Private · 4-year", from the IPEDS fields the Phase 2 import wrote. Empty
    // when the city or state is missing.
    function gpa_college_location_line( $post_id ) {
        $city    = trim( (string) get_post_meta( $post_id, 'college_city', true ) );
        $st      = trim( (string) get_post_meta( $post_id, 'college_state', true ) );
        $control = trim( (string) get_post_meta( $post_id, 'college_control', true ) );
        $level   = trim( (string) get_post_meta( $post_id, 'college_level', true ) );
        if ( '' === $city || ! preg_match( '/^[A-Z]{2}$/', $st ) ) {
            return '';
        }
        $bits = array( $city . ', ' . $st );
        if ( '' !== $control ) {
            $bits[] = 0 === stripos( $control, 'public' ) ? 'Public' : 'Private';
        }
        if ( 0 === stripos( $level, 'four' ) ) {
            $bits[] = '4-year';
        } elseif ( 0 === stripos( $level, 'at least 2' ) ) {
            $bits[] = '2-year';
        } elseif ( 0 === stripos( $level, 'less than 2' ) ) {
            $bits[] = 'Under 2-year';
        }
        return implode( ' · ', $bits );
    }
}

if ( ! function_exists( 'gpa_college_schema_address' ) ) {
    // The CollegeOrUniversity address on template v2 pages: street and ZIP code from IPEDS (step 3's field file), city
    // and state from the Phase 2 import. Null when the city or state is missing, so the caller keeps its own.
    function gpa_college_schema_address( $post_id ) {
        $city = trim( (string) get_post_meta( $post_id, 'college_city', true ) );
        $st   = trim( (string) get_post_meta( $post_id, 'college_state', true ) );
        if ( '' === $city || '' === $st ) {
            return null;
        }
        $street = trim( (string) get_post_meta( $post_id, 'college_street', true ) );
        $zip    = trim( (string) get_post_meta( $post_id, 'college_zip', true ) );
        return array_filter( array(
            '@type'           => 'PostalAddress',
            'streetAddress'   => $street,
            'addressLocality' => $city,
            'addressRegion'   => $st,
            'postalCode'      => $zip,
            'addressCountry'  => 'US',
        ), 'strlen' );
    }
}

if ( ! function_exists( 'gpa_college_band_lists' ) ) {
    // The GPA-band lists' windows (gpa-bands.json, from scripts/admissions/gpa_bands.py): "3.5" => [window, weighted,
    // total]. Only the "typical" lists (3.0 to 4.3); 4.4 and 4.5 list the highest averages and have no hub view.
    function gpa_college_band_lists() {
        static $bands = null;
        if ( null === $bands ) {
            $file  = get_stylesheet_directory() . '/gpa-bands.json';
            $json  = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
            $bands = is_array( $json ) && isset( $json['bands'] ) && is_array( $json['bands'] ) ? $json['bands'] : array();
        }
        return $bands;
    }

    // The hub's ?gpa=3.5 view ("See all colleges where a 3.5 GPA is typical"): [low, high] of the reported average,
    // or null for a value that isn't a list. Same rule as the lists: averages of 4.0 or below for 3.0 to 4.0, weighted
    // averages above 4.0 for 4.1 and up.
    function gpa_college_band_range( $key ) {
        $bands = gpa_college_band_lists();
        $key   = is_string( $key ) && preg_match( '/^\d\.\d$/', $key ) ? $key : '';
        if ( '' === $key || ! isset( $bands[ $key ] ) ) {
            return null;
        }
        $w  = (float) $bands[ $key ]['window'];
        $lo = (float) $key - $w;
        $hi = (float) $key + $w;
        return ! empty( $bands[ $key ]['weighted'] )
            ? array( max( $lo, 4.01 ), $hi )
            : array( $lo, min( $hi, 4.0 ) );
    }

    // The meta query for that view: a cited average in the range, on a tier A or B page (both indexed).
    function gpa_college_band_meta_query( $key ) {
        $range = gpa_college_band_range( $key );
        if ( ! $range ) {
            return null;
        }
        return array(
            'relation' => 'AND',
            array( 'key' => 'cds_gpa', 'value' => array( sprintf( '%.2f', $range[0] ), sprintf( '%.2f', $range[1] ) ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(4,2)' ),
            array( 'key' => 'cds_gpa_source_url', 'value' => '', 'compare' => '!=' ),
            array( 'key' => 'admissions_tier', 'value' => array( 'A', 'B' ), 'compare' => 'IN' ),
        );
    }

    // "Colleges where a [GPA] fits" on the compare box: the hub, which college-compare.js points at the typed GPA's
    // list (?gpa=3.5) when there is one.
    add_filter( 'gpa_college_fits_url', function ( $url ) {
        return gpa_college_band_lists() ? (string) get_post_type_archive_link( 'colleges' ) : $url;
    } );
}

if ( ! function_exists( 'gpa_college_official_link' ) ) {
    // The college's official admissions page (or its website when that page didn't load), checked by step 3's
    // links check and written as post meta: [url, label], or null.
    function gpa_college_official_link( $post_id ) {
        $url  = trim( (string) get_post_meta( $post_id, 'college_admissions_url', true ) );
        $kind = trim( (string) get_post_meta( $post_id, 'college_admissions_url_kind', true ) );
        if ( ! preg_match( '#^https?://#i', $url ) ) {
            return null;
        }
        return array( $url, 'website' === $kind ? 'official website' : 'official admissions site' );
    }
}

if ( ! function_exists( 'gpa_college_v2_body_class' ) ) {
    // gpa-college-v2 on template v2 pages, for admissions.css 9 (the 64px section spacing).
    function gpa_college_v2_body_class( $classes ) {
        if ( is_singular( 'colleges' ) && gpa_college_v2( get_queried_object_id() ) ) {
            $classes[] = 'gpa-college-v2';
        }
        return $classes;
    }
    add_filter( 'body_class', 'gpa_college_v2_body_class' );
}

if ( ! function_exists( 'gpa_college_v2_assets' ) ) {
    // The compare box's script on template v2 pages.
    function gpa_college_v2_assets() {
        if ( ! is_singular( 'colleges' ) || ! gpa_college_v2( get_queried_object_id() ) ) {
            return;
        }
        wp_enqueue_script( 'gpa-college-compare', get_stylesheet_directory_uri() . '/college-compare.js', array(), gpa_asset_ver( 'college-compare.js' ), true );
    }
    add_action( 'wp_enqueue_scripts', 'gpa_college_v2_assets', 21 );
}

// College pages print their own "On this page" list (single-colleges.php, Design's details.gpa-toc component), so the
// theme's browser-built table of contents stays off them (Digant 2026-10-03 16:46: no JS-built contents).
add_action( 'wp', function () {
    if ( is_singular( 'colleges' ) ) {
        remove_action( 'wp_footer', 'gpa_toc_builder', 31 );
    }
} );

if ( ! function_exists( 'gpa_college_toc' ) ) {
    /**
     * The "On this page" list for a college page: section id => H2 text, word for word, in page order (the compare box,
     * then the numbered sections). Empty under 4 sections. single-colleges.php prints it and gpa_college_toc_schema() names
     * the same entries in the schema, so the links, the H2s and the schema names stay identical (Digant 2026-10-03 18:29).
     */
    function gpa_college_toc( $post_id, $v = null, $sections = null, $compare = null ) {
        $v        = null === $v ? gpa_college_view( $post_id ) : $v;
        $sections = null === $sections ? gpa_college_sections( $v ) : $sections;
        if ( null === $compare ) {
            $compare = gpa_college_v2( $post_id ) ? gpa_college_compare_box( $v ) : '';
        }
        $toc = '' !== $compare ? array( 'compare' => 'How does your GPA compare?' ) : array();
        foreach ( $sections as $section ) {
            $toc[ $section['id'] ] = $section['title'];
        }
        return count( $toc ) >= 4 ? $toc : array();
    }
}

if ( ! function_exists( 'gpa_college_toc_schema' ) ) {
    // The same list as SiteNavigationElement entries, in the shape Rank Math gives its TOC block on other pages (a nested
    // array in @graph sharing the @id #rank-math-toc), so scripts/qa/check_toc_schema.py can hold college pages to it.
    function gpa_college_toc_schema( $data ) {
        if ( ! is_singular( 'colleges' ) || ! function_exists( 'gpa_college_view' ) ) {
            return $data;
        }
        $post_id = get_queried_object_id();
        $url     = get_permalink( $post_id );
        $nav     = array();
        foreach ( gpa_college_toc( $post_id ) as $id => $h2 ) {
            $nav[] = array(
                '@context' => 'https://schema.org',
                '@type'    => 'SiteNavigationElement',
                '@id'      => $url . '#rank-math-toc',
                'name'     => $h2,
                'url'      => $url . '#' . $id,
            );
        }
        if ( $nav ) {
            $data['gpaCollegeToc'] = $nav;
        }
        return $data;
    }
    add_filter( 'rank_math/json_ld', 'gpa_college_toc_schema', 120, 1 );
}
