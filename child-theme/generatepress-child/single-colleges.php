<?php
/**
 * College profile (/admissions/<slug>/), Admissions Phase 3: the site's content-page design. Hero band with the
 * breadcrumb, H1 and a one-paragraph intro; quick facts; numbered sections (GPA, acceptance rate, SAT and ACT,
 * admission requirements, AP credit, net price), each only when the college has data for it; the FAQ as a Rank Math
 * FAQ block; Sources. Styles: layout.css and components.css (shared), admissions.css (college data only), loaded by
 * gpa_college_profile_styles() in college-data.php, which also adds the content template's body classes.
 *
 * Everything shown comes from college-data.php: gpa_college_view() (figures, with their years), gpa_college_sections(),
 * gpa_college_faqs() (which also feeds the page's FAQPage JSON-LD) and gpa_college_sources(). Pages the federal import
 * hasn't reached show the name, place and type and say their figures aren't verified yet.
 */

get_header();

$post_id  = get_the_ID();
$v        = gpa_college_view( $post_id );
$intro    = gpa_college_intro( $v );
$facts    = gpa_college_quick_facts( $v );
$sections = gpa_college_sections( $v );
$faqs     = gpa_college_faqs( $post_id );
$sources  = gpa_college_sources( $post_id );
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
					<h2 id="faq">Frequently asked questions</h2>
					<div id="rank-math-faq" class="rank-math-block">
						<div class="rank-math-list">
							<?php foreach ( $faqs as $i => $faq ) : ?>
							<div id="faq-<?php echo (int) $i + 1; ?>" class="rank-math-list-item">
								<h3 class="rank-math-question"><?php echo esc_html( $faq['question'] ); ?></h3>
								<div class="rank-math-answer"><p><?php echo wp_kses_post( $faq['answer'] ); ?></p></div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
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
