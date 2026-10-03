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
     * Up to five colleges in the same state and of the same kind (4-year or 2-year) that search engines can index: the
     * closest in acceptance rate and size for a college with a rate, other open-admission colleges of a similar size for
     * an open-admission one. Each: [id, title, note ("Hard · 16%
     * admitted" or "Open admission")]. Empty without a state or with fewer than two matches.
     */
    function gpa_college_similar( array $v, $limit = 5 ) {
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
        if ( count( $out ) < 2 ) {
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
        $state = gpa_college_state( $v['location'] );
        $calc  = '<p class="gpa-compare__help">Don\'t know your GPA? <a href="' . esc_url( home_url( '/high-school-gpa-calculator/' ) ) . '">Calculate it with the high school GPA calculator</a></p>';
        // The hub has no GPA filter (GPAs show only where a college published one), so "where a GPA fits" is the
        // state's colleges that admit at least half of applicants or anyone who applies.
        $fits  = add_query_arg( array_filter( array( 'search' => '' !== $state ? rawurlencode( $state ) : '', 'acceptance' => 'over_50' ) ), get_post_type_archive_link( 'colleges' ) );
        $ctas  = '<div class="gpa-compare__ctas">'
            . '<a class="gpa-compare__cta gpa-compare__cta--primary" href="' . esc_url( $fits ) . '"><span>Colleges where <span class="gpa-compare__fits">your GPA</span> fits</span></a>'
            . '<a class="gpa-compare__cta" href="' . esc_url( home_url( '/how-to-raise-gpa/' ) ) . '">Plan the grades I need</a>'
            . '</div>';
        $fine  = '<p class="gpa-compare__fine">A guide based on reported data, not a prediction. ' . $name . ' reviews each application.</p>';
        if ( $d['open'] ) {
            return '<div class="gpa-compare gpa-compare--open"><div class="gpa-compare__result">'
                . '<p class="gpa-compare__verdict"><span class="gpa-compare__label">Where you stand</span><strong>Any GPA meets the admission requirement</strong></p>'
                . '<p class="gpa-compare__text">' . $name . ' has an open admission policy: it accepts any student who applies, so your GPA and test scores won\'t keep you out. Some programs can set their own requirements, so check the one you want with the college.</p>'
                . $ctas . $fine
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
                'answer'   => $name . '\'s first-year students averaged a ' . esc_html( $v['cds']['basis'] ) . ' GPA of ' . esc_html( $v['cds']['value'] ) . ', as reported by the college for ' . esc_html( $v['cds']['year'] ) . ', so on the same ' . esc_html( $v['cds']['basis'] ) . ' scale a ' . $pivot . ' is ' . ( $above ? 'at or above' : 'below' ) . ' that average'
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
    // "Similar colleges in {State}": one heading over link cards (name, then difficulty and admit rate), as in the
    // mockup. Unnumbered and out of the TOC (layout.css 10). Empty without at least two matches.
    function gpa_college_similar_section( array $v ) {
        $similar = gpa_college_similar( $v );
        if ( ! $similar ) {
            return '';
        }
        $state = gpa_college_state( $v['location'] );
        $items = '';
        foreach ( $similar as $c ) {
            $items .= '<li><a class="gpa-college-similar__card" href="' . esc_url( get_permalink( $c['id'] ) ) . '"><span class="gpa-college-similar__name">' . esc_html( $c['title'] ) . '</span>'
                . ( '' !== $c['note'] ? '<span class="gpa-college-similar__note">' . esc_html( $c['note'] ) . '</span>' : '' ) . '</a></li>';
        }
        return '<h2 id="similar-colleges" class="gpa-no-number">Similar colleges' . ( '' !== $state ? ' in ' . esc_html( $state ) : '' ) . '</h2>'
            . '<p class="gpa-college-similar__sub">' . ( $v['open'] ? 'Also open to anyone who applies.' : 'With a similar acceptance rate and size.' ) . '</p>'
            . '<ul class="gpa-college-similar">' . $items . '</ul>';
    }

    // "Keep exploring": the state's colleges on the hub and the weighted GPA calculator, as pill links. The Raise GPA
    // calculator is the compare box's "Plan the grades I need" and the high school GPA calculator its help line, so
    // neither repeats here.
    function gpa_college_next_section( array $v ) {
        $state = gpa_college_state( $v['location'] );
        $next  = array();
        if ( '' !== $state ) {
            $next[] = array( add_query_arg( 'search', rawurlencode( $state ), get_post_type_archive_link( 'colleges' ) ), 'All colleges in ' . $state );
        } else {
            $next[] = array( get_post_type_archive_link( 'colleges' ), 'All colleges' );
        }
        $next[] = array( home_url( '/weighted-gpa-calculator/' ), 'Weighted GPA calculator' );
        $items  = '';
        foreach ( $next as $n ) {
            $items .= '<li><a class="gpa-college-next__pill" href="' . esc_url( $n[0] ) . '">' . esc_html( $n[1] ) . '</a></li>';
        }
        return '<h2 id="keep-exploring" class="gpa-no-number">Keep exploring</h2><ul class="gpa-college-next">' . $items . '</ul>';
    }
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
