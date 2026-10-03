<?php
/**
 * Template part: College admissions database grid (CPT `colleges`).
 *
 * Extracted from archive-colleges.php so the SAME grid renders both on the CPT
 * archive (when reachable) AND inside the `[gpa_college_archive]` shortcode that
 * powers the canonical /admission/ landing page (T10.7 fix — the old
 * [CollegeDB_full] plugin shortcode was disabled on staging). Self-contained:
 * builds its own query, uses the global gpa_render_college_card(), and localizes
 * database-ajax.js. The caller must have enqueued database-ajax.js + database-page.css.
 *
 * @package GeneratePress Child
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ============================================
// Initial query: first 30 colleges sorted by GPA DESC
// ============================================
$paged = 1;
$per_page = 30;

$args = array(
    'post_type'      => 'colleges',
    'posts_per_page' => $per_page,
    'paged'          => $paged,
    'post_status'    => 'publish',
    'meta_key'       => 'average_gpa',
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
);

$college_query = new WP_Query( $args );
$total_colleges = $college_query->found_posts;
$total_pages = $college_query->max_num_pages;
?>

<div class="db-archive-page">

    <?php if ( function_exists( 'gpa_render_breadcrumb' ) ) { gpa_render_breadcrumb(); } // T10.6 ?>

    <!-- ============================================
         SECTION 1: HERO / HEADER
         ============================================ -->
    <section class="db-archive-hero">
        <div class="db-archive-hero__bg"></div>
        <div class="db-archive-hero__overlay"></div>
        <div class="db-archive-hero__content">
            <h1 class="db-archive-hero__title">US College Admissions Database</h1>
            <p class="db-archive-hero__subtitle">Browse admission requirements, GPA scores, and acceptance rates for 3,700+ colleges and universities</p>
            <div class="db-archive-hero__stats">
                <div class="db-archive-hero__stat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"></path>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"></path>
                    </svg>
                    <span>3,720 Colleges</span>
                </div>
                <div class="db-archive-hero__stat-divider"></div>
                <div class="db-archive-hero__stat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <span>Updated 2026</span>
                </div>
                <div class="db-archive-hero__stat-divider"></div>
                <div class="db-archive-hero__stat">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                        <polyline points="9 12 11 14 15 10"></polyline>
                    </svg>
                    <span>Free Access</span>
                </div>
            </div>
        </div>
    </section>

    <div class="db-container">

        <!-- ============================================
             SECTION 2: SEARCH BAR
             ============================================ -->
        <div class="db-archive-search">
            <div class="db-archive-search__wrapper">
                <svg class="db-archive-search__icon" xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                <input
                    type="text"
                    id="db-search-input"
                    class="db-archive-search__input"
                    placeholder="Search by college name, state, or city..."
                    autocomplete="off"
                />
                <div class="db-archive-search__spinner" id="db-search-spinner" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                    </svg>
                </div>
            </div>
        </div>

        <!-- ============================================
             SECTION 3: QUICK FILTER PILLS
             ============================================ -->
        <div class="db-archive-pills" id="db-quick-pills">
            <button class="db-archive-pill db-archive-pill--active" data-filter="all">All</button>
            <button class="db-archive-pill" data-filter="ivy_league">Ivy League</button>
            <button class="db-archive-pill" data-filter="public">Public</button>
            <button class="db-archive-pill" data-filter="private">Private</button>
            <button class="db-archive-pill" data-filter="high_acceptance">High Acceptance</button>
            <button class="db-archive-pill" data-filter="top_rated">Top Rated</button>
        </div>

        <!-- ============================================
             SECTION 4: ADVANCED FILTERS (Collapsible)
             ============================================ -->
        <div class="db-archive-filters">
            <button class="db-archive-filters__toggle" id="db-filters-toggle" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                </svg>
                <span>Advanced Filters</span>
                <svg class="db-archive-filters__chevron" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"></polyline>
                </svg>
            </button>
            <div class="db-archive-filters__panel" id="db-filters-panel" style="display:none;">
                <div class="db-archive-filters__grid">
                    <!-- Ownership -->
                    <div class="db-archive-filters__group">
                        <label class="db-archive-filters__label" for="db-filter-ownership">Ownership</label>
                        <select id="db-filter-ownership" class="db-archive-filters__select" data-filter="ownership">
                            <option value="">All</option>
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                        </select>
                    </div>

                    <!-- Acceptance Rate -->
                    <div class="db-archive-filters__group">
                        <label class="db-archive-filters__label" for="db-filter-acceptance">Acceptance Rate</label>
                        <select id="db-filter-acceptance" class="db-archive-filters__select" data-filter="acceptance_rate">
                            <option value="">All</option>
                            <option value="under_10">Under 10%</option>
                            <option value="under_25">Under 25%</option>
                            <option value="under_50">Under 50%</option>
                            <option value="over_50">50%+</option>
                        </select>
                    </div>

                    <!-- GPA -->
                    <div class="db-archive-filters__group">
                        <label class="db-archive-filters__label" for="db-filter-gpa">GPA</label>
                        <select id="db-filter-gpa" class="db-archive-filters__select" data-filter="gpa">
                            <option value="">All</option>
                            <option value="3.5_plus">3.5+</option>
                            <option value="3.0_3.5">3.0 - 3.5</option>
                            <option value="under_3.0">Under 3.0</option>
                        </select>
                    </div>

                    <!-- SAT -->
                    <div class="db-archive-filters__group">
                        <label class="db-archive-filters__label" for="db-filter-sat">SAT</label>
                        <select id="db-filter-sat" class="db-archive-filters__select" data-filter="sat">
                            <option value="">All</option>
                            <option value="1400_plus">1400+</option>
                            <option value="1200_1400">1200 - 1400</option>
                            <option value="under_1200">Under 1200</option>
                        </select>
                    </div>

                    <!-- Sort By -->
                    <div class="db-archive-filters__group">
                        <label class="db-archive-filters__label" for="db-filter-sort">Sort By</label>
                        <select id="db-filter-sort" class="db-archive-filters__select" data-filter="sort">
                            <option value="gpa_desc">GPA (High to Low)</option>
                            <option value="acceptance_asc">Acceptance Rate (Low to High)</option>
                            <option value="sat_desc">SAT Score (High to Low)</option>
                            <option value="name_asc">Name A-Z</option>
                        </select>
                    </div>
                </div>

                <!-- Reset Filters -->
                <div class="db-archive-filters__actions">
                    <button class="db-archive-filters__reset" id="db-filters-reset" type="button">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="1 4 1 10 7 10"></polyline>
                            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path>
                        </svg>
                        <span>Reset Filters</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ============================================
             SECTION 5: RESULTS COUNT
             ============================================ -->
        <div class="db-archive-results-info">
            <p class="db-archive-results-info__text" id="db-results-count">
                Showing <span id="db-showing-count"><?php echo min( $per_page, $total_colleges ); ?></span> of <span id="db-total-count"><?php echo esc_html( number_format( $total_colleges ) ); ?></span> colleges
            </p>
        </div>

        <!-- ============================================
             SECTION 5b: INTRO CONTENT (H2 sections above listing)
             ============================================ -->
        <section class="db-archive-intro" aria-labelledby="db-intro-compare">
            <h2 id="db-intro-compare" class="db-archive-intro__title">Compare admission stats</h2>
            <p class="db-archive-intro__text">
                Each college profile shows acceptance rate, average GPA, and the 25th-75th percentile SAT and ACT score ranges side-by-side. Use the filters above to narrow by ownership, acceptance rate, GPA threshold, or SAT band, then sort to compare schools on a single metric.
            </p>
        </section>

        <section class="db-archive-intro" aria-labelledby="db-intro-methodology">
            <h2 id="db-intro-methodology" class="db-archive-intro__title">Methodology</h2>
            <p class="db-archive-intro__text">
                Acceptance rates, score ranges, and enrollment figures are sourced from each institution's most recent Common Data Set and IPEDS submission. Average GPA reflects reported admitted-student data where available. Profiles are refreshed each admissions cycle &mdash; flag any discrepancy and we'll re-check the source.
            </p>
        </section>

        <!-- ============================================
             SECTION 6: COLLEGE CARDS GRID
             ============================================ -->
        <h2 id="db-listing-heading" class="db-archive-section-title">Browse colleges by name</h2>
        <div class="db-archive-grid" id="db-college-grid" aria-labelledby="db-listing-heading">
            <?php
            if ( $college_query->have_posts() ) :
                while ( $college_query->have_posts() ) :
                    $college_query->the_post();
                    echo gpa_render_college_card( get_the_ID() );
                endwhile;
                wp_reset_postdata();
            endif;
            ?>
        </div>

        <!-- ============================================
             SECTION 7: NO RESULTS STATE
             ============================================ -->
        <div class="db-archive-no-results" id="db-no-results" style="display:none;">
            <div class="db-archive-no-results__content">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    <line x1="8" y1="8" x2="14" y2="14"></line>
                    <line x1="14" y1="8" x2="8" y2="14"></line>
                </svg>
                <h3 class="db-archive-no-results__title">No colleges match your filters</h3>
                <p class="db-archive-no-results__text">Try adjusting your search criteria or reset the filters to see all colleges.</p>
                <button class="db-archive-no-results__reset" id="db-no-results-reset" type="button">Reset All Filters</button>
            </div>
        </div>

        <!-- ============================================
             SECTION 8: LOAD MORE BUTTON
             ============================================ -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="db-archive-load-more" id="db-load-more-wrap">
                <button class="db-archive-load-more__btn" id="db-load-more-btn" type="button"
                    data-page="1"
                    data-max-pages="<?php echo esc_attr( $total_pages ); ?>">
                    <span class="db-archive-load-more__text">Load More Colleges</span>
                    <span class="db-archive-load-more__spinner" style="display:none;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12a9 9 0 1 1-6.219-8.56"></path>
                        </svg>
                        Loading...
                    </span>
                </button>
            </div>
        <?php endif; ?>

        <!-- Skeleton loader template for load more -->
        <template id="db-skeleton-card-template">
            <div class="db-college-card db-college-card--skeleton">
                <div class="db-skeleton db-skeleton--badges"></div>
                <div class="db-skeleton db-skeleton--title"></div>
                <div class="db-skeleton db-skeleton--location"></div>
                <div class="db-skeleton db-skeleton--stats"></div>
                <div class="db-skeleton db-skeleton--test-box"></div>
                <div class="db-skeleton db-skeleton--test-box"></div>
            </div>
        </template>

    </div><!-- .db-container -->

</div><!-- .db-archive-page -->

<?php
// Localize script data for AJAX
wp_localize_script( 'database-ajax', 'gpa_db_ajax', array(
    'ajax_url'       => admin_url( 'admin-ajax.php' ),
    'nonce'          => wp_create_nonce( 'gpa_db_filter_nonce' ),
    'per_page'       => $per_page,
    'total_colleges' => $total_colleges,
    'total_pages'    => $total_pages,
) );
