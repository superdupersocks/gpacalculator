<?php
/**
 * Template part: the college finder (search, filters and the list of colleges), Admissions Phase 3.
 *
 * Printed by the /admissions/ hub (archive-colleges.php), where it rises into the hero like a calculator card, and by
 * the [gpa_college_archive] shortcode. The search bar has its own Search button and three example searches; the
 * list searches as you type too. Builds the first page of results itself (on /admissions/page/N/, that page's), one
 * gpa_render_college_card() card per college (college-data.php) under the legend of the difficulty levels, and gives
 * database-ajax.js its settings; the ids are the ones that script looks for.
 * The caller enqueues database-ajax.js and admissions.css.
 *
 * @package GeneratePress Child
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// The first list: 30 colleges by name, or on the hub's own pages (/admissions/page/N/) that page's 30 (the AJAX
// handler, gpa_ajax_filter_colleges(), serves the rest)
$per_page      = function_exists( 'gpa_college_hub_per_page' ) ? gpa_college_hub_per_page() : 30;
$paged         = function_exists( 'gpa_college_hub_paged' ) ? gpa_college_hub_paged() : 1;
$college_query = new WP_Query( array(
	'post_type'      => 'colleges',
	'posts_per_page' => $per_page,
	'paged'          => $paged,
	'post_status'    => 'publish',
	'orderby'        => 'title',
	'order'          => 'ASC',
) );
$total_colleges = (int) $college_query->found_posts;
$total_pages    = (int) $college_query->max_num_pages;
$stats          = gpa_college_hub_stats();
?>
<div class="gpa-hub" id="college-finder">

	<div class="gpa-hub__search db-archive-search" role="search">
		<label class="gpa-hub__search-label" for="db-search-input">Find a college</label>
		<div class="gpa-hub__bar">
			<svg class="gpa-hub__search-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<circle cx="11" cy="11" r="8"></circle>
				<line x1="21" y1="21" x2="16.65" y2="16.65"></line>
			</svg>
			<input type="search" id="db-search-input" class="gpa-hub__input" placeholder="College name, city or state" autocomplete="off" enterkeyhint="search" />
			<span class="gpa-hub__spinner" id="db-search-spinner" style="display:none;" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"></path></svg>
			</span>
			<button class="gpa-hub__go" id="db-search-btn" type="button">Search</button>
		</div>
		<div class="gpa-hub__try" id="db-search-examples">Try <button class="gpa-hub__example" type="button" data-search="Harvard">Harvard</button>, <button class="gpa-hub__example" type="button" data-search="Boston">Boston</button> or <button class="gpa-hub__example" type="button" data-search="Texas">Texas</button></div>
	</div>

	<div class="gpa-hub__controls">
		<div class="gpa-hub__pills" id="db-quick-pills" role="group" aria-label="Quick filters">
			<button class="db-archive-pill db-archive-pill--active" data-filter="all" type="button">All</button>
			<button class="db-archive-pill" data-filter="ivy_league" type="button">Ivy League</button>
			<button class="db-archive-pill" data-filter="public" type="button">Public</button>
			<button class="db-archive-pill" data-filter="private" type="button">Private</button>
			<button class="db-archive-pill" data-filter="high_acceptance" type="button">High acceptance</button>
		</div>
		<button class="gpa-hub__more db-archive-filters__toggle" id="db-filters-toggle" type="button" aria-expanded="false" aria-controls="db-filters-panel">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"></line><line x1="7" y1="12" x2="17" y2="12"></line><line x1="10" y1="18" x2="14" y2="18"></line></svg>
			<span>More filters</span>
		</button>
	</div>

	<div class="gpa-hub__panel" id="db-filters-panel" style="display:none;">
		<div class="gpa-hub__field">
			<label for="db-filter-ownership">Ownership</label>
			<select id="db-filter-ownership" data-filter="ownership">
				<option value="">Public and private</option>
				<option value="public">Public</option>
				<option value="private">Private</option>
			</select>
		</div>
		<div class="gpa-hub__field">
			<label for="db-filter-acceptance">Acceptance rate</label>
			<select id="db-filter-acceptance" data-filter="acceptance_rate">
				<option value="">Any</option>
				<option value="under_10">Under 10%</option>
				<option value="under_25">Under 25%</option>
				<option value="under_50">Under 50%</option>
				<option value="over_50">50%+ or open admission</option>
			</select>
		</div>
		<div class="gpa-hub__field">
			<label for="db-filter-sat">Average SAT (estimate)</label>
			<select id="db-filter-sat" data-filter="sat">
				<option value="">Any</option>
				<option value="1400_plus">1400 or higher</option>
				<option value="1200_1400">1200 to 1400</option>
				<option value="under_1200">Under 1200</option>
			</select>
		</div>
		<div class="gpa-hub__field">
			<label for="db-filter-sort">Sort by</label>
			<select id="db-filter-sort" data-filter="sort">
				<option value="name_asc">Name, A to Z</option>
				<option value="acceptance_asc">Lowest acceptance rate</option>
				<option value="sat_desc">Highest average SAT</option>
			</select>
		</div>
		<button class="gpa-hub__reset" id="db-filters-reset" type="button">Clear filters</button>
	</div>

	<?php
	// ?gpa=3.5 (the GPA-band lists' "See all"): say what the list is
	$band_key   = isset( $_GET['gpa'] ) ? sanitize_text_field( wp_unslash( $_GET['gpa'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$band_range = function_exists( 'gpa_college_band_range' ) ? gpa_college_band_range( $band_key ) : null;
	if ( $band_range ) :
		?>
	<div class="gpa-callout gpa-callout--note gpa-hub__band" id="db-gpa-band"><p><strong>Colleges where a <?php echo esc_html( $band_key ); ?> GPA is typical</strong>Each of these colleges reported an average high school GPA of <?php echo esc_html( sprintf( '%.2f', $band_range[0] ) ); ?> to <?php echo esc_html( sprintf( '%.2f', $band_range[1] ) ); ?> in its Common Data Set<?php echo (float) $band_key > 4.0 ? ', on a weighted scale' : ''; ?>. <?php echo (float) $band_key > 4.0 ? 'A typical GPA isn\'t a cutoff: colleges review each application.' : 'Colleges don\'t all say whether their average is weighted. Many count honors and AP courses, so compare with your weighted GPA if you have one.'; ?></p></div>
	<?php endif; ?>

	<div class="gpa-hub__count" id="db-results-count" aria-live="polite">
		Showing <span id="db-showing-count"><?php echo esc_html( $college_query->post_count ); ?></span> of <span id="db-total-count"><?php echo esc_html( number_format( $total_colleges ) ); ?></span> colleges
	</div>

	<div class="gpa-hub__legend">
		<span class="gpa-hub__legend-title">How hard to get into<?php echo '' !== $stats['fall'] ? ', by ' . esc_html( $stats['fall'] ) . ' acceptance rate' : ''; ?>:</span>
		<span class="gpa-hub__keys" role="list">
			<?php foreach ( gpa_college_difficulty_legend() as $level ) : ?>
			<span class="gpa-hub__key gpa-hub__key--<?php echo esc_attr( $level[0] ); ?>" role="listitem"><?php echo gpa_college_difficulty_meter( $level[0] ); // fixed markup ?><?php echo esc_html( $level[1] ); ?><?php echo '' !== $level[2] ? ' <span class="gpa-hub__range">' . esc_html( $level[2] ) . '</span>' : ''; ?></span>
			<?php endforeach; ?>
		</span>
	</div>

	<div class="gpa-hub__head" aria-hidden="true">
		<span>College</span>
		<span>Average GPA<small>High school</small></span>
		<span>SAT<small>Middle 50%</small></span>
		<span>ACT<small>Middle 50%</small></span>
	</div>
	<div class="gpa-hub__list" id="db-college-grid" role="list" aria-label="Colleges">
		<?php
		while ( $college_query->have_posts() ) {
			$college_query->the_post();
			echo gpa_render_college_card( get_the_ID() ); // escaped in gpa_render_college_card()
		}
		wp_reset_postdata();
		?>
	</div>

	<div class="gpa-hub__empty" id="db-no-results" style="display:none;">
		<strong>No colleges match</strong>
		<span>Try another name or place, or clear the filters.</span>
		<button class="gpa-hub__reset" id="db-no-results-reset" type="button">Clear search and filters</button>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
	<div class="gpa-hub__more-wrap" id="db-load-more-wrap"<?php echo $paged >= $total_pages ? ' style="display:none;"' : ''; ?>>
		<?php
		// On the hub, a link to its next page, which the script loads in place: search engines follow it to every
		// college. In the [gpa_college_archive] shortcode, and on the hub's last page, a button.
		$next_url = is_post_type_archive( 'colleges' ) && $paged < $total_pages && function_exists( 'gpa_college_hub_page_url' ) ? gpa_college_hub_page_url( $paged + 1 ) : '';
		$attrs    = 'class="gpa-hub__load" id="db-load-more-btn" data-page="' . esc_attr( $paged ) . '" data-max-pages="' . esc_attr( $total_pages ) . '"';
		echo '' !== $next_url ? '<a ' . $attrs . ' href="' . esc_url( $next_url ) . '">' : '<button ' . $attrs . ' type="button">'; // escaped above
		?>
			<span class="db-archive-load-more__text">Show more colleges</span>
			<span class="db-archive-load-more__spinner" style="display:none;">Loading…</span>
		<?php echo '' !== $next_url ? '</a>' : '</button>'; ?>
	</div>
	<?php endif; ?>

	<template id="db-skeleton-card-template">
		<div class="db-college-card db-college-card--skeleton gpa-hub-row" aria-hidden="true">
			<span class="gpa-hub-skel gpa-hub-skel--name"></span><span class="gpa-hub-skel"></span><span class="gpa-hub-skel"></span><span class="gpa-hub-skel"></span>
		</div>
	</template>

</div>
<?php
wp_localize_script( 'database-ajax', 'gpa_db_ajax', array(
	'ajax_url'       => admin_url( 'admin-ajax.php' ),
	'nonce'          => wp_create_nonce( 'gpa_db_filter_nonce' ),
	'per_page'       => $per_page,
	'page'           => $paged,
	'total_colleges' => $total_colleges,
	'total_pages'    => $total_pages,
) );
