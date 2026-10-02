<?php
/**
 * GPA Calculator - Block Patterns
 * 
 * Register reusable Gutenberg block patterns for
 * calculator pages and content pages.
 * 
 * Full page pattern + individual section patterns.
 */

// ============================================
// Register Pattern Category
// ============================================
add_action('init', 'gpa_register_pattern_category');
function gpa_register_pattern_category() {
    register_block_pattern_category('gpa-calculator', array(
        'label' => 'GPA Calculator'
    ));
    register_block_pattern_category('gpa-content', array(
        'label' => 'GPA Content Blocks'
    ));
}

// ============================================
// Register Calculator Page Patterns
// ============================================
add_action('init', 'gpa_register_block_patterns');
function gpa_register_block_patterns() {

    // -------------------------------------------
    // FULL PAGE: Calculator Page Template
    // -------------------------------------------
    $full_page = file_get_contents(get_stylesheet_directory() . '/patterns/calculator-page-full.html');
    if ($full_page) {
        register_block_pattern('gpa/calculator-page-full', array(
            'title'       => 'Calculator Page — Full Template',
            'description' => 'Complete calculator page with all 9 sections',
            'categories'  => array('gpa-calculator'),
            'keywords'    => array('gpa', 'calculator', 'template'),
            'content'     => $full_page,
        ));
    }

    // -------------------------------------------
    // FULL PAGE: Content Page Template
    // -------------------------------------------
    $content_page = file_get_contents(get_stylesheet_directory() . '/patterns/content-page-full.html');
    if ($content_page) {
        register_block_pattern('gpa/content-page-full', array(
            'title'       => 'Content Page — Full Template',
            'description' => 'Complete content page with all 13 components (hero, text, lists, table, definition, note, tip, formula, example, image, FAQ, related)',
            'categories'  => array('gpa-content'),
            'keywords'    => array('gpa', 'content', 'guide', 'template'),
            'content'     => $content_page,
        ));
    }

    // -------------------------------------------
    // SECTION: Hero
    // -------------------------------------------
    register_block_pattern('gpa/hero', array(
        'title'       => 'GPA Hero Section',
        'description' => 'Gradient hero with H1, subtitle, and trust badge',
        'categories'  => array('gpa-calculator'),
        'keywords'    => array('hero', 'header'),
        'content'     => '<!-- wp:group {"className":"gpa-hero","layout":{"type":"constrained","contentSize":"800px"}} -->
<div class="wp-block-group gpa-hero">
<!-- wp:heading {"textAlign":"center","level":1,"className":"gpa-hero-title"} -->
<h1 class="wp-block-heading has-text-align-center gpa-hero-title">GPA Calculator</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","className":"gpa-hero-sub"} -->
<p class="has-text-align-center gpa-hero-sub">Calculate your GPA instantly using letter grades and credit hours on the 4.0 scale. Enter each course to see your semester and cumulative GPA update in real time. Updated for 2026.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","className":"gpa-hero-trust"} -->
<p class="has-text-align-center gpa-hero-trust">🏆 Trusted by 2M+ Students</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // SECTION: FAQ (Rank Math)
    // -------------------------------------------
    register_block_pattern('gpa/faq-section', array(
        'title'       => 'GPA FAQ Section',
        'description' => 'FAQ section with badge, heading, and Rank Math FAQ block (3 sample questions)',
        'categories'  => array('gpa-calculator', 'gpa-content'),
        'keywords'    => array('faq', 'questions'),
        'content'     => '<!-- wp:group {"className":"calc-sec-gray","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group calc-sec-gray">
<!-- wp:paragraph {"align":"center","className":"calc-badge calc-badge-green"} -->
<p class="has-text-align-center calc-badge calc-badge-green">Common Questions</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"textAlign":"center","className":"calc-h2"} -->
<h2 class="wp-block-heading has-text-align-center calc-h2">Frequently Asked Questions</h2>
<!-- /wp:heading -->
<!-- wp:rank-math/faq-block {"questions":[{"id":"faq-1","title":"Sample question 1?","content":"Answer to question 1.","visible":true},{"id":"faq-2","title":"Sample question 2?","content":"Answer to question 2.","visible":true},{"id":"faq-3","title":"Sample question 3?","content":"Answer to question 3.","visible":true}]} -->
<div class="wp-block-rank-math-faq-block"><div class="rank-math-faq-item"><h3 class="rank-math-question">Sample question 1?</h3><div class="rank-math-answer">Answer to question 1.</div></div><div class="rank-math-faq-item"><h3 class="rank-math-question">Sample question 2?</h3><div class="rank-math-answer">Answer to question 2.</div></div><div class="rank-math-faq-item"><h3 class="rank-math-question">Sample question 3?</h3><div class="rank-math-answer">Answer to question 3.</div></div></div>
<!-- /wp:rank-math/faq-block -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // SECTION: Related Calculators (2x2 grid)
    // -------------------------------------------
    register_block_pattern('gpa/related-calculators', array(
        'title'       => 'GPA Related Calculators',
        'description' => '2x2 grid of related calculator cards with icons and CTA links',
        'categories'  => array('gpa-calculator', 'gpa-content'),
        'keywords'    => array('related', 'cards', 'links'),
        'content'     => '<!-- wp:group {"className":"calc-sec-white","layout":{"type":"constrained","contentSize":"900px"}} -->
<div class="wp-block-group calc-sec-white">
<!-- wp:heading {"className":"calc-h2"} -->
<h2 class="wp-block-heading calc-h2">Related GPA Calculators</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"calc-desc"} -->
<p class="calc-desc">Explore more free GPA tools to calculate grades in different ways and improve academic planning:</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"className":"calc-related-grid"} -->
<div class="wp-block-columns calc-related-grid">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"calc-rel-card"} -->
<div class="wp-block-group calc-rel-card">
<!-- wp:paragraph {"className":"calc-rel-icon calc-icon-blue"} -->
<p class="calc-rel-icon calc-icon-blue">🎓</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":3,"className":"calc-h3"} -->
<h3 class="wp-block-heading calc-h3">Calculator Name</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"calc-body"} -->
<p class="calc-body">Short description of the calculator.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"calc-cta calc-cta-blue"} -->
<p class="calc-cta calc-cta-blue"><a href="#">Try Calculator →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"className":"calc-rel-card"} -->
<div class="wp-block-group calc-rel-card">
<!-- wp:paragraph {"className":"calc-rel-icon calc-icon-green"} -->
<p class="calc-rel-icon calc-icon-green">📈</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":3,"className":"calc-h3"} -->
<h3 class="wp-block-heading calc-h3">Calculator Name</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"className":"calc-body"} -->
<p class="calc-body">Short description of the calculator.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"calc-cta calc-cta-green"} -->
<p class="calc-cta calc-cta-green"><a href="#">Try Calculator →</a></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Definition Block
    // -------------------------------------------
    register_block_pattern('gpa/definition-block', array(
        'title'       => 'Definition Block',
        'description' => 'Blue left-border definition card with term and description',
        'categories'  => array('gpa-content'),
        'keywords'    => array('definition', 'term', 'glossary'),
        'content'     => '<!-- wp:group {"className":"content-card-definition"} -->
<div class="wp-block-group content-card-definition">
<!-- wp:paragraph {"className":"content-card-title"} -->
<p class="content-card-title">Term Name</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"content-card-body"} -->
<p class="content-card-body">Definition and explanation of the term goes here.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Important Note Block
    // -------------------------------------------
    register_block_pattern('gpa/note-block', array(
        'title'       => 'Important Note Block',
        'description' => 'Amber warning-style note block',
        'categories'  => array('gpa-content'),
        'keywords'    => array('note', 'important', 'warning'),
        'content'     => '<!-- wp:group {"className":"content-card-note"} -->
<div class="wp-block-group content-card-note">
<!-- wp:paragraph {"className":"content-card-title"} -->
<p class="content-card-title">⚠️ Important</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"content-card-body"} -->
<p class="content-card-body">Important information that the reader should pay attention to.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Tip Block
    // -------------------------------------------
    register_block_pattern('gpa/tip-block', array(
        'title'       => 'Tip Block',
        'description' => 'Green tip/advice block',
        'categories'  => array('gpa-content'),
        'keywords'    => array('tip', 'advice', 'hint'),
        'content'     => '<!-- wp:group {"className":"content-card-tip"} -->
<div class="wp-block-group content-card-tip">
<!-- wp:paragraph {"className":"content-card-title"} -->
<p class="content-card-title">💡 Tip</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"content-card-body"} -->
<p class="content-card-body">Helpful tip or advice for the reader.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Formula Block
    // -------------------------------------------
    register_block_pattern('gpa/formula-block', array(
        'title'       => 'Formula Block',
        'description' => 'Blue centered formula display box',
        'categories'  => array('gpa-content'),
        'keywords'    => array('formula', 'math', 'equation'),
        'content'     => '<!-- wp:group {"className":"content-formula"} -->
<div class="wp-block-group content-formula">
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">GPA = Total Grade Points ÷ Total Credits</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Result Block
    // -------------------------------------------
    register_block_pattern('gpa/result-block', array(
        'title'       => 'Result / Answer Block',
        'description' => 'Green-bordered result display with large number',
        'categories'  => array('gpa-content'),
        'keywords'    => array('result', 'answer', 'calculation'),
        'content'     => '<!-- wp:group {"className":"content-result"} -->
<div class="wp-block-group content-result">
<!-- wp:paragraph {"align":"center"} -->
<p class="has-text-align-center">GPA = 34.2 ÷ 10 = <span class="content-result-big">3.42</span></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
    ));

    // -------------------------------------------
    // CONTENT: Section with Badge + H2
    // -------------------------------------------
    register_block_pattern('gpa/section-header', array(
        'title'       => 'Section Header (Badge + H2)',
        'description' => 'Colored badge pill with gradient H2 heading',
        'categories'  => array('gpa-content', 'gpa-calculator'),
        'keywords'    => array('section', 'header', 'heading'),
        'content'     => '<!-- wp:paragraph {"align":"center","className":"calc-badge calc-badge-blue"} -->
<p class="has-text-align-center calc-badge calc-badge-blue">Section Label</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"textAlign":"center","className":"calc-h2"} -->
<h2 class="wp-block-heading has-text-align-center calc-h2">Section Title</h2>
<!-- /wp:heading -->',
    ));

}

// ============================================
// Design overhaul phase 4: callout and takeaways block styles, Sources pattern
// (styles in components.css)
// ============================================
add_action('init', 'gpa_register_component_block_styles');
function gpa_register_component_block_styles() {
    $styles = array(
        'gpa-callout-tip'  => 'Tip callout',
        'gpa-callout-note' => 'Note callout',
        'gpa-callout-warn' => 'Heads-up callout',
        'gpa-takeaways'    => 'Key takeaways',
    );
    foreach ( array( 'core/group', 'core/paragraph' ) as $block ) {
        foreach ( $styles as $name => $label ) {
            if ( 'gpa-takeaways' === $name && 'core/paragraph' === $block ) {
                continue;
            }
            register_block_style( $block, array( 'name' => $name, 'label' => $label ) );
        }
    }
}

add_action('init', 'gpa_register_sources_pattern');
function gpa_register_sources_pattern() {
    register_block_pattern('gpa/sources', array(
        'title'       => 'Sources',
        'description' => 'End-of-page sources list: title, reviewed/updated line (date from the last update), numbered sources with publisher.',
        'categories'  => array('gpa-content'),
        'keywords'    => array('sources', 'citations', 'references'),
        'content'     => '<!-- wp:group {"className":"gpa-sources"} -->
<div class="wp-block-group gpa-sources"><!-- wp:paragraph {"className":"gpa-sources__title"} -->
<p class="gpa-sources__title">Sources</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"className":"gpa-sources__meta"} -->
<p class="gpa-sources__meta">Reviewed by the GPA Calculator team · Updated [gpa_updated_month]</p>
<!-- /wp:paragraph -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://nces.ed.gov/">Source title</a>Publisher</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list --></div>
<!-- /wp:group -->',
    ));
}

/** [gpa_updated_month]: "October 2026", from the post's last update. */
add_shortcode('gpa_updated_month', function () {
    return esc_html( get_the_modified_date( 'F Y' ) );
});
