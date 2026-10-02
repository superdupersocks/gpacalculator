<?php
/**
 * The /admissions/ hub (archive of the colleges post type), Admissions Phase 3: the site's content-page design.
 *
 * Hero band with the breadcrumb, the H1 and a one-paragraph intro with the live counts; the college finder
 * (template-parts/college-db-archive.php), which rises into the hero like a calculator card; then how to read the
 * figures, where they come from, and Sources. Freestar's mid-article and bottom slots keep their
 * exact markup. Styles: layout.css, components.css, content-styles.css and admissions.css, loaded with the content
 * template's body classes by gpa_college_profile_styles() in college-data.php; search and filters: database-ajax.js.
 */

get_header();

$stats = gpa_college_hub_stats();
$fall  = '' !== $stats['fall'] ? $stats['fall'] : 'the latest year';
?>

<div class="content-area" id="primary">
	<main class="site-main" id="main">
		<article class="gpa-college-hub">
			<div class="inside-article">
				<header class="entry-header">
					<?php gpa_render_breadcrumb(); ?>
					<h1 class="entry-title">US College Admissions Database</h1>
					<div class="rx-hero-intro"><p>Acceptance rates, SAT and ACT score ranges and admission requirements for <?php echo esc_html( number_format( $stats['colleges'] ) ); ?> US colleges and universities<?php if ( $stats['gpa'] ) : ?>, with the average high school GPA of <?php echo esc_html( number_format( $stats['gpa'] ) ); ?> of them as each college reported it<?php endif; ?>.</p></div>
				</header>

				<div class="entry-content">
					<?php get_template_part( 'template-parts/college-db-archive' ); ?>

<!-- Tag ID: gpacalculator-net_incontent_midarticle -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_midarticle">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_midarticle", slotId: "gpacalculator-net_incontent_midarticle" });
  </script>
</div>

					<h2 id="reading-the-figures">How to read the figures</h2>
					<?php
					// "very hard under 10%, hard 10–24%, ... and easy 75% or more", from the levels the tags use
					$levels = array();
					foreach ( gpa_college_difficulty_legend() as $level ) {
						if ( 'open' !== $level[0] ) {
							$levels[] = strtolower( $level[1] ) . ' ' . $level[2];
						}
					}
					$last = array_pop( $levels );
					?>
					<ul>
						<li><strong>Average GPA:</strong> shown only when a college publishes it in its own Common Data Set and we could check it, with the year and whether it's weighted. Colleges calculate GPA in different ways, so an average can't be compared directly with your own GPA.</li>
						<li><strong>How hard to get into:</strong> each college's tag, with its color and meter, comes from its acceptance rate, the share of first-year applicants it admitted for <?php echo esc_html( $fall ); ?>, as it reported to the U.S. Department of Education: <?php echo esc_html( implode( ', ', $levels ) . ' and ' . $last ); ?>. Colleges with open admission accept every applicant, so they don't have an acceptance rate.</li>
						<li><strong>SAT and ACT:</strong> the middle 50% of scores of the first-year students who enrolled in <?php echo esc_html( $fall ); ?> and sent scores. A quarter of them scored below the range and a quarter above it. R&amp;W is the SAT's reading and writing section; ACT ranges are composite scores.</li>
						<li><strong>Average SAT filter:</strong> the SAT filter and sort use the College Scorecard's estimate of the average SAT score of admitted students.</li>
					</ul>

					<h2 id="about-the-data">Where the data comes from</h2>
					<p>Acceptance rates, test scores, admission factors, enrollment, net price and credit policies come from IPEDS, the survey that every college taking part in federal student aid programs reports to the U.S. Department of Education each year. Average GPAs come from the colleges' own Common Data Sets. Each college's page lists its sources and the year of every figure.</p>
					<p>Admission policies change from year to year, so check anything important with the college's admissions office.</p>

					<div class="gpa-sources">
						<p class="gpa-sources__title">Sources</p>
						<ol>
							<li><a href="https://nces.ed.gov/ipeds/" target="_blank" rel="noopener">IPEDS: Integrated Postsecondary Education Data System</a>U.S. Department of Education, National Center for Education Statistics<?php echo '' !== $stats['ipeds_release'] ? ' (' . esc_html( $stats['ipeds_release'] ) . ' data)' : ''; ?>: admissions, test scores, admission factors, enrollment, net price and credit policies.</li>
							<li><a href="https://collegescorecard.ed.gov/data/" target="_blank" rel="noopener">College Scorecard data</a>U.S. Department of Education<?php echo '' !== $stats['scorecard_release'] ? ' (' . esc_html( $stats['scorecard_release'] ) . ' release)' : ''; ?>: estimated average SAT score of admitted students.</li>
							<li><a href="https://commondataset.org/" target="_blank" rel="noopener">Common Data Set Initiative</a>The standard format colleges use to publish their own admissions data, including first-year students' average high school GPA.</li>
						</ol>
					</div>

<!-- Tag ID: gpacalculator-net_incontent_bottom -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_bottom">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_bottom", slotId: "gpacalculator-net_incontent_bottom" });
  </script>
</div>
				</div>
			</div>
		</article>
	</main>
</div>

<?php
get_footer();
