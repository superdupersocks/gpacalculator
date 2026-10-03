<?php
/**
 * College profile (/admissions/<slug>/), Admissions Phase 3: the site's content-page design. Hero band with the
 * breadcrumb, H1 and a one-paragraph intro (v2: no intro; its first sentence moves into the content); quick facts; numbered sections (GPA, acceptance rate, SAT and ACT,
 * admission requirements, AP credit, net price), each only when the college has data for it; the FAQ as a Rank Math
 * FAQ block; Sources. Styles: layout.css and components.css (shared), admissions.css (college data only), loaded by
 * gpa_college_profile_styles() in college-data.php, which also adds the content template's body classes.
 *
 * Tiers switched on in college-v2.php (template v2) also get the compare box under the quick facts, data-driven FAQs,
 * then, after the FAQ (unnumbered, like similar colleges), the college's official admissions link ("Before you apply"),
 * Sources, and similar colleges in the state last (Digant, 2026-10-03 06:57). A location line ("City, ST · Public · 4-year") sits under the H1.
 *
 * Everything shown comes from college-data.php: gpa_college_view() (figures, with their years), gpa_college_sections(),
 * gpa_college_faqs() (which also feeds the page's FAQPage JSON-LD) and gpa_college_sources(). Pages the federal import
 * hasn't reached show the name, place and type and say their figures aren't verified yet.
 */

get_header();

$post_id  = get_the_ID();
$v        = gpa_college_view( $post_id );
$facts    = gpa_college_quick_facts( $v );
$sections = gpa_college_sections( $v );
$faqs     = gpa_college_faqs( $post_id );
$sources  = gpa_college_sources( $post_id );
// Template v2 (college-v2.php), switched on by tier: compare box, similar colleges and the official admissions link
$v2       = function_exists( 'gpa_college_v2' ) && gpa_college_v2( $post_id );
$compare  = $v2 ? gpa_college_compare_box( $v ) : '';
$official = $v2 ? gpa_college_official_link( $post_id ) : null;
// v1 opens with a two-sentence intro in the hero. v2 keeps the hero short (Digant, 2026-10-03 07:54): the admit rate is
// already in the quick facts, and the sentence about the college (type, place, undergraduates) closes the acceptance
// rate section, or opens the first section when there isn't one.
$intro    = $v2 ? '' : gpa_college_intro( $v );
if ( $v2 && $sections && '' !== ( $about = gpa_college_intro( $v, true ) ) ) {
	$at = array_search( 'acceptance-rate', array_column( $sections, 'id' ), true );
	if ( false === $at ) {
		$sections[0]['html'] = '<p>' . esc_html( $about ) . '</p>' . $sections[0]['html'];
	} else {
		$sections[ $at ]['html'] .= '<p class="gpa-college-about">' . esc_html( $about ) . '</p>';
	}
}
// The mid-article ad goes before the middle section, as on content pages (after the notice when there are none)
$ad_at    = count( $sections ) > 1 ? (int) floor( count( $sections ) / 2 ) : count( $sections );
?>

<div class="content-area" id="primary">
	<main class="site-main" id="main">
		<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
			<div class="inside-article">
				<header class="entry-header">
					<?php gpa_render_breadcrumb(); ?>
					<h1 class="entry-title"><?php echo esc_html( $v['name'] ); ?> <span class="gpa-college-h1-sub"><?php echo esc_html( gpa_college_h1_sub( $v ) ); ?></span></h1>
					<?php if ( $v2 && '' !== ( $where = gpa_college_location_line( $post_id ) ) ) : ?>
					<p class="gpa-college-where"><?php echo esc_html( $where ); ?></p>
					<?php endif; ?>
					<?php if ( '' !== $intro ) : ?>
					<div class="rx-hero-intro"><p><?php echo esc_html( $intro ); ?></p></div>
					<?php endif; ?>
				</header>

				<div class="entry-content">
					<?php if ( $facts ) : ?>
					<blockquote class="wp-block-quote gpa-college-facts"><ul class="wp-block-list">
						<?php foreach ( $facts as $fact ) : ?>
						<li><strong><?php echo esc_html( $fact[0] ); ?>:</strong> <?php echo $fact[1]; // escaped in gpa_college_quick_facts() ?></li>
						<?php endforeach; ?>
					</ul></blockquote>
					<?php endif; ?>

					<?php
					// "On this page" (Design spec rev 32, "In-page navigation", Digant 2026-10-03 18:29): a <details> row,
					// collapsed on every screen size, under the key-facts box and above the first numbered section. Each
					// link's text is its H2's text word for word; the links are in the HTML while collapsed. Lists the
					// numbered H2s only (no FAQ, Similar colleges, Keep planning or Sources), on pages with 4 or more.
					// Markup = Design's shared component (Core Details block around the Rank Math TOC block; layout.css 11b,
					// design f183e95). college-v2.php keeps the theme's browser-built TOC (gpa_toc_builder) off college pages.
					$toc = '' !== $compare ? array( 'compare' => 'How does your GPA compare?' ) : array();
					foreach ( $sections as $section ) {
						$toc[ $section['id'] ] = $section['title'];
					}
					?>
					<?php if ( count( $toc ) >= 4 ) : ?>
					<details class="wp-block-details gpa-toc">
						<summary>On this page <span class="gpa-toc__count">· <?php echo (int) count( $toc ); ?> sections</span></summary>
						<div class="wp-block-rank-math-toc-block"><nav aria-label="On this page"><ul>
							<?php foreach ( $toc as $toc_id => $toc_h2 ) : ?>
							<li><a href="#<?php echo esc_attr( $toc_id ); ?>"><?php echo esc_html( $toc_h2 ); ?></a></li>
							<?php endforeach; ?>
						</ul></nav></div>
					</details>
					<?php endif; ?>

					<?php if ( '' !== $compare ) : ?>
					<h2 id="compare">How does your GPA compare?</h2>
					<?php echo $compare; // built from escaped values in gpa_college_compare_box() ?>
					<?php endif; ?>

					<?php if ( ! $v['fresh'] ) : ?>
					<div class="gpa-callout gpa-callout--note"><p><strong>Figures under review</strong>We don't have verified admissions figures for <?php echo esc_html( $v['name'] ); ?> yet, so this page doesn't list any. The college's admissions office has its current requirements.</p></div>
					<?php endif; ?>

					<?php foreach ( $sections as $i => $section ) : ?>
					<?php if ( $i === $ad_at ) : ?>
<!-- Tag ID: gpacalculator-net_incontent_midarticle -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_midarticle">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_midarticle", slotId: "gpacalculator-net_incontent_midarticle" });
  </script>
</div>
					<?php endif; ?>
					<h2 id="<?php echo esc_attr( $section['id'] ); ?>"><?php echo esc_html( $section['title'] ); ?></h2>
					<?php echo $section['html']; // built from escaped values in gpa_college_sections() ?>
					<?php endforeach; ?>

					<?php if ( count( $sections ) === $ad_at ) : ?>
<!-- Tag ID: gpacalculator-net_incontent_midarticle -->
<div align="center" data-freestar-ad="__240x400 __336x280" id="gpacalculator-net_incontent_midarticle">
  <script data-cfasync="false" type="text/javascript">
    freestar.config.enabled_slots.push({ placementName: "gpacalculator-net_incontent_midarticle", slotId: "gpacalculator-net_incontent_midarticle" });
  </script>
</div>
					<?php endif; ?>

					<?php if ( $faqs ) : ?>
					<h2 id="faq"<?php echo $v2 ? ' class="gpa-no-number"' : ''; ?>>Frequently asked questions</h2>
					<?php
					// Each question is a <details> with its answer in the HTML, the first one open (Digant 2026-10-03 16:40),
					// so nothing is added on open and the FAQPage JSON-LD (same gpa_college_faqs() text) matches the page.
					// The items aren't .rank-math-list-item, so the theme's JS accordion (gpa_faq_accordion) leaves them alone.
					?>
					<div id="rank-math-faq" class="rank-math-block gpa-faq--details">
						<div class="rank-math-list">
							<?php foreach ( $faqs as $i => $faq ) : ?>
							<details id="faq-<?php echo (int) $i + 1; ?>" class="gpa-faq-item"<?php echo 0 === $i ? ' open' : ''; ?>>
								<summary class="rank-math-question"><?php echo esc_html( $faq['question'] ); ?></summary>
								<div class="rank-math-answer"><p><?php echo wp_kses_post( $faq['answer'] ); ?></p></div>
							</details>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<?php if ( $official ) : ?>
					<div class="gpa-callout gpa-callout--note gpa-college-official"><p><strong>Before you apply</strong>Requirements can differ by program and change from year to year. Confirm the details on <a href="<?php echo esc_url( $official[0] ); ?>" rel="noopener"><?php echo esc_html( $v['plain'] . '\'s ' . $official[1] ); ?></a>.</p></div>
					<?php endif; ?>

					<?php if ( $sources ) : ?>
					<div class="gpa-sources">
						<p class="gpa-sources__title">Sources</p>
						<ol>
							<?php foreach ( $sources as $source ) : ?>
							<li><a href="<?php echo esc_url( $source['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $source['link'] ); ?></a><?php echo esc_html( $source['what'] ); ?></li>
							<?php endforeach; ?>
						</ol>
					</div>
					<?php endif; ?>

					<?php if ( $v2 && $v['fresh'] ) : ?>
					<?php echo gpa_college_similar_section( $v ); // built from escaped values ?>
					<?php endif; ?>

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
