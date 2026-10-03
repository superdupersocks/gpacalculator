<?php
// ============================================
// GPA Calculator - Child Theme Functions
// Combined: GPA site + College Database
// ============================================


// ============================================================
// PART 1: HELPER FUNCTIONS
// ============================================================

function gpa_is_calculator_page() {
    return gpa_is_main_calc_landing();
}

function gpa_is_main_calc_landing() {
    return gpa_is_calculator_tool_page();
}

const GPA_CALC_TEMPLATE = 'gpa-calculator-tool';

add_filter( 'theme_page_templates', 'gpa_register_calculator_template' );
function gpa_register_calculator_template( $templates ) {
    $templates[ GPA_CALC_TEMPLATE ] = 'GPA – Calculator Tool';
    return $templates;
}

function gpa_is_calculator_tool_page() {
    return is_page() && GPA_CALC_TEMPLATE === get_page_template_slug();
}

add_filter( 'template_include', 'gpa_calculator_template_include' );
function gpa_calculator_template_include( $template ) {
    if ( gpa_is_calculator_tool_page() ) {
        $page = get_template_directory() . '/page.php';
        if ( file_exists( $page ) ) {
            return $page;
        }
    }
    return $template;
}

add_filter( 'body_class', 'gpa_calculator_template_body_class' );
function gpa_calculator_template_body_class( $classes ) {
    if ( gpa_is_calculator_tool_page() ) {
        $classes[] = 'gpa-calc-tool';
        if ( ! in_array( 'page-template-template-calculator', $classes, true ) ) {
            $classes[] = 'page-template-template-calculator';
        }
    }
    return $classes;
}

function gpa_page_is_calculator_tool() {
    if ( gpa_is_calculator_page() ) {
        return true;
    }
    if ( is_front_page() ) {
        $front   = get_post( (int) get_option( 'page_on_front' ) );
        $content = $front ? $front->post_content : '';
    } elseif ( is_singular() ) {
        $post    = get_queried_object();
        $content = ( $post instanceof WP_Post ) ? $post->post_content : '';
    } else {
        return false;
    }
    $calc_shortcodes = apply_filters( 'gpa_calculator_shortcodes', array(
        'gpa-calculator', 'college-gpa-calculator', 'high-school-gpa-calculator',
        'middle-school-gpa-calculator', 'semester-gpa-calculator', 'grade-calculator',
        'final-grade-calculator', 'semester-grade-calculator', 'weighted-grade-calculator',
        'sgpa-to-cgpa-calculator', 'raise-gpa-calculator',
    ) );
    foreach ( $calc_shortcodes as $sc ) {
        if ( has_shortcode( $content, $sc ) ) {
            return true;
        }
    }
    return false;
}

add_filter( 'rank_math/json_ld', 'gpa_strip_software_app_on_non_calc', 105, 2 );
function gpa_strip_software_app_on_non_calc( $data, $jsonld ) {
    if ( ! is_array( $data ) || gpa_page_is_calculator_tool() ) {
        return $data;
    }
    foreach ( $data as $key => $node ) {
        if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
            continue;
        }
        $types = (array) $node['@type'];
        if ( array_intersect( array( 'WebApplication', 'SoftwareApplication' ), $types ) ) {
            unset( $data[ $key ] );
        }
    }
    return $data;
}

function gpa_is_content_page() {
    return is_page_template('page-templates/template-content.php')
        || is_page('gpa-scale')
        || is_page('grade-conversion')
        || is_page('how-to-raise-gpa')
        || is_singular('page');
}

function gpa_is_database_page() {
    return is_page_template('page-templates/template-database-archive.php')
        || is_page_template('page-templates/template-database-single.php')
        || is_singular('colleges')
        || is_post_type_archive('colleges');
}

// ============================================================
// PART 2: CSS ENQUEUE
// ============================================================

add_action('wp_enqueue_scripts', 'gpa_fonts', 4);
function gpa_fonts() {
    wp_enqueue_style(
        'gpa-google-fonts',
        'https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap',
        array(),
        null
    );
}

add_filter('style_loader_tag', 'gpa_async_noncritical_css', 10, 4);
function gpa_async_noncritical_css($tag, $handle, $href, $media) {
    $is_async = ('gpa-google-fonts' === $handle);
    if (! $is_async) {
        return $tag;
    }
    $onload = "this.onload=null;this.media='all';";
    if ( preg_match( "/\smedia=(['\"]).*?\\1/", $tag ) ) {
        $async = preg_replace( "/\smedia=(['\"]).*?\\1/", " media=\"print\" onload=\"{$onload}\"", $tag, 1 );
    } else {
        $async = preg_replace( '/<link\s/', "<link media=\"print\" onload=\"{$onload}\" ", $tag, 1 );
    }

    $noscript_tag = preg_replace( "/\sid=(['\"]).*?\\1/", '', $tag );

    return rtrim( $async ) . '<noscript>' . $noscript_tag . '</noscript>' . "\n";
}

add_filter('wp_resource_hints', 'gpa_font_resource_hints', 10, 2);
function gpa_font_resource_hints($hints, $relation_type) {
    if ('preconnect' === $relation_type) {
        $hints[] = 'https://fonts.googleapis.com';
        $hints[] = array('href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous');
    }
    return $hints;
}

// ============================================================
// T10.1: Freestar ad code (pub.network) — sitewide, all templates
// ============================================================
add_action( 'wp_head', 'gpa_freestar_head', 2 );
function gpa_freestar_head() {
    if ( is_admin() ) {
        return;
    }
    ?>
<link rel="preconnect" href="https://a.pub.network/" crossorigin />
<link rel="preconnect" href="https://btloader.com/" crossorigin />
<link rel="preconnect" href="https://api.btloader.com/" crossorigin />
<link rel="preconnect" href="https://d.pub.network/" crossorigin />
<link rel="preconnect" href="https://c.amazon-adsystem.com" crossorigin />
<link rel="preconnect" href="https://s.amazon-adsystem.com" crossorigin />
<link rel="stylesheet" href="https://a.pub.network/gpacalculator-net/cls.css">
<script data-cfasync="false">
  var freestar = freestar || {};
  freestar.queue = freestar.queue || [];
  freestar.config = freestar.config || {};
  freestar.config.enabled_slots = [];
  freestar.initCallback = function () { (freestar.config.enabled_slots.length === 0) ? freestar.initCallbackCalled = false : freestar.newAdSlots(freestar.config.enabled_slots) };
</script>
<script src="https://a.pub.network/gpacalculator-net/pubfig.min.js" data-cfasync="false" async></script>
<?php
}

add_filter('the_content', 'gpa_add_img_dimensions', 20);
function gpa_add_img_dimensions($content) {
    if (empty($content) || strpos($content, '<img') === false) {
        return $content;
    }
    return preg_replace_callback('/<img\b[^>]*>/i', function ($m) {
        $tag = $m[0];
        if (preg_match('/\swidth\s*=/i', $tag) && preg_match('/\sheight\s*=/i', $tag)) {
            return $tag;
        }
        if (!preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $src)) {
            return $tag;
        }
        $id = attachment_url_to_postid($src[1]);
        if (!$id) {
            return $tag;
        }
        $meta = wp_get_attachment_metadata($id);
        if (empty($meta['width']) || empty($meta['height'])) {
            return $tag;
        }
        return preg_replace(
            '/^<img\b/i',
            '<img width="' . (int) $meta['width'] . '" height="' . (int) $meta['height'] . '"',
            $tag
        );
    }, $content);
}

function gpa_asset_ver( $relative_path ) {
    $file  = get_stylesheet_directory() . '/' . ltrim( $relative_path, '/' );
    $mtime = is_file( $file ) ? filemtime( $file ) : false;
    return $mtime ? (string) $mtime : wp_get_theme()->get( 'Version' );
}

// Load order (design system overhaul): tokens → layout → components → template file
// (homepage / calculator / content / database) → calculator bundle → calc-theme.css.
// Template files depend on 'gpa-components' so they always print after it.
add_action('wp_enqueue_scripts', 'gpa_design_tokens', 5);
function gpa_design_tokens() {
    wp_enqueue_style(
        'gpa-design-tokens',
        get_stylesheet_directory_uri() . '/gpa-design-tokens.css',
        array('gpa-google-fonts'),
        gpa_asset_ver( 'gpa-design-tokens.css' )
    );
    wp_enqueue_style(
        'gpa-layout',
        get_stylesheet_directory_uri() . '/layout.css',
        array('gpa-design-tokens'),
        gpa_asset_ver( 'layout.css' )
    );
    wp_enqueue_style(
        'gpa-components',
        get_stylesheet_directory_uri() . '/components.css',
        array('gpa-layout'),
        gpa_asset_ver( 'components.css' )
    );
}

// Calculator bundles (calc-assets/*.css) are enqueued while the content renders, so they print in the
// footer; calc-theme.css joins that late queue after them.
add_action('wp_footer', 'gpa_calc_theme_styles', 1);
function gpa_calc_theme_styles() {
    $styles = wp_styles();
    foreach ( $styles->queue as $handle ) {
        $src = isset( $styles->registered[ $handle ] ) ? (string) $styles->registered[ $handle ]->src : '';
        if ( false !== strpos( $src, '/calc-assets/' ) ) {
            wp_enqueue_style(
                'gpa-calc-theme',
                get_stylesheet_directory_uri() . '/calc-theme.css',
                array( $handle ),
                gpa_asset_ver( 'calc-theme.css' )
            );
            return;
        }
    }
}

add_action('wp_enqueue_scripts', 'gpa_homepage_styles');
function gpa_homepage_styles() {
    if (is_front_page() || is_page('gpa-calculator')) {
        wp_enqueue_style(
            'gpa-homepage',
            get_stylesheet_directory_uri() . '/gpa-homepage.css',
            array('gpa-components'),
            gpa_asset_ver( 'gpa-homepage.css' )
        );
    }
}

add_action('wp_enqueue_scripts', 'calc_page_styles');
function calc_page_styles() {
    if (gpa_is_main_calc_landing()) {
        wp_enqueue_style(
            'calc-page',
            get_stylesheet_directory_uri() . '/calculator-page.css',
            array('gpa-components'),
            gpa_asset_ver( 'calculator-page.css' )
        );
    }
}

add_action('wp_enqueue_scripts', 'gpa_content_styles');
function gpa_content_styles() {
    if (gpa_is_calculator_page() || gpa_is_content_page()) {
        wp_enqueue_style(
            'gpa-content',
            get_stylesheet_directory_uri() . '/content-styles.css',
            array('gpa-components'),
            gpa_asset_ver( 'content-styles.css' )
        );
    }
}

// GPA scale styles (gpa-scale.css): the /gpa-scale/ hub, its GPA pages and any page that uses the scale chart
// or a [gpa_scale_*] shortcode. Prints after components.css and content-styles.css.
add_action('wp_enqueue_scripts', 'gpa_scale_styles', 20);
function gpa_scale_styles() {
    if ( ! is_singular() ) {
        return;
    }
    $post   = get_post();
    $parent = $post && $post->post_parent ? get_post( $post->post_parent ) : null;
    $on     = $post && ( 'gpa-scale' === $post->post_name || ( $parent && 'gpa-scale' === $parent->post_name )
        || false !== strpos( $post->post_content, 'gpa-scale-table' ) || false !== strpos( $post->post_content, '[gpa_scale_' ) );
    if ( ! $on ) {
        return;
    }
    wp_enqueue_style(
        'gpa-scale',
        get_stylesheet_directory_uri() . '/gpa-scale.css',
        wp_style_is( 'gpa-content', 'enqueued' ) ? array( 'gpa-components', 'gpa-content' ) : array( 'gpa-components' ),
        gpa_asset_ver( 'gpa-scale.css' )
    );
}

add_action('wp_enqueue_scripts', 'gpa_database_page_styles');
function gpa_database_page_styles() {
    if ( gpa_is_database_page() ) {
        wp_enqueue_style(
            'database-page',
            get_stylesheet_directory_uri() . '/database-page.css',
            array('gpa-components'),
            gpa_asset_ver( 'database-page.css' )
        );
    }
    if ( is_post_type_archive( 'colleges' ) ) {
        wp_enqueue_script(
            'database-ajax',
            get_stylesheet_directory_uri() . '/database-ajax.js',
            array(),
            gpa_asset_ver( 'database-ajax.js' ),
            true
        );
    }
}

add_filter('body_class', 'gpa_content_page_body_class');
function gpa_content_page_body_class($classes) {
    if (gpa_is_content_page() && !is_front_page()) {
        $classes[] = 'content-page';
    }
    return $classes;
}

add_filter('body_class', 'gpa_force_featured_image_active_on_calc_pages');
function gpa_force_featured_image_active_on_calc_pages($classes) {
    if ((gpa_is_main_calc_landing() || is_front_page()) && !in_array('featured-image-active', $classes, true)) {
        $classes[] = 'featured-image-active';
    }
    return $classes;
}

add_action('after_setup_theme', 'gpa_editor_style_setup');
function gpa_editor_style_setup() {
    add_editor_style('gpa-design-tokens.css');
    add_editor_style('gpa-homepage.css');
    add_editor_style('calculator-page.css');
}


// ============================================================
// PART 3: DEQUEUE UNWANTED ASSETS
// ============================================================

add_action('wp_enqueue_scripts', 'gpa_dequeue_gp_google_fonts', 100);
function gpa_dequeue_gp_google_fonts() {
    wp_dequeue_style('generate-google-fonts');
    wp_deregister_style('generate-google-fonts');
}

add_action('wp_enqueue_scripts', 'gpa_dequeue_homepage_assets', 100);
function gpa_dequeue_homepage_assets() {
    if (is_front_page() || is_page('gpa-calculator')) {
        wp_dequeue_style('formidable');
        wp_deregister_style('formidable');
        wp_dequeue_style('cdb_main');
        wp_deregister_style('cdb_main');
        wp_dequeue_script('college-db');
        wp_deregister_script('college-db');
        wp_dequeue_script('jquery-ui');
        wp_deregister_script('jquery-ui');
        wp_dequeue_style('jquery-ui');
        wp_deregister_style('jquery-ui');
        wp_dequeue_style('jquery-ui-css');
        wp_deregister_style('jquery-ui-css');
        wp_dequeue_style('jquery-ui-theme');
        wp_deregister_style('jquery-ui-theme');
        wp_dequeue_style('jquery-ui-smoothness');
        wp_deregister_style('jquery-ui-smoothness');
        wp_dequeue_style('adrotate');
        wp_deregister_style('adrotate');
    }
}

add_action('wp_enqueue_scripts', 'gpa_dequeue_calc_page_assets', 100);
function gpa_dequeue_calc_page_assets() {
    if ( ! is_page_template('page-templates/template-calculator.php') && ! gpa_is_main_calc_landing() ) {
        return;
    }

    wp_dequeue_style('cdb_main');
    wp_deregister_style('cdb_main');
    wp_dequeue_script('college-db');
    wp_deregister_script('college-db');

    wp_dequeue_style('formidable');
    wp_dequeue_style('frm_fonts');
    wp_dequeue_style('page-list-style');
}

add_filter('script_loader_src', 'gpa_calc_assets_cache_bust', 20);
add_filter('style_loader_src', 'gpa_calc_assets_cache_bust', 20);
function gpa_calc_assets_cache_bust($src) {
    if (strpos($src, '/calc-assets/') === false) {
        return $src;
    }
    $file = str_replace(
        get_stylesheet_directory_uri(),
        get_stylesheet_directory(),
        strtok($src, '?')
    );
    if (is_file($file)) {
        $src = add_query_arg('v', filemtime($file), $src);
    }
    return $src;
}

add_action('wp_enqueue_scripts', 'gpa_dequeue_acf_non_database', 100);
function gpa_dequeue_acf_non_database() {
    if ( gpa_is_database_page() ) {
        return;
    }

    if ( is_front_page() || gpa_is_calculator_page() || gpa_is_content_page() ) {
        wp_dequeue_style('acf');
        wp_deregister_style('acf');
        wp_dequeue_style('acf-input');
        wp_deregister_style('acf-input');
        wp_dequeue_style('acf-global');
        wp_deregister_style('acf-global');
        wp_dequeue_style('acf-field-group');
        wp_deregister_style('acf-field-group');

        wp_dequeue_script('acf');
        wp_deregister_script('acf');
        wp_dequeue_script('acf-input');
        wp_deregister_script('acf-input');
    }
}

add_action('wp_default_scripts', 'gpa_disable_jquery_migrate_global');
function gpa_disable_jquery_migrate_global($scripts) {
    if ( ! is_admin() && isset($scripts->registered['jquery']) ) {
        $script = $scripts->registered['jquery'];
        if ( $script->deps ) {
            $script->deps = array_diff($script->deps, array('jquery-migrate'));
        }
    }
}

add_action('wp_enqueue_scripts', 'gpa_dequeue_ilj_frontend', 100);
function gpa_dequeue_ilj_frontend() {
    wp_dequeue_script('ilj_admin');
    wp_deregister_script('ilj_admin');
    wp_dequeue_style('ilj_admin');
    wp_deregister_style('ilj_admin');
    wp_dequeue_script('ilj-admin-script');
    wp_deregister_script('ilj-admin-script');
    wp_dequeue_style('ilj-admin-style');
    wp_deregister_style('ilj-admin-style');
}

add_filter('script_loader_tag', 'gpa_defer_calculator_js', 10, 3);
function gpa_defer_calculator_js($tag, $handle, $src) {
    $defer_handles = array('gpa-calculator-app');

    if (in_array($handle, $defer_handles) && strpos($tag, 'defer') === false) {
        $tag = str_replace(' src', ' defer src', $tag);
    }

    return $tag;
}


// ============================================================
// PART 4: GLOBAL CLEANUP
// ============================================================

remove_action('wp_head', 'wp_generator');

remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');

add_action('wp_enqueue_scripts', 'gpa_hide_featured_image_content_pages');
function gpa_hide_featured_image_content_pages() {
    if ( is_front_page() || is_singular( 'colleges' ) ) return;
    remove_action( 'generate_after_entry_header', 'generate_post_image' );
    remove_action( 'generate_before_content', 'generate_post_image' );
    remove_action( 'generate_after_header', 'generate_post_image' );
}

add_action('wp_head', 'gpa_hide_featured_image_css');
function gpa_hide_featured_image_css() {
    if ( is_front_page() || is_singular( 'colleges' ) ) return;
    echo '<style>
        body:not(.home):not(.single-colleges) .post-image,
        body:not(.home):not(.single-colleges) .featured-image,
        body:not(.home):not(.single-colleges) .wp-post-image,
        body:not(.home):not(.single-colleges) .entry-header .post-image,
        body:not(.home):not(.single-colleges) .inside-article > .post-image,
        body:not(.home):not(.single-colleges) .inside-article > a > .post-image,
        body:not(.home):not(.single-colleges) .entry-content > a > img.wp-post-image { display: none !important; }
    </style>';
}

add_filter( 'post_thumbnail_html', 'gpa_strip_thumbnail_html_on_page', 20, 5 );
function gpa_strip_thumbnail_html_on_page( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
    if ( is_front_page() || is_singular( 'colleges' ) ) return $html;
    if ( ! in_the_loop() || ! is_singular() ) return $html;
    return '';
}

add_action('admin_init', 'gpa_disable_comments_admin');
function gpa_disable_comments_admin() {
    $post_types = get_post_types();
    foreach ( $post_types as $post_type ) {
        if ( post_type_supports( $post_type, 'comments' ) ) {
            remove_post_type_support( $post_type, 'comments' );
            remove_post_type_support( $post_type, 'trackbacks' );
        }
    }
}
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);
add_filter('comments_array', '__return_empty_array', 10, 2);
add_action('admin_menu', function() {
    remove_menu_page('edit-comments.php');
});
add_action('init', function() {
    if ( is_admin_bar_showing() ) {
        remove_action('admin_bar_menu', 'wp_admin_bar_comments_menu', 60);
    }
});

add_filter('generate_article_schema', '__return_false');
add_filter('post_class', 'gpa_remove_article_microdata_class', 20);
function gpa_remove_article_microdata_class($classes) {
    if ( gpa_is_database_page() ) {
        $classes = array_diff($classes, array('hentry'));
    }
    return $classes;
}


// ============================================================
// PART 5: SCHEMA (Rank Math JSON-LD)
// ============================================================

add_filter('rank_math/json_ld', 'gpa_remove_rankmath_article_homepage', 99, 2);
function gpa_remove_rankmath_article_homepage($data, $jsonld) {
    if (is_front_page() || is_page('gpa-calculator')) {
        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value['@type']) && $value['@type'] === 'Article') {
                unset($data[$key]);
            }
        }
        if (isset($data['richSnippet'])) {
            unset($data['richSnippet']);
        }
    }
    return $data;
}

add_filter('rank_math/json_ld', 'gpa_calc_page_schema', 99, 2);
function gpa_calc_page_schema($data, $jsonld) {
    if (!gpa_is_calculator_page()) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (is_array($value) && isset($value['@type'])) {
            $type = $value['@type'];
            if ($type === 'Article' || $type === 'BlogPosting') {
                unset($data[$key]);
            }
        }
    }

    foreach ($data as $key => $value) {
        if (is_array($value) && isset($value['@type']) && $value['@type'] === 'Person') {
            unset($data[$key]);
        }
    }

    if (isset($data['richSnippet'])) {
        unset($data['richSnippet']);
    }

    foreach ($data as $key => $value) {
        if (is_array($value) && isset($value['@type']) && $value['@type'] === 'SoftwareApplication') {
            unset($data[$key]);
        }
    }

    // A Rank Math FAQ block's FAQPage can arrive nested under subjectOf: lift it to a top-level node (one per page)
    // rather than dropping it, so pages with a Rank Math FAQ block keep their FAQ rich result.
    $gpa_faq_top = false;
    foreach ($data as $gpa_node) {
        if (is_array($gpa_node) && isset($gpa_node['@type']) && $gpa_node['@type'] === 'FAQPage') {
            $gpa_faq_top = true;
            break;
        }
    }
    foreach ($data as $key => &$value) {
        if (is_array($value) && isset($value['subjectOf'])) {
            if (is_array($value['subjectOf'])) {
                $subjects = $value['subjectOf'];
                if (isset($subjects['@type'])) {
                    if ($subjects['@type'] === 'FAQPage') {
                        if (!$gpa_faq_top && !empty($subjects['mainEntity'])) {
                            $data['FAQPage'] = $subjects;
                            $gpa_faq_top     = true;
                        }
                        unset($value['subjectOf']);
                    }
                } else {
                    $filtered = array_filter($subjects, function($s) use (&$data, &$gpa_faq_top) {
                        $is_faq = is_array($s) && isset($s['@type']) && $s['@type'] === 'FAQPage';
                        if ($is_faq && !$gpa_faq_top && !empty($s['mainEntity'])) {
                            $data['FAQPage'] = $s;
                            $gpa_faq_top     = true;
                        }
                        return !$is_faq;
                    });
                    if (empty($filtered)) {
                        unset($value['subjectOf']);
                    } else {
                        $value['subjectOf'] = array_values($filtered);
                    }
                }
            }
        }
    }
    unset($value);

    $gpa_has_app = false;
    foreach ( $data as $gpa_node ) {
        if ( is_array( $gpa_node ) && isset( $gpa_node['@type'] ) && 'WebApplication' === $gpa_node['@type'] ) {
            $gpa_has_app = true;
            break;
        }
    }
    if ( ! $gpa_has_app ) {
        $gpa_post = get_post();
        $gpa_url  = get_permalink();
        $gpa_desc = $gpa_post ? (string) get_post_meta( $gpa_post->ID, 'rank_math_description', true ) : '';
        if ( '' === $gpa_desc && $gpa_post && has_excerpt( $gpa_post ) ) {
            $gpa_desc = get_the_excerpt( $gpa_post );
        }

        $gpa_app = array(
            '@type'               => 'WebApplication',
            '@id'                 => $gpa_url . '#webapplication',
            'name'                => $gpa_post ? wp_strip_all_tags( $gpa_post->post_title ) : get_the_title(),
            'url'                 => $gpa_url,
            'applicationCategory' => 'EducationalApplication',
            'operatingSystem'     => 'Any',
            'browserRequirements' => 'Requires JavaScript',
            'offers'              => array(
                '@type'         => 'Offer',
                'price'         => '0',
                'priceCurrency' => 'USD',
            ),
            'mainEntityOfPage'    => array( '@id' => $gpa_url . '#webpage' ),
        );
        if ( '' !== $gpa_desc ) {
            $gpa_app['description'] = $gpa_desc;
        }

        $data['WebApplication'] = $gpa_app;
    }

    $rating = apply_filters('gpa_calc_aggregate_rating', array(
        'ratingValue' => get_option('gpa_calc_rating_value', ''),
        'ratingCount' => get_option('gpa_calc_rating_count', ''),
    ));
    if (
        ! empty($rating['ratingValue']) && is_numeric($rating['ratingValue'])
        && ! empty($rating['ratingCount']) && is_numeric($rating['ratingCount'])
        && (int) $rating['ratingCount'] > 0
    ) {
        foreach ($data as $rating_key => $rating_node) {
            if (is_array($rating_node) && isset($rating_node['@type']) && $rating_node['@type'] === 'WebApplication') {
                $data[$rating_key]['aggregateRating'] = array(
                    '@type'       => 'AggregateRating',
                    'ratingValue' => (string) $rating['ratingValue'],
                    'ratingCount' => (string) $rating['ratingCount'],
                    'bestRating'  => '5',
                    'worstRating' => '1',
                );
                break;
            }
        }
    }

    return $data;
}

// Content pages (GPA scale, guides): Rank Math nests a FAQ block's FAQPage under Article.subjectOf.
// Lift it to one top-level FAQPage like the calculator pages (a nested copy is dropped when one is already top-level). College pages remove theirs at priority 99, so they have none left here.
add_filter( 'rank_math/json_ld', 'gpa_lift_nested_faqpage', 115, 2 );
function gpa_lift_nested_faqpage( $data, $jsonld ) {
    if ( ! is_array( $data ) || is_front_page() || ! is_singular() ) {
        return $data;
    }
    $has_top = false;
    foreach ( $data as $node ) {
        if ( is_array( $node ) && isset( $node['@type'] ) && 'FAQPage' === $node['@type'] ) {
            $has_top = true;
            break;
        }
    }
    $faq = null;
    foreach ( $data as $key => $node ) {
        if ( ! is_array( $node ) || empty( $node['subjectOf'] ) || ! is_array( $node['subjectOf'] ) ) {
            continue;
        }
        $subjects = isset( $node['subjectOf']['@type'] ) ? array( $node['subjectOf'] ) : $node['subjectOf'];
        $keep     = array();
        foreach ( $subjects as $s ) {
            if ( is_array( $s ) && isset( $s['@type'] ) && 'FAQPage' === $s['@type'] ) {
                if ( null === $faq && ! empty( $s['mainEntity'] ) ) {
                    $faq = $s;
                }
                continue;
            }
            $keep[] = $s;
        }
        if ( count( $keep ) === count( $subjects ) ) {
            continue;
        }
        if ( $keep ) {
            $data[ $key ]['subjectOf'] = $keep;
        } else {
            unset( $data[ $key ]['subjectOf'] );
        }
    }
    if ( ! $has_top && null !== $faq ) {
        $data['FAQPage'] = $faq;
    }
    return $data;
}

add_filter('rank_math/opengraph/type', 'gpa_calc_og_type');
function gpa_calc_og_type($type) {
    if (gpa_is_calculator_page()) {
        return 'website';
    }
    return $type;
}

add_action('wp_head', 'gpa_calc_remove_article_meta', 1);
function gpa_calc_remove_article_meta() {
    if (gpa_is_calculator_page()) {
        add_filter('rank_math/opengraph/facebook/article:published_time', '__return_false');
        add_filter('rank_math/opengraph/facebook/article:modified_time', '__return_false');
    }
}

// College profile data from the federal import (Admissions Phase 2, checkpoint E) and the shared FAQ. Loaded only
// when present, and every caller below checks for its functions, so deploying functions.php alone can't break the site.
if ( is_readable( get_stylesheet_directory() . '/college-data.php' ) ) {
    require_once get_stylesheet_directory() . '/college-data.php';
}

add_filter('rank_math/json_ld', 'gpa_college_page_schema', 99, 2);
function gpa_college_page_schema($data, $jsonld) {
    if ( ! is_singular('colleges') ) {
        return $data;
    }

    $post_id    = get_the_ID();
    $college    = get_the_title($post_id);
    $page_url   = get_permalink($post_id);
    $location   = get_field('location', $post_id);
    $avg_gpa    = get_field('average_gpa', $post_id);
    $avg_sat    = get_field('average_sat_score', $post_id);
    $sat_range  = get_field('sat_range', $post_id);
    $act_range  = get_field('act_range', $post_id);
    $acceptance = get_field('acceptance_rate', $post_id);
    // Pages with the federal import describe themselves from it; no unsourced GPA or estimated SAT average
    $fresh      = function_exists( 'gpa_college_fresh' ) ? gpa_college_fresh( $post_id ) : null;
    if ( $fresh ) {
        $avg_gpa = $avg_sat = '';
    }

    foreach ($data as $key => $value) {
        if ( is_array($value) && isset($value['@type']) ) {
            $type = $value['@type'];
            if ( in_array($type, array('Article', 'BlogPosting', 'Person'), true) ) {
                unset($data[$key]);
            }
        }
    }

    if ( isset($data['richSnippet']) ) {
        unset($data['richSnippet']);
    }

    foreach ($data as $key => &$entity) {
        if ( is_array($entity) && isset($entity['subjectOf']) ) {
            if ( is_array($entity['subjectOf']) ) {
                $subjects = $entity['subjectOf'];
                if ( isset($subjects['@type']) ) {
                    if ( $subjects['@type'] === 'FAQPage' ) {
                        unset($entity['subjectOf']);
                    }
                } else {
                    $filtered = array_filter($subjects, function($s) {
                        return !( is_array($s) && isset($s['@type']) && $s['@type'] === 'FAQPage' );
                    });
                    if ( empty($filtered) ) {
                        unset($entity['subjectOf']);
                    } else {
                        $entity['subjectOf'] = array_values($filtered);
                    }
                }
            }
        }
    }
    unset($entity);

    // Admissions Phase 4: the page (a WebPage, and the FAQPage when it has questions) is about the college; the site's
    // WebSite and Organization as on every other page. No "Admissions" EducationalOccupationalProgram (that type is a
    // course of study), and the college's url isn't this page's.
    foreach ($data as $key => $value) {
        if ( is_array($value) && isset($value['@type']) ) {
            $types = (array) $value['@type'];
            if ( array_intersect( $types, array( 'CollegeOrUniversity', 'EducationalOccupationalProgram', 'WebPage', 'FAQPage' ) ) ) {
                unset($data[$key]);
            }
        }
    }

    $college_schema = array(
        '@type' => 'CollegeOrUniversity',
        '@id'   => $page_url . '#college',
        'name'  => $college,
    );
    // The name the page gives as "formerly ..." (Phase 3 names)
    $former = trim( (string) get_post_meta( $post_id, 'former_name', true ) );
    if ( '' !== $former && $former !== $college ) {
        $college_schema['alternateName'] = $former;
    }
    // The college's own website from IPEDS (Phase 4); our page is the WebPage node about it
    $website = function_exists( 'gpa_college_website' ) ? gpa_college_website( $post_id ) : '';
    if ( '' !== $website ) {
        $college_schema['url'] = $website;
    }
    $college_schema['mainEntityOfPage'] = array( '@id' => $page_url . '#webpage' );

    if ( $location ) {
        $parts = array_map('trim', explode(',', $location));
        $address = array( '@type' => 'PostalAddress' );
        if ( isset($parts[0]) ) {
            $address['addressLocality'] = $parts[0];
        }
        if ( isset($parts[1]) ) {
            $address['addressRegion'] = $parts[1];
        }
        $address['addressCountry'] = 'US';
        $college_schema['address'] = $address;
    }
    // Template v2 (college-v2.php): the full IPEDS address
    if ( function_exists( 'gpa_college_v2' ) && gpa_college_v2( $post_id ) && ( $v2_address = gpa_college_schema_address( $post_id ) ) ) {
        $college_schema['address'] = $v2_address;
    }

    $loc_clean = trim( (string) $location );
    if ( $fresh && $loc_clean !== '' ) {
        $type        = gpa_college_type_phrase( $post_id );
        $description = $college . ' is ' . ( preg_match( '/^[aeiou]/i', $type ) ? 'an ' : 'a ' ) . $type . ' in ' . $loc_clean . '.';
        if ( null !== $fresh['rate'] && '' !== $fresh['fall'] ) {
            $description .= ' Its acceptance rate for ' . $fresh['fall'] . ' was ' . gpa_college_pct_txt( $fresh['rate'] ) . '.';
        } elseif ( $fresh['open'] ) {
            $description .= ' It has an open admission policy.';
        }
        $college_schema['description'] = $description;
    } elseif ( $loc_clean !== '' && strcasecmp($loc_clean, 'N/A') !== 0 ) {
        $description = $college . ' is located in ' . $loc_clean . '.';

        $acc_clean = trim( (string) $acceptance );
        if ( $acc_clean !== '' && strcasecmp($acc_clean, 'N/A') !== 0 ) {
            $description .= ' The acceptance rate is ' . $acc_clean . '.';
        }

        $gpa_clean = trim( (string) $avg_gpa );
        if ( $gpa_clean !== '' && strcasecmp($gpa_clean, 'N/A') !== 0 ) {
            if ( is_numeric($gpa_clean) && strpos($gpa_clean, '.') === false ) {
                $gpa_clean .= '.0';
            }
            $description .= ' The average GPA is ' . $gpa_clean . '.';
        }

        $sat_clean = trim( (string) $avg_sat );
        if ( $sat_clean !== '' && strcasecmp($sat_clean, 'N/A') !== 0 ) {
            $description .= ' The average SAT score is ' . $sat_clean . '.';
        }

        $college_schema['description'] = $description;
    }

    if ( has_post_thumbnail($post_id) ) {
        $college_schema['image'] = get_the_post_thumbnail_url($post_id, 'full');
    } elseif ( ! $fresh ) {
        $img_url = get_field('img_url', $post_id);
        if ( ! empty($img_url) ) {
            $college_schema['image'] = $img_url;
        }
    }

    $enrollment = $fresh ? '' : get_field('enrollment', $post_id); // the import holds undergraduates only, not every student
    if ( $enrollment ) {
        $enrollment_clean = (int) preg_replace('/[^0-9]/', '', (string) $enrollment);
        if ( $enrollment_clean > 0 ) {
            $college_schema['numberOfStudents'] = $enrollment_clean;
        }
    }

    $data['CollegeOrUniversity'] = $college_schema;

    // The same questions and answers as the page's FAQ section (college-data.php), as plain text
    $faq_items = array();
    foreach ( ( function_exists( 'gpa_college_faqs' ) ? gpa_college_faqs( $post_id ) : array() ) as $faq ) {
        $faq_items[] = array(
            '@type' => 'Question',
            'name'  => $faq['question'],
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => gpa_college_faq_text( $faq['answer'] ),
            ),
        );
    }

    // The page: its description comes from the meta description (gpa_schema_final_walk())
    $site      = untrailingslashit( home_url() );
    $page_node = array(
        '@type'         => $faq_items ? array( 'WebPage', 'FAQPage' ) : 'WebPage',
        '@id'           => $page_url . '#webpage',
        'url'           => $page_url,
        'name'          => function_exists( 'gpa_college_seo_build_title' ) ? gpa_college_seo_build_title( $post_id ) : $college,
        'isPartOf'      => array( '@id' => $site . '/#website' ),
        'about'         => array( '@id' => $page_url . '#college' ),
        'breadcrumb'    => array( '@id' => $page_url . '#breadcrumb' ),
        'datePublished' => get_post_time( 'c', true, $post_id ),
        'dateModified'  => get_post_modified_time( 'c', true, $post_id ),
        'inLanguage'    => get_bloginfo( 'language' ),
    );
    if ( $faq_items ) {
        $page_node['mainEntity'] = $faq_items;
    }
    $data['WebPage'] = $page_node;

    return function_exists( 'gpa_college_schema_site' ) ? gpa_college_schema_site( $data ) : $data;
}

add_filter('rank_math/json_ld', 'gpa_college_archive_schema', 99, 2);
function gpa_college_archive_schema($data, $jsonld) {
    $is_archive = is_post_type_archive('colleges');
    if ( ! $is_archive ) {
        return $data;
    }

    $remove_types = array('SoftwareApplication', 'Article', 'BlogPosting', 'Person');
    foreach ($data as $key => $value) {
        if ( is_array($value) && isset($value['@type']) ) {
            if ( in_array($value['@type'], $remove_types, true) ) {
                unset($data[$key]);
            }
        }
    }

    if ( isset($data['richSnippet']) ) {
        unset($data['richSnippet']);
    }

    foreach ($data as $key => &$entity) {
        if ( is_array($entity) && isset($entity['subjectOf']) ) {
            if ( is_array($entity['subjectOf']) ) {
                $subjects = $entity['subjectOf'];
                if ( isset($subjects['@type']) && $subjects['@type'] === 'FAQPage' ) {
                    $data['FAQPage'] = $subjects;
                    unset($entity['subjectOf']);
                } elseif ( ! isset($subjects['@type']) ) {
                    $faq_found = null;
                    $filtered = array();
                    foreach ( $subjects as $s ) {
                        if ( is_array($s) && isset($s['@type']) && $s['@type'] === 'FAQPage' ) {
                            $faq_found = $s;
                        } else {
                            $filtered[] = $s;
                        }
                    }
                    if ( $faq_found ) {
                        $data['FAQPage'] = $faq_found;
                    }
                    if ( empty($filtered) ) {
                        unset($entity['subjectOf']);
                    } else {
                        $entity['subjectOf'] = array_values($filtered);
                    }
                }
            }
        }
    }
    unset($entity);

    $archive_url = get_post_type_archive_link( 'colleges' );
    if ( ! $archive_url ) {
        $archive_url = home_url( '/admissions/' );
    }
    $per_page    = function_exists( 'gpa_college_hub_per_page' ) ? gpa_college_hub_per_page() : 30;
    $paged       = max(1, (int) get_query_var('paged'));
    $page_url    = $paged > 1 ? trailingslashit($archive_url) . 'page/' . $paged . '/' : $archive_url;

    $college_ids = get_posts(array(
        'post_type'      => 'colleges',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
        'post_status'    => 'publish',
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
        'fields'         => 'ids',
    ));

    $item_list_elements = array();
    $position = ( $paged - 1 ) * $per_page + 1;
    foreach ( $college_ids as $college_id ) {
        $item_list_elements[] = array(
            '@type'    => 'ListItem',
            'position' => $position,
            'url'      => get_permalink( $college_id ),
            'name'     => get_the_title( $college_id ),
        );
        $position++;
    }

    // Admissions Phase 4: one page node, Rank Math's CollectionPage (#webpage), with the description and this page's
    // colleges as its main entity; numberOfItems counts the whole list, of which this page shows its 30.
    $page_key = null;
    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) && isset( $value['@type'] ) && array_intersect( (array) $value['@type'], array( 'CollectionPage', 'WebPage' ) ) ) {
            $page_key = $key;
            break;
        }
    }
    if ( null === $page_key ) {
        $page_key          = 'CollectionPage';
        $data[ $page_key ] = array(
            '@type'      => 'CollectionPage',
            '@id'        => $page_url . '#webpage',
            'url'        => $page_url,
            'name'       => function_exists( 'gpa_college_hub_title' ) ? gpa_college_hub_title() : 'US College Admissions Database',
            'isPartOf'   => array( '@id' => untrailingslashit( home_url() ) . '/#website' ),
            'inLanguage' => get_bloginfo( 'language' ),
        );
    }
    $data[ $page_key ]['description'] = function_exists( 'gpa_college_hub_description' ) ? gpa_college_hub_description() : 'Browse admission requirements, acceptance rates and SAT and ACT score ranges for US colleges and universities.';
    $data[ $page_key ]['breadcrumb']  = array( '@id' => $archive_url . '#breadcrumb' );

    if ( ! empty( $item_list_elements ) ) {
        $data['ItemList'] = array(
            '@type'           => 'ItemList',
            '@id'             => $page_url . '#itemlist',
            'url'             => $page_url,
            'numberOfItems'   => (int) wp_count_posts( 'colleges' )->publish,
            'itemListElement' => $item_list_elements,
        );
        $data[ $page_key ]['mainEntity'] = array( '@id' => $page_url . '#itemlist' );
    }

    return function_exists( 'gpa_college_schema_site' ) ? gpa_college_schema_site( $data ) : $data;
}

add_filter('rank_math/opengraph/type', 'gpa_college_og_type');
function gpa_college_og_type($type) {
    if ( is_singular('colleges') || is_post_type_archive('colleges') ) {
        return 'website';
    }
    return $type;
}

add_action('wp_head', 'gpa_college_remove_article_meta', 1);
function gpa_college_remove_article_meta() {
    if ( is_singular('colleges') || is_post_type_archive('colleges') ) {
        add_filter('rank_math/opengraph/facebook/article:published_time', '__return_false');
        add_filter('rank_math/opengraph/facebook/article:modified_time', '__return_false');
    }
}

if ( ! function_exists( 'gpa_admission_filter_active' ) ) {
    function gpa_admission_filter_active() {
        if ( ! is_post_type_archive( 'colleges' ) ) {
            return false;
        }
        $filter_params = array( 'search', 'filter', 'ownership', 'acceptance', 'gpa', 'sat', 'sort', 'state' );
        foreach ( $filter_params as $p ) {
            if ( isset( $_GET[ $p ] ) && '' !== $_GET[ $p ] ) {
                return true;
            }
        }
        return false;
    }
}

add_filter( 'rank_math/frontend/robots', 'gpa_admission_filter_robots' );
function gpa_admission_filter_robots( $robots ) {
    if ( gpa_admission_filter_active() ) {
        $robots['index']  = 'noindex';
        $robots['follow'] = 'follow';
    }
    return $robots;
}

add_filter( 'wp_robots', 'gpa_admission_filter_wp_robots' );
function gpa_admission_filter_wp_robots( $robots ) {
    if ( gpa_admission_filter_active() ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
        unset( $robots['index'] );
    }
    return $robots;
}

add_action( 'wp_head', 'gpa_admission_filter_force_canonical', 99 );
function gpa_admission_filter_force_canonical() {
    if ( ! gpa_admission_filter_active() ) {
        return;
    }
    $url = get_post_type_archive_link( 'colleges' );
    if ( ! $url ) {
        $url = home_url( '/admissions/' );
    }
    echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
}

if ( ! function_exists( 'gpa_college_acc_pct' ) ) {
    // Acceptance rate as text ("43%", "3.6%": one decimal under 10%), or '' when missing, 0 or 100% (open admission /
    // not reported).
    function gpa_college_acc_pct( $post_id ) {
        $raw = trim( (string) get_field( 'acceptance_rate', $post_id ) );
        $acc = (float) preg_replace( '/[^0-9.]/', '', $raw );
        if ( $acc > 0 && $acc <= 1 && false === strpos( $raw, '%' ) ) {
            $acc *= 100; // stored as a fraction, e.g. 0.81
        }
        if ( ! ( $acc > 0 && $acc < 100 ) ) {
            return '';
        }
        return function_exists( 'gpa_college_pct_txt' ) ? gpa_college_pct_txt( $acc ) : round( $acc ) . '%';
    }
}
if ( ! function_exists( 'gpa_college_gpa_txt' ) ) {
    // Average GPA with up to two decimals (3.95 stays 3.95), or '' when missing.
    function gpa_college_gpa_txt( $post_id ) {
        $num = (float) get_field( 'average_gpa', $post_id );
        if ( $num <= 0 ) {
            return '';
        }
        $s = number_format( $num, 2 );
        return ( '0' === substr( $s, -1 ) ) ? substr( $s, 0, -1 ) : $s;    }
}
if ( ! function_exists( 'gpa_college_cds_gpa' ) ) {
    // The average high school GPA the college reported on its own Common Data Set (C12), imported by
    // scripts/admissions/phase2_b2_live.sh, or null without a value, its year and its source. Pages show a GPA
    // only from these fields, always labeled as reported by the college with its year (and basis when known).
    function gpa_college_cds_gpa( $post_id ) {
        $value = trim( (string) get_post_meta( $post_id, 'cds_gpa', true ) );
        $year  = trim( (string) get_post_meta( $post_id, 'cds_gpa_year', true ) );
        $url   = trim( (string) get_post_meta( $post_id, 'cds_gpa_source_url', true ) );
        if ( ! is_numeric( $value ) || (float) $value <= 0 || '' === $year || '' === $url ) {
            return null;
        }
        $pct   = trim( (string) get_post_meta( $post_id, 'cds_gpa_submit_pct', true ) );
        $basis = trim( (string) get_post_meta( $post_id, 'cds_gpa_basis', true ) );
        return array(
            'value'  => $value,
            'year'   => str_replace( '-', '–', $year ),
            'submit' => ( is_numeric( $pct ) && (float) $pct > 0 ) ? round( (float) $pct ) . '%' : '',
            'basis'  => in_array( $basis, array( 'weighted', 'unweighted' ), true ) ? $basis : '',
            'url'    => $url,
        );
    }
}
if ( ! function_exists( 'gpa_college_cds_gpa_answer' ) ) {
    // The sentence that states a reported GPA: who reported it, where, for whom, and why it isn't a target.
    function gpa_college_cds_gpa_answer( $college, array $g ) {
        $basis = 'weighted' === $g['basis'] ? ' This is a weighted average (it is above 4.0).' : ( 'unweighted' === $g['basis'] ? ' This is an unweighted average.' : '' );
        return 'The average high school GPA of ' . $college . '\'s first-year students who submitted one'
            . ( '' !== $g['submit'] ? ' (' . $g['submit'] . ' did)' : '' )
            . ' is ' . $g['value'] . ', as reported by the college in its ' . $g['year'] . ' Common Data Set.' . $basis
            . ' Colleges calculate GPA in different ways, so this average can\'t be compared directly with your own GPA.';
    }
}
if ( ! function_exists( 'gpa_college_range_txt' ) ) {
    function gpa_college_range_txt( $value ) {
        $v = trim( (string) $value );
        if ( '' === $v || in_array( strtolower( $v ), array( '-', '–', 'n/a', 'not reported', 'unavailable' ), true ) ) {
            return '';
        }
        return str_replace( '-', '–', $v );
    }
}

if ( ! function_exists( 'gpa_college_seo_build_title' ) ) {
    // Builds a title from the college's own data, trying formats from longest to shortest and
    // using the first that fits in ~60 characters (what Google shows before cutting titles off).
    function gpa_college_seo_build_title( $post_id = null ) {
        $post_id = $post_id ?: get_the_ID();
        $name    = get_the_title( $post_id );
        $gpa     = gpa_college_gpa_txt( $post_id );
        $acc     = gpa_college_acc_pct( $post_id );
        $cds     = function_exists( 'gpa_college_cds_gpa' ) ? gpa_college_cds_gpa( $post_id ) : null;
        $fresh   = function_exists( 'gpa_college_fresh' ) ? gpa_college_fresh( $post_id ) : null;
        $tests   = $fresh ? implode( '/', array_keys( array_filter( array( 'SAT' => $fresh['sat'], 'ACT' => $fresh['act'] ) ) ) ) : '';

        if ( $cds ) {
            // The college's own Common Data Set GPA: the page gives it with its year and source; the title names it only
            $options = '' !== $acc ? array(
                $name . ' Average GPA & Acceptance Rate (' . $acc . ')',
                $name . ': Average GPA & ' . $acc . ' Acceptance Rate',
                $name . ': Average GPA & ' . $acc . ' Acceptance',
            ) : array(
                $name . ' Average GPA' . ( '' !== $tests ? ' & ' . $tests . ' Scores' : ' & Admissions' ),
                $name . ' Average GPA & Admissions',
            );
        } elseif ( '' !== $gpa && '' !== $acc ) {
            $options = array(
                $name . ' GPA Requirements (' . $gpa . ' Avg) & ' . $acc . ' Acceptance Rate',
                $name . ' GPA Requirements: ' . $gpa . ' Avg, ' . $acc . ' Acceptance',
                $name . ': ' . $gpa . ' Avg GPA, ' . $acc . ' Acceptance Rate',
                $name . ': ' . $gpa . ' GPA, ' . $acc . ' Acceptance',
            );
        } elseif ( '' !== $gpa ) {
            $options = array(
                $name . ' GPA Requirements: ' . $gpa . ' Average GPA',
                $name . ' GPA Requirements (' . $gpa . ' Avg)',
                $name . ': ' . $gpa . ' Average GPA',
            );
        } elseif ( '' !== $acc ) {
            $options = array(
                $name . ' Acceptance Rate (' . $acc . ') & Admissions',
                $name . ' Acceptance Rate: ' . $acc,
            );
            if ( '' !== $tests ) {
                array_unshift( $options, $name . ' Acceptance Rate (' . $acc . ') & ' . $tests . ' Scores' );
            }
        } elseif ( $fresh && $fresh['open'] ) {
            $options = array(
                $name . ' Admission Requirements & Open Admission',
                $name . ' Admission Requirements',
            );
        } elseif ( $fresh && $fresh['requirements'] ) {
            // Admission factors but no acceptance rate (colleges that admit few or no first-year students)
            $options = array( $name . ' Admission Requirements' );
        } else {
            // No admissions figures on the page: the title promises nothing it doesn't have
            $options = array( $name . ' Admissions' );
        }
		        // Very long college names: fall back to shorter formats so the title still fits
        if ( '' !== $gpa ) { $options[] = $name . ': ' . $gpa . ' GPA'; }
        if ( $cds ) { $options[] = $name . ' Average GPA'; }
        if ( '' !== $acc ) { $options[] = $name . ': ' . $acc . ' Acceptance'; }
        $options[] = $name . ' Admissions';
        $len = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen';
        foreach ( $options as $title ) {
            if ( $len( $title ) <= 60 ) {
                return $title;
            }
        }
        return end( $options );
    }
}
if ( ! function_exists( 'gpa_college_seo_build_description' ) ) {
    function gpa_college_seo_build_description( $post_id = null ) {
        $post_id = $post_id ?: get_the_ID();
        $name    = get_the_title( $post_id );
        // "University of Georgia" reads better as "The University of Georgia" in a sentence
        $needs_the = (bool) preg_match( '/^(University|College) of /', $name );
        $subject   = $needs_the ? 'The ' . $name : $name; // start of a sentence
        $object    = $needs_the ? 'the ' . $name : $name; // middle of a sentence
        $gpa     = gpa_college_gpa_txt( $post_id );
        $acc_txt = gpa_college_acc_pct( $post_id ); // e.g. "43%" or "3.6%", or '' when missing / 100%
        $acc     = (float) $acc_txt;
        $fresh   = function_exists( 'gpa_college_fresh' ) ? gpa_college_fresh( $post_id ) : null;
        $cds     = function_exists( 'gpa_college_cds_gpa' ) ? gpa_college_cds_gpa( $post_id ) : null;
        $sat     = gpa_college_range_txt( get_field( 'sat_range', $post_id ) );
        $act     = gpa_college_range_txt( get_field( 'act_range', $post_id ) );
        $test    = '' !== $sat ? $sat . ' on the SAT' : ( '' !== $act ? $act . ' on the ACT' : '' );
        $tests   = array(); // pages with the federal import: what the middle 50% of entrants scored, most-used test first
        if ( $fresh && '' !== $fresh['fall'] ) {
            $erw  = isset( $fresh['sat']['Reading and Writing'] ) ? gpa_college_range( $fresh['sat']['Reading and Writing'] ) : '';
            $math = isset( $fresh['sat']['Math'] ) ? gpa_college_range( $fresh['sat']['Math'] ) : '';
            $comp = isset( $fresh['act']['Composite'] ) ? gpa_college_range( $fresh['act']['Composite'] ) : '';
            if ( '' !== $erw && '' !== $math ) {
                $tests['sat'] = 'Middle 50% SAT: ' . $erw . ' reading and writing, ' . $math . ' math.';
            }
            if ( '' !== $comp ) {
                $tests['act'] = 'The middle 50% of first-year students scored ' . $comp . ' on the ACT.';
            }
            if ( (float) $fresh['act_submit'] > (float) $fresh['sat_submit'] ) {
                $tests = array_reverse( $tests );
            }
            $test = $tests ? 'x' : ''; // counts as admissions data below
        }

        // School facts
        $type    = function_exists( 'gpa_college_type_phrase' ) ? gpa_college_type_phrase( $post_id ) : 'college';
        $article = in_array( $type[0], array( 'a', 'e', 'i', 'o', 'u' ), true ) ? 'an' : 'a';
        $loc     = trim( (string) get_field( 'location', $post_id ) );
        $loc     = ( '' !== $loc && 'n/a' !== strtolower( $loc ) ) ? $loc : '';
        $enr     = (int) preg_replace( '/[^0-9]/', '', (string) get_field( 'enrollment', $post_id ) );
        $price   = (int) preg_replace( '/[^0-9]/', '', (string) get_field( 'net_price', $post_id ) );

        // Every description ends with a call to action. Reserve room for the shortest one, and
        // trim the least important detail first if the sentences would otherwise leave no room.
        // Pages with a college-published GPA always keep the GPA call to action: most searches are about GPA.
        $len            = function_exists( 'mb_strlen' ) ? 'mb_strlen' : 'strlen'; // ranges use en dashes
        $has_admissions = ( $acc > 0 || '' !== $gpa || '' !== $test );
        $short_cta      = $cds ? 'See its average GPA.' : ( $has_admissions ? 'See the requirements.' : 'See admission requirements.' );
        $thin_ctas      = array();
        if ( ! $cds && ! $has_admissions ) {
            // Pages without admissions figures name only what they have: admission factors, credit policies, net
            // price (all of it when it fits); pages still under review have none of those, and say so
            $what = array();
            if ( $fresh && $fresh['requirements'] ) {
                $what[] = 'admission requirements';
            }
            if ( $fresh && ( in_array( $fresh['ap'], array( 'Yes', 'No' ), true ) || in_array( $fresh['life'], array( 'Yes', 'No' ), true ) ) ) {
                $what[] = 'credit policies';
            }
            if ( $fresh && $fresh['net_price'] && '' !== $fresh['net_price_year'] ) {
                $what[] = 'average net price';
            }
            if ( count( $what ) > 1 ) {
                $thin_ctas[] = 'See its ' . implode( ', ', array_slice( $what, 0, -1 ) ) . ' and ' . end( $what ) . '.';
                $thin_ctas[] = 'See its ' . $what[0] . ' and more.';
            } elseif ( $what ) {
                $thin_ctas[] = 'See its ' . $what[0] . '.';
            } else {
                $thin_ctas[] = $fresh ? 'See its admissions details.' : 'Its admissions figures are under review.';
            }
            $short_cta = end( $thin_ctas );
        }
        $limit          = 160 - $len( $short_cta ) - 1;

        $sentences = array();

        // How selective the school is
        if ( $acc > 0 && $fresh && '' !== $fresh['fall'] ) {
            $for = ' for ' . $fresh['fall'];
            if ( $acc < 10 ) {
                $sentences[] = $subject . ' admitted just ' . $acc_txt . ' of applicants' . $for . '.';
            } elseif ( $acc < 25 ) {
                $sentences[] = $subject . ' is highly selective: it admitted ' . $acc_txt . ' of applicants' . $for . '.';
            } elseif ( $acc < 50 ) {
                $sentences[] = $subject . ' admitted ' . $acc_txt . ' of applicants' . $for . '.';
            } elseif ( $acc < 75 ) {
                $sentences[] = $subject . ' admitted more than half of applicants' . $for . ' (' . $acc_txt . ').';
            } else {
                $sentences[] = $subject . ' admitted most applicants' . $for . ' (' . $acc_txt . ').';
            }
        } elseif ( $acc > 0 ) {
            if ( $acc < 10 ) {
                $sentences[] = $subject . ' admits just ' . $acc_txt . ' of applicants.';
            } elseif ( $acc < 25 ) {
                $sentences[] = $subject . ' is highly selective, admitting ' . $acc_txt . ' of applicants.';
            } elseif ( $acc < 50 ) {
                $sentences[] = $subject . ' accepts ' . $acc_txt . ' of applicants.';
            } elseif ( $acc < 75 ) {
                $sentences[] = $subject . ' accepts more than half of applicants (' . $acc_txt . ').';
            } else {
                $sentences[] = $subject . ' accepts most applicants (' . $acc_txt . ').';
            }
        } elseif ( $fresh && $fresh['open'] ) {
            $sentences[] = $subject . ' has an open admission policy.';
        }

        // What admitted students look like (drop the test-score clause if space is tight)
        $who    = $acc > 0 ? 'Admitted students' : 'Admitted students at ' . $object;
        $s2     = array();
        if ( $tests ) {
            $s2 = array_values( $tests );
        } elseif ( '' !== $gpa ) {
            if ( '' !== $test ) {
                $s2[] = $who . ' average a ' . $gpa . ' GPA and typically score ' . $test . '.';
            }
            $s2[] = $who . ' average a ' . $gpa . ' GPA.';
        } elseif ( '' !== $test ) {
            $s2[] = $who . ' typically score ' . $test . '.';
        }
        if ( $s2 ) {
            $head = implode( ' ', $sentences );
            $pick = $tests ? '' : end( $s2 ); // the imported test sentences are left out when none fits
            foreach ( $s2 as $candidate ) {
                if ( $len( trim( $head . ' ' . $candidate ) ) <= $limit ) {
                    $pick = $candidate;
                    break;
                }
            }
            if ( '' !== $pick ) {
                $sentences[] = $pick;
            }
        }

        // Fill in with school facts when admissions data is thin. Try the fullest wording first and
        // drop the least important detail (net price, enrollment, location) until it fits.
        if ( count( $sentences ) < 2 ) {
            $students   = $fresh ? ' undergraduates' : ' students'; // the federal import counts undergraduates
            $where      = $loc ? ' in ' . $loc : '';
            $candidates = array();
            if ( $sentences ) {
                if ( $enr > 0 ) {
                    $candidates[] = "It's " . $article . ' ' . $type . $where . ( $loc ? ', with ' : ' with ' ) . number_format( $enr ) . $students . '.';
                }
                $candidates[] = "It's " . $article . ' ' . $type . $where . '.';
                $candidates[] = "It's " . $article . ' ' . $type . '.';
            } else {
                $base = $subject . ' is ' . $article . ' ' . $type . $where;
                if ( $enr > 0 && $price > 0 ) {
                    $candidates[] = $base . ', with ' . number_format( $enr ) . $students . ' and an average net price of $' . number_format( $price ) . '.';
                }
                if ( $enr > 0 ) {
                    $candidates[] = $base . ', with ' . number_format( $enr ) . $students . '.';
                } elseif ( $price > 0 ) {
                    $candidates[] = $base . ', with an average net price of $' . number_format( $price ) . '.';
                }
                $candidates[] = $base . '.';
                if ( $where ) {
                    $candidates[] = $subject . ' is ' . $article . ' ' . $type . '.';
                }
            }
            $head = implode( ' ', $sentences );
            $pick = ( $fresh && $sentences ) ? '' : end( $candidates ); // a second sentence only when it fits
            foreach ( $candidates as $candidate ) {
                if ( $len( trim( $head . ' ' . $candidate ) ) <= $limit ) {
                    $pick = $candidate;
                    break;
                }
            }
            if ( '' !== $pick ) {
                $sentences[] = $pick;
            }
        }

        // Call to action: rotate the wording across pages, using the first version that fits (pages without
        // admissions figures: the fullest list of what they have that fits)
        $ctas = $has_admissions
            ? array( 'See what it takes to get in.', 'See the full admission requirements.', "Here's what it takes to get in." )
            : $thin_ctas;
        if ( $cds ) {
            $ctas = array( 'See its average GPA and what it takes to get in.', 'See its average GPA and full requirements.' );
        }
        $body  = implode( ' ', $sentences );
        $cta   = $short_cta;
        $n     = count( $ctas );
        $start = $thin_ctas ? 0 : $post_id;
        for ( $i = 0; $i < $n; $i++ ) {
            $option = $ctas[ ( $start + $i ) % $n ];
            if ( $len( $body . ' ' . $option ) <= 160 ) {
                $cta = $option;
                break;
            }
        }
        return $body . ' ' . $cta;
    }
}

add_filter( 'rank_math/title', 'gpa_college_seo_title', 20 );
function gpa_college_seo_title( $title ) {
    if ( ! is_singular( 'colleges' ) ) return $title;
    $post_id = get_the_ID();
    $custom = get_post_meta( $post_id, 'rank_math_title', true );
    if ( ! empty( $custom ) ) return $title;
    return gpa_college_seo_build_title( $post_id );
}
add_filter( 'rank_math/description', 'gpa_college_seo_description', 20 );
function gpa_college_seo_description( $description ) {
    if ( ! is_singular( 'colleges' ) ) return $description;
    $post_id = get_the_ID();
    $custom = get_post_meta( $post_id, 'rank_math_description', true );
    if ( ! empty( $custom ) ) return $description;
    return gpa_college_seo_build_description( $post_id );
}

add_filter( 'rank_math/opengraph/facebook/og_title', 'gpa_college_og_title', 20 );
add_filter( 'rank_math/opengraph/twitter/twitter_title', 'gpa_college_og_title', 20 );
function gpa_college_og_title( $value ) {
    if ( ! is_singular( 'colleges' ) ) return $value;
    return gpa_college_seo_build_title();
}
add_filter( 'rank_math/opengraph/facebook/og_description', 'gpa_college_og_description', 20 );
add_filter( 'rank_math/opengraph/twitter/twitter_description', 'gpa_college_og_description', 20 );
function gpa_college_og_description( $value ) {
    if ( ! is_singular( 'colleges' ) ) return $value;
    return gpa_college_seo_build_description();
}

add_filter( 'pre_get_document_title', 'gpa_college_document_title', 20 );
function gpa_college_document_title( $title ) {
    if ( ! is_singular( 'colleges' ) ) return $title;
    return gpa_college_seo_build_title();
}

add_action( 'wp_head', 'gpa_college_meta_description', 1 );
function gpa_college_meta_description() {
    if ( ! is_singular( 'colleges' ) ) return;
    $desc = gpa_college_seo_build_description();
    if ( ! $desc ) return;
    echo "\n" . '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
}

if ( ! function_exists( 'gpa_render_breadcrumb' ) ) {
    function gpa_render_breadcrumb() {
        $home_url      = home_url( '/' );
        $admission_url = get_post_type_archive_link( 'colleges' );
        if ( ! $admission_url ) {
            $admission_url = home_url( '/admissions/' );
        }

        $trail = array( array( 'Home', $home_url ) );

        if ( is_singular( 'colleges' ) ) {
            $trail[] = array( 'College Admissions', $admission_url );
            $trail[] = array( get_the_title(), null );
        } elseif ( is_post_type_archive( 'colleges' ) ) {
            $trail[] = array( 'College Admissions', null );
        } elseif ( is_singular() && ! is_front_page() ) {
            // Same trail as the BreadcrumbList schema: Home > parent pages > this page.
            $post_id = get_queried_object_id();
            foreach ( gpa_breadcrumb_ancestors( $post_id ) as $ancestor_id ) {
                $trail[] = array( gpa_breadcrumb_title( $ancestor_id ), get_permalink( $ancestor_id ) );
            }
            $trail[] = array( gpa_breadcrumb_title( $post_id ), null );
        } else {
            return;
        }

        $last = count( $trail ) - 1;
        echo '<nav class="gpa-breadcrumb" aria-label="Breadcrumb"><ol class="gpa-breadcrumb__list">';
        foreach ( $trail as $i => $crumb ) {
            list( $label, $url ) = $crumb;
            echo '<li class="gpa-breadcrumb__item">';
            if ( $url && $i !== $last ) {
                echo '<a class="gpa-breadcrumb__link" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
            } else {
                echo '<span class="gpa-breadcrumb__current" aria-current="page">' . esc_html( $label ) . '</span>';
            }
            echo '</li>';
        }
        echo '</ol></nav>';
    }
}

/** Published parent pages of a page, top level first. */
function gpa_breadcrumb_ancestors( $post_id ) {
    $ids = array();
    foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor_id ) {
        if ( 'publish' === get_post_status( $ancestor_id ) ) {
            $ids[] = $ancestor_id;
        }
    }
    return $ids;
}

function gpa_breadcrumb_title( $post_id ) {
    return wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES );
}

/**
 * Design overhaul phase 4: the breadcrumb sits in the hero, above the H1, on
 * calculator, content and blog post pages. College pages print their own.
 */
add_action( 'generate_before_page_title', 'gpa_hero_breadcrumb' );
add_action( 'generate_before_entry_title', 'gpa_hero_breadcrumb' );
function gpa_hero_breadcrumb() {
    static $done = false;
    if ( $done || ! is_singular( array( 'page', 'post' ) ) || is_front_page() || ! in_the_loop() || ! is_main_query() ) {
        return;
    }
    if ( is_page() && ! gpa_is_content_hero_page() && ! ( function_exists( 'gpa_is_calculator_tool_page' ) && gpa_is_calculator_tool_page() ) ) {
        return;
    }
    $done = true;
    gpa_render_breadcrumb();
}

add_filter( 'rank_math/json_ld', 'gpa_breadcrumb_list_schema', 110, 2 );
function gpa_breadcrumb_list_schema( $data, $jsonld ) {
    if ( is_front_page() || is_home() ) {
        return $data;
    }

    $home_url      = home_url( '/' );
    $admission_url = get_post_type_archive_link( 'colleges' );
    if ( ! $admission_url ) {
        $admission_url = home_url( '/admissions/' );
    }
    $items         = array(
        array(
            '@type'    => 'ListItem',
            'position' => 1,
            'name'     => 'Home',
            'item'     => $home_url,
        ),
    );

    if ( is_singular( 'colleges' ) ) {
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => 'College Admissions',
            'item'     => $admission_url,
        );
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 3,
            'name'     => get_the_title(),
            'item'     => get_permalink(),
        );
        $page_url = get_permalink();
    } elseif ( is_post_type_archive( 'colleges' ) ) {
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => 2,
            'name'     => 'College Admissions',
            'item'     => $admission_url,
        );
        $page_url = $admission_url;
    } elseif ( is_singular() ) {
        $post_id = get_queried_object_id();
        foreach ( gpa_breadcrumb_ancestors( $post_id ) as $ancestor_id ) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => count( $items ) + 1,
                'name'     => gpa_breadcrumb_title( $ancestor_id ),
                'item'     => get_permalink( $ancestor_id ),
            );
        }
        $items[] = array(
            '@type'    => 'ListItem',
            'position' => count( $items ) + 1,
            'name'     => gpa_breadcrumb_title( $post_id ),
            'item'     => get_permalink( $post_id ),
        );
        $page_url = get_permalink( $post_id );
    } else {
        return $data;
    }

    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) && isset( $value['@type'] ) && $value['@type'] === 'BreadcrumbList' ) {
            unset( $data[ $key ] );
        }
    }

    $data['BreadcrumbList'] = array(
        '@type'           => 'BreadcrumbList',
        '@id'             => $page_url . '#breadcrumb',
        'itemListElement' => $items,
    );

    return $data;
}

add_filter( 'rank_math/json_ld', 'gpa_howto_schema', 100, 2 );
function gpa_howto_schema( $data, $jsonld ) {
    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) && isset( $value['@type'] ) && $value['@type'] === 'HowTo' ) {
            unset( $data[ $key ] );
        }
    }

    if ( ! gpa_is_calculator_page() ) {
        return $data;
    }

    $post = get_post();
    if ( ! $post || false === strpos( $post->post_content, 'rx-step' ) ) {
        return $data;
    }

    $prev = libxml_use_internal_errors( true );
    $doc  = new DOMDocument();
    $doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>' );
    libxml_clear_errors();
    libxml_use_internal_errors( $prev );

    $xpath = new DOMXPath( $doc );
    $cards = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' rx-step ')]" );
    if ( ! $cards || 0 === $cards->length ) {
        return $data;
    }

    $page_url = get_permalink( $post );

    $section    = $xpath->query( "ancestor::*[contains(concat(' ', normalize-space(@class), ' '), ' rx-sec ')][1]", $cards->item( 0 ) )->item( 0 );
    $section_id = $section ? trim( $section->getAttribute( 'id' ) ) : '';
    $fallback   = $section_id ? $page_url . '#' . $section_id : $page_url;

    $clean = function ( $text ) {
        return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
    };

    $name        = '';
    $description = '';
    if ( $section ) {
        $eyebrow = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' rx-eyebrow ')]", $section )->item( 0 );
        if ( $eyebrow ) {
            $name = $clean( $eyebrow->textContent );
        }
        $heading = $xpath->query( './/h2', $section )->item( 0 );
        if ( $heading ) {
            $description = $clean( $heading->textContent );
            if ( '' === $name ) {
                $name = $description;
            }
        }
    }
    if ( '' === $name ) {
        $name = get_the_title( $post );
    }

    $steps = array();
    foreach ( $cards as $card ) {
        $heading = $xpath->query( './/h3|.//h4', $card )->item( 0 );
        $body    = $xpath->query( './/p', $card )->item( 0 );
        if ( ! $heading || ! $body ) {
            continue;
        }

        $step_name = $clean( $heading->textContent );
        $step_text = $clean( $body->textContent );
        if ( '' === $step_name || '' === $step_text ) {
            continue;
        }

        $step_id = trim( $card->getAttribute( 'id' ) );
        if ( '' === $step_id ) {
            $step_id = trim( $heading->getAttribute( 'id' ) );
        }

        $steps[] = array(
            '@type'    => 'HowToStep',
            'position' => count( $steps ) + 1,
            'name'     => $step_name,
            'text'     => $step_text,
            'url'      => '' !== $step_id ? $page_url . '#' . $step_id : $fallback,
        );
    }

    if ( count( $steps ) < 2 ) {
        return $data;
    }

    $data['HowTo'] = array(
        '@type' => 'HowTo',
        '@id'   => $page_url . '#howto',
        'name'  => $name,
        'step'  => $steps,
    );
    if ( '' !== $description ) {
        $data['HowTo']['description'] = $description;
    }

    return $data;
}

add_filter( 'rank_math/json_ld', 'gpa_content_page_article_schema', 99, 2 );
function gpa_content_page_article_schema( $data, $jsonld ) {
    $article_slugs = apply_filters( 'gpa_article_page_slugs', array(
        'gpa-scale',
        'grade-conversion',
        'how-to-raise-gpa',
    ) );
    if ( is_front_page() || ! is_page( $article_slugs ) ) {
        return $data;
    }

    foreach ( $data as $key => $value ) {
        if ( is_array( $value ) && isset( $value['@type'] )
            && in_array( $value['@type'], array( 'Article', 'BlogPosting' ), true ) ) {
            unset( $data[ $key ] );
        }
    }

    $post_id  = get_the_ID();
    $page_url = get_permalink( $post_id );
    $org_id   = home_url( '/' ) . '#organization';

    $description = get_post_meta( $post_id, 'rank_math_description', true );
    if ( empty( $description ) ) {
        $description = get_the_excerpt( $post_id );
    }

    $section = apply_filters( 'gpa_content_article_section', 'GPA Guides', $post_id );

    $article = array(
        '@type'            => 'Article',
        '@id'              => $page_url . '#article',
        'headline'         => get_the_title( $post_id ),
        'inLanguage'       => get_bloginfo( 'language' ),
        'datePublished'    => get_the_date( DATE_W3C, $post_id ),
        'dateModified'     => get_the_modified_date( DATE_W3C, $post_id ),
        'author'           => array( '@id' => $org_id ),
        'publisher'        => array( '@id' => $org_id ),
        'mainEntityOfPage' => array( '@id' => $page_url . '#webpage' ),
    );

    if ( ! empty( $section ) ) {
        $article['articleSection'] = $section;
    }
    if ( ! empty( $description ) ) {
        $article['description'] = $description;
    }
    if ( has_post_thumbnail( $post_id ) ) {
        $article['image'] = get_the_post_thumbnail_url( $post_id, 'full' );
    }

    $data['Article'] = $article;

    return $data;
}

add_filter( 'the_content', 'gpa_fix_step_heading_hierarchy', 20 );
function gpa_fix_step_heading_hierarchy( $content ) {
    if ( ! gpa_is_calculator_page() ) {
        return $content;
    }
    return preg_replace(
        '#<h3(\s+class="wp-block-heading")>(\s*\d+\.\s+[^<]+)</h3>#',
        '<div$1 role="heading" aria-level="4">$2</div>',
        $content
    );
}

//add_filter( 'the_content', 'gpa_inject_jump_to_menu', 25 );
function gpa_inject_jump_to_menu( $content ) {
    if ( is_admin() || ! is_singular() || ! is_main_query() || ! in_the_loop() ) {
        return $content;
    }
    if ( ! is_page( 'gpa-scale' ) ) {
        return $content;
    }
    if ( '' === trim( $content ) ) {
        return $content;
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors( true );
    $loaded = $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="gpa-jumpto-wrap">' . $content . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    if ( ! $loaded ) {
        return $content;
    }

    $wrap = $doc->getElementsByTagName( 'div' )->item( 0 );
    if ( ! $wrap ) {
        return $content;
    }

    $h2s   = $doc->getElementsByTagName( 'h2' );
    $items = array();
    $used  = array();
    foreach ( $h2s as $h2 ) {
        $text = trim( preg_replace( '/\s+/', ' ', $h2->textContent ) );
        if ( '' === $text ) {
            continue;
        }
        $id = $h2->getAttribute( 'id' );
        if ( '' === $id ) {
            $base = sanitize_title( $text );
            if ( '' === $base ) {
                continue;
            }
            $id = $base;
            $n  = 2;
            while ( in_array( $id, $used, true ) ) {
                $id = $base . '-' . $n++;
            }
            $h2->setAttribute( 'id', $id );
        }
        $used[]  = $id;
        $items[] = array( 'id' => $id, 'text' => $text );
    }

    if ( count( $items ) < 2 ) {
        return $content;
    }

    $links = '';
    foreach ( $items as $item ) {
        $links .= sprintf(
            '<li class="gpa-jumpto-item"><a class="gpa-jumpto-link" href="#%s">%s</a></li>',
            esc_attr( $item['id'] ),
            esc_html( $item['text'] )
        );
    }
    $nav_html = '<nav class="gpa-jumpto" aria-label="On this page">'
        . '<p class="gpa-jumpto-label">On this page</p>'
        . '<ul class="gpa-jumpto-list">' . $links . '</ul>'
        . '</nav>';

    $navDoc = new DOMDocument();
    libxml_use_internal_errors( true );
    $navDoc->loadHTML(
        '<?xml encoding="utf-8"?>' . $nav_html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $navRoot = $navDoc->getElementsByTagName( 'nav' )->item( 0 );
    if ( ! $navRoot ) {
        return $content;
    }
    $navNode = $doc->importNode( $navRoot, true );

    $xpath = new DOMXPath( $doc );
    $hero  = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' gpa-hero ')]" )->item( 0 );
    if ( $hero && $hero->parentNode ) {
        if ( $hero->nextSibling ) {
            $hero->parentNode->insertBefore( $navNode, $hero->nextSibling );
        } else {
            $hero->parentNode->appendChild( $navNode );
        }
    } else {
        $wrap->insertBefore( $navNode, $wrap->firstChild );
    }

    $out = '';
    foreach ( $wrap->childNodes as $child ) {
        $out .= $doc->saveHTML( $child );
    }
    return $out;
}


// ============================================================
// PART 6: COLLEGE DATABASE - CPT & ACF
// ============================================================

add_action('init', 'gpa_register_colleges_cpt');
function gpa_register_colleges_cpt() {
    $labels = array(
        'name'                  => 'Colleges',
        'singular_name'         => 'College',
        'menu_name'             => 'Colleges',
        'name_admin_bar'        => 'College',
        'add_new'               => 'Add New',
        'add_new_item'          => 'Add New College',
        'new_item'              => 'New College',
        'edit_item'             => 'Edit College',
        'view_item'             => 'View College',
        'all_items'             => 'All Colleges',
        'search_items'          => 'Search Colleges',
        'parent_item_colon'     => 'Parent College:',
        'not_found'             => 'No colleges found.',
        'not_found_in_trash'    => 'No colleges found in Trash.',
        'archives'              => 'College Archives',
        'filter_items_list'     => 'Filter colleges list',
        'items_list_navigation' => 'Colleges list navigation',
        'items_list'            => 'Colleges list',
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_rest'       => true,
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'admissions', 'with_front' => false ),
        'capability_type'    => 'page',
        'has_archive'        => true,
        'hierarchical'       => true,
        'menu_position'      => 20,
        'menu_icon'          => 'dashicons-welcome-learn-more',
        'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ),
    );

    register_post_type( 'colleges', $args );
}

add_action('after_switch_theme', 'gpa_flush_rewrite_rules');
function gpa_flush_rewrite_rules() {
    gpa_register_colleges_cpt();
    flush_rewrite_rules();
}

add_action('acf/init', 'gpa_register_college_acf_fields');
function gpa_register_college_acf_fields() {

    if ( ! function_exists('acf_add_local_field_group') ) {
        return;
    }

    $fields = array(
        array( 'key' => 'field_college_location', 'label' => 'Location', 'name' => 'location', 'type' => 'text' ),
        array( 'key' => 'field_college_owning', 'label' => 'Owning (Public/Private)', 'name' => 'owning', 'type' => 'text' ),
        array( 'key' => 'field_college_average_gpa', 'label' => 'Average GPA', 'name' => 'average_gpa', 'type' => 'text' ),
        array( 'key' => 'field_college_enrollment', 'label' => 'Enrollment', 'name' => 'enrollment', 'type' => 'text' ),
        array( 'key' => 'field_college_net_price', 'label' => 'Net Price', 'name' => 'net_price', 'type' => 'text' ),
        array( 'key' => 'field_college_acceptance_rate', 'label' => 'Acceptance Rate', 'name' => 'acceptance_rate', 'type' => 'text' ),

        array( 'key' => 'field_college_sat_range', 'label' => 'SAT Range', 'name' => 'sat_range', 'type' => 'text' ),
        array( 'key' => 'field_college_applicants_submitting_sat', 'label' => 'Applicants Submitting SAT', 'name' => 'applicants_submitting_sat', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_reading_25', 'label' => 'SAT Reading 25th Percentile', 'name' => 'sat_reading_25', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_math_25', 'label' => 'SAT Math 25th Percentile', 'name' => 'sat_math_25', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_composite_25', 'label' => 'SAT Composite 25th Percentile', 'name' => 'sat_composite_25', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_reading_75', 'label' => 'SAT Reading 75th Percentile', 'name' => 'sat_reading_75', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_math_75', 'label' => 'SAT Math 75th Percentile', 'name' => 'sat_math_75', 'type' => 'text' ),
        array( 'key' => 'field_college_sat_composite_75', 'label' => 'SAT Composite 75th Percentile', 'name' => 'sat_composite_75', 'type' => 'text' ),
        array( 'key' => 'field_college_average_sat_score', 'label' => 'Average SAT Score', 'name' => 'average_sat_score', 'type' => 'text' ),

        array( 'key' => 'field_college_act_range', 'label' => 'ACT Range', 'name' => 'act_range', 'type' => 'text' ),
        array( 'key' => 'field_college_applicant_submitting_act', 'label' => 'Applicants Submitting ACT', 'name' => 'applicant_submitting_act', 'type' => 'text' ),
        array( 'key' => 'field_college_act_reading_25', 'label' => 'ACT Reading 25th Percentile', 'name' => 'act_reading_25', 'type' => 'text' ),
        array( 'key' => 'field_college_act_math_25', 'label' => 'ACT Math 25th Percentile', 'name' => 'act_math_25', 'type' => 'text' ),
        array( 'key' => 'field_college_act_composite_25', 'label' => 'ACT Composite 25th Percentile', 'name' => 'act_composite_25', 'type' => 'text' ),
        array( 'key' => 'field_college_act_reading_75', 'label' => 'ACT Reading 75th Percentile', 'name' => 'act_reading_75', 'type' => 'text' ),
        array( 'key' => 'field_college_act_math_75', 'label' => 'ACT Math 75th Percentile', 'name' => 'act_math_75', 'type' => 'text' ),
        array( 'key' => 'field_college_act_composite_75', 'label' => 'ACT Composite 75th Percentile', 'name' => 'act_composite_75', 'type' => 'text' ),
        array( 'key' => 'field_college_average_act_score', 'label' => 'Average ACT Score', 'name' => 'average_act_score', 'type' => 'text' ),

        array( 'key' => 'field_college_admission_standards', 'label' => 'Admission Standards', 'name' => 'admission_standards', 'type' => 'text' ),
        array( 'key' => 'field_college_applicant_competition', 'label' => 'Applicant Competition', 'name' => 'applicant_competition', 'type' => 'text' ),

        array( 'key' => 'field_college_adm_req_test_scores', 'label' => 'Admission Req: Test Scores', 'name' => 'admission_requirements_test_scores', 'type' => 'text' ),
        array( 'key' => 'field_college_adm_req_hs_gpa', 'label' => 'Admission Req: High School GPA', 'name' => 'admission_requirements_high_school_gpa', 'type' => 'text' ),
        array( 'key' => 'field_college_adm_req_hs_class_rank', 'label' => 'Admission Req: HS Class Rank', 'name' => 'admission_requirements_high_school_class_rank', 'type' => 'text' ),
        array( 'key' => 'field_college_adm_req_college_prep', 'label' => 'Admission Req: College Prep Program', 'name' => 'admission_requirements_completion_of_college_preparatory_program', 'type' => 'text' ),
        array( 'key' => 'field_college_adm_req_recommendations', 'label' => 'Admission Req: Recommendations', 'name' => 'admission_requirements_recommendations', 'type' => 'text' ),
        array( 'key' => 'field_college_adm_req_competencies', 'label' => 'Admission Req: Demonstration of Competencies', 'name' => 'admission_requirements_demonstration_of_competencies', 'type' => 'text' ),

        array( 'key' => 'field_college_ap_credit', 'label' => 'AP Credit', 'name' => 'ap_credit', 'type' => 'text', 'instructions' => 'Yes or No' ),
        array( 'key' => 'field_college_dual_credit', 'label' => 'Dual Credit', 'name' => 'dual_credit', 'type' => 'text', 'instructions' => 'Yes or No' ),
        array( 'key' => 'field_college_credit_life_experiences', 'label' => 'Credit for Life Experiences', 'name' => 'credit_for_life_experiences', 'type' => 'text', 'instructions' => 'Yes or No' ),

        array( 'key' => 'field_college_img_url', 'label' => 'Image URL', 'name' => 'img_url', 'type' => 'url' ),
    );

    acf_add_local_field_group(array(
        'key'                   => 'group_college_admissions_data',
        'title'                 => 'College Admissions Data',
        'fields'                => $fields,
        'location'              => array(
            array(
                array(
                    'param'    => 'post_type',
                    'operator' => '==',
                    'value'    => 'colleges',
                ),
            ),
        ),
        'menu_order'            => 0,
        'position'              => 'normal',
        'style'                 => 'default',
        'label_placement'       => 'top',
        'instruction_placement' => 'label',
        'active'                => true,
    ));
}


// ============================================================
// PART 7: COLLEGE DATABASE - AJAX
// ============================================================

if ( ! function_exists( 'gpa_fmt_gpa' ) ) {
    function gpa_fmt_gpa( $value ) {
        if ( $value === '' || $value === null ) return '';
        $num = floatval( $value );
        if ( $num <= 0 ) return '';
        return number_format( $num, 1 );
    }
}
if ( ! function_exists( 'gpa_fmt_pct' ) ) {
    function gpa_fmt_pct( $value ) {
        $value = trim( (string) $value );
        if ( $value === '' ) return '';
        if ( strpos( $value, '%' ) !== false ) return $value;
        return $value . '%';
    }
}

add_shortcode( 'gpa_college_archive', 'gpa_college_archive_shortcode' );
function gpa_college_archive_shortcode() {
    // The college finder from the /admissions/ hub; its styles are in admissions.css (section 7)
    wp_enqueue_style(
        'gpa-admissions',
        get_stylesheet_directory_uri() . '/admissions.css',
        array('gpa-components'),
        gpa_asset_ver( 'admissions.css' )
    );
    wp_enqueue_script(
        'database-ajax',
        get_stylesheet_directory_uri() . '/database-ajax.js',
        array(),
        gpa_asset_ver( 'database-ajax.js' ),
        true
    );
    ob_start();
    get_template_part( 'template-parts/college-db-archive' );
    return ob_get_clean();
}

// gpa_render_college_card(), one college in the hub list and its AJAX results: college-data.php

add_action('wp_ajax_filter_colleges', 'gpa_ajax_filter_colleges');
add_action('wp_ajax_nopriv_filter_colleges', 'gpa_ajax_filter_colleges');
function gpa_ajax_filter_colleges() {

    check_ajax_referer( 'gpa_db_filter_nonce', 'nonce' );

    $search          = isset($_POST['search']) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
    $quick_filter    = isset($_POST['quick_filter']) ? sanitize_text_field( wp_unslash( $_POST['quick_filter'] ) ) : '';
    $ownership       = isset($_POST['ownership']) ? sanitize_text_field( wp_unslash( $_POST['ownership'] ) ) : '';
    $acceptance_rate = isset($_POST['acceptance_rate']) ? sanitize_text_field( wp_unslash( $_POST['acceptance_rate'] ) ) : '';
    $gpa_filter      = isset($_POST['gpa']) ? sanitize_text_field( wp_unslash( $_POST['gpa'] ) ) : '';
    $sat_filter      = isset($_POST['sat']) ? sanitize_text_field( wp_unslash( $_POST['sat'] ) ) : '';
    $state_filter    = isset($_POST['state']) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '';
    $sort            = isset($_POST['sort']) ? sanitize_text_field( wp_unslash( $_POST['sort'] ) ) : 'name_asc';
    $paged           = isset($_POST['page']) ? max( 1, absint($_POST['page']) ) : 1;
    $per_page        = isset($_POST['per_page']) ? max( 1, min( 100, absint($_POST['per_page']) ) ) : 30;

    $args = array(
        'post_type'      => 'colleges',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
    );

    if ( $search ) {
        // Every word must be in the college's name, its federal (IPEDS) name, a former name or its city and state
        // (gpa_college_hub_search_sql() in college-data.php)
        $args['gpa_hub_terms'] = array_slice( preg_split( '/[\s,]+/', $search, -1, PREG_SPLIT_NO_EMPTY ), 0, 6 );
    }

    $meta_query = array( 'relation' => 'AND' );

    $ownership_value = '';
    if ( $ownership === 'public' || $ownership === 'private' ) {
        $ownership_value = $ownership;
    } elseif ( $quick_filter === 'public' || $quick_filter === 'private' ) {
        $ownership_value = $quick_filter;
    }
    if ( $ownership_value ) {
        $meta_query[] = array(
            'key'     => 'owning',
            'value'   => $ownership_value,
            'compare' => 'LIKE',
        );
    }

    // "Under N%" skips colleges with no rate: an empty acceptance_rate (open admission, not reported, or emptied by
    // the E import for want of a source) casts to 0 and would otherwise count as the most selective.
    switch ( $acceptance_rate ) {
        case 'under_10':
            $meta_query[] = array( 'key' => 'acceptance_rate', 'value' => array( 0.01, 10 - 0.01 ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(5,2)' );
            break;
        case 'under_25':
            $meta_query[] = array( 'key' => 'acceptance_rate', 'value' => array( 0.01, 25 - 0.01 ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(5,2)' );
            break;
        case 'under_50':
            $meta_query[] = array( 'key' => 'acceptance_rate', 'value' => array( 0.01, 50 - 0.01 ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(5,2)' );
            break;
        case 'over_50':
            $meta_query[] = array(
                'relation' => 'OR',
                array( 'key' => 'acceptance_rate', 'value' => 50, 'compare' => '>=', 'type' => 'DECIMAL(5,2)' ),
                array( 'key' => 'adm_open_admission', 'value' => 'Yes' ), // open admission: no rate, admits everyone
            );
            break;
    }

    // ?state=MA: the colleges in one state (college pages' "See all colleges in {State}"; noindex like every filter)
    if ( function_exists( 'gpa_college_state_names' ) && isset( gpa_college_state_names()[ $state_filter ] ) ) {
        $meta_query[] = array( 'key' => 'college_state', 'value' => $state_filter );
    }

    // ?gpa=3.5: the GPA-band list's colleges (college-v2.php: a cited Common Data Set average in the list's window, tier
    // A or B). Any other value, including the old 3.5_plus style links, lists every college instead of none.
    if ( function_exists( 'gpa_college_band_meta_query' ) && ( $band_query = gpa_college_band_meta_query( $gpa_filter ) ) ) {
        $meta_query[] = $band_query;
    }
    $gpa_filter = '';
    switch ( $gpa_filter ) {
        case '3.5_plus':
            $meta_query[] = array( 'key' => 'average_gpa', 'value' => 3.5, 'compare' => '>=', 'type' => 'DECIMAL(3,2)' );
            break;
        case '3.0_3.5':
            $meta_query[] = array( 'key' => 'average_gpa', 'value' => array( 3.0, 3.5 ), 'compare' => 'BETWEEN', 'type' => 'DECIMAL(3,2)' );
            break;
        case 'under_3.0':
            $meta_query[] = array( 'key' => 'average_gpa', 'value' => 3.0, 'compare' => '<', 'type' => 'DECIMAL(3,2)' );
            break;
    }

    // "Under 1200" skips colleges with no SAT figure, as "Under N%" does above: an empty average_sat_score (not
    // reported, or emptied by the E import for want of a source) casts to 0.
    switch ( $sat_filter ) {
        case '1400_plus':
            $meta_query[] = array( 'key' => 'average_sat_score', 'value' => 1400, 'compare' => '>=', 'type' => 'NUMERIC' );
            break;
        case '1200_1400':
            $meta_query[] = array( 'key' => 'average_sat_score', 'value' => array( 1200, 1400 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' );
            break;
        case 'under_1200':
            $meta_query[] = array( 'key' => 'average_sat_score', 'value' => array( 1, 1199 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' );
            break;
    }

    if ( $quick_filter === 'high_acceptance' ) {
        $meta_query[] = array(
            'relation' => 'OR',
            array( 'key' => 'acceptance_rate', 'value' => 70, 'compare' => '>=', 'type' => 'DECIMAL(5,2)' ),
            array( 'key' => 'adm_open_admission', 'value' => 'Yes' ),
        );
    } elseif ( $quick_filter === 'ivy_league' ) {
        // By IPEDS unit ID: Brown, Columbia, Cornell, Dartmouth, Harvard, Penn, Princeton, Yale
        $meta_query[] = array(
            'key'     => 'ipeds_unitid',
            'value'   => array( '217156', '190150', '190415', '182670', '166027', '215062', '186131', '130794' ),
            'compare' => 'IN',
        );
    }

    // Lowest-first sorts would list colleges with no figure (open admission, not reported) as if it were 0
    if ( 'acceptance_asc' === $sort ) {
        $meta_query[] = array( 'key' => 'acceptance_rate', 'value' => 0, 'compare' => '>', 'type' => 'DECIMAL(5,2)' );
    } elseif ( 'sat_asc' === $sort ) {
        $meta_query[] = array( 'key' => 'average_sat_score', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' );
    }

    if ( count( $meta_query ) > 1 ) {
        $args['meta_query'] = $meta_query;
    }

    $sort_map = array(
        'gpa_desc'        => array( 'meta_key' => 'average_gpa',       'orderby' => 'meta_value_num', 'order' => 'DESC' ),
        'gpa_asc'         => array( 'meta_key' => 'average_gpa',       'orderby' => 'meta_value_num', 'order' => 'ASC' ),
        'acceptance_asc'  => array( 'meta_key' => 'acceptance_rate',   'orderby' => 'meta_value_num', 'order' => 'ASC' ),
        'acceptance_desc' => array( 'meta_key' => 'acceptance_rate',   'orderby' => 'meta_value_num', 'order' => 'DESC' ),
        'sat_desc'        => array( 'meta_key' => 'average_sat_score', 'orderby' => 'meta_value_num', 'order' => 'DESC' ),
        'sat_asc'         => array( 'meta_key' => 'average_sat_score', 'orderby' => 'meta_value_num', 'order' => 'ASC' ),
        'name_asc'        => array( 'orderby' => 'title', 'order' => 'ASC' ),
        'name_desc'       => array( 'orderby' => 'title', 'order' => 'DESC' ),
    );
    $sort_map['gpa_desc'] = $sort_map['gpa_asc'] = $sort_map['name_asc']; // no GPA to sort by (see above)
    $sort_cfg = isset( $sort_map[ $sort ] ) ? $sort_map[ $sort ] : $sort_map['name_asc'];
    foreach ( $sort_cfg as $k => $v ) {
        $args[ $k ] = $v;
    }

    add_filter( 'posts_search', 'gpa_college_hub_search_sql', 10, 2 );
    $query = new WP_Query( $args );
    remove_filter( 'posts_search', 'gpa_college_hub_search_sql', 10 );

    $html = '';
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $html .= gpa_render_college_card( get_the_ID() );
        }
        wp_reset_postdata();
    }

    wp_send_json_success( array(
        'html'      => $html,
        'total'     => (int) $query->found_posts,
        'has_more'  => ( $paged < $query->max_num_pages ),
        'max_pages' => (int) $query->max_num_pages,
        'page'      => $paged,
    ) );
}

// ============================================================
// PART 8: BLOCK PATTERNS
// ============================================================
require_once get_stylesheet_directory() . '/gpa-block-patterns.php';

// ============================================================
// PART 9: SHORTCODES (live-rendered content fragments)
// ============================================================
require_once get_stylesheet_directory() . '/gpa-shortcodes.php';

// ============================================================
// PART 10: ONE-SHOT MIGRATIONS (delete after run)
// ============================================================
if (file_exists(get_stylesheet_directory() . '/gpa-migrate-tables.php')) {
    require_once get_stylesheet_directory() . '/gpa-migrate-tables.php';
}

// ============================================================
// PART 11: WEBP DELIVERY  (T6.2 — image optimization)
// ============================================================

function gpa_webp_sibling_url( $url ) {
    static $upload = null;
    static $cache  = array();

    if ( array_key_exists( $url, $cache ) ) {
        return $cache[ $url ];
    }
    if ( $upload === null ) {
        $u = wp_get_upload_dir();
        $upload = array( 'baseurl' => $u['baseurl'], 'basedir' => $u['basedir'] );
    }

    $clean = preg_replace( '/[?#].*$/', '', $url );

    if ( ! preg_match( '/\.(jpe?g|png)$/i', $clean )
         || strpos( $clean, $upload['baseurl'] ) !== 0 ) {
        return $cache[ $url ] = false;
    }

    $rel  = substr( $clean, strlen( $upload['baseurl'] ) );
    $file = $upload['basedir'] . $rel . '.webp';

    return $cache[ $url ] = is_file( $file ) ? ( $url . '.webp' ) : false;
}

function gpa_webp_wrap_img( $img_tag ) {
    if ( strpos( $img_tag, 'data-no-webp' ) !== false ) {
        return $img_tag;
    }

    $webp_srcset = '';

    if ( preg_match( '/\ssrcset=("|\')(.*?)\1/is', $img_tag, $m ) ) {
        $out = array();
        foreach ( array_map( 'trim', explode( ',', $m[2] ) ) as $cand ) {
            $parts = preg_split( '/\s+/', trim( $cand ), 2 );
            if ( empty( $parts[0] ) ) {
                continue;
            }
            $webp = gpa_webp_sibling_url( $parts[0] );
            if ( false === $webp ) {
                $out = array();
                break;
            }
            $out[] = isset( $parts[1] ) ? $webp . ' ' . $parts[1] : $webp;
        }
        if ( $out ) {
            $webp_srcset = implode( ', ', $out );
        }
    }

    if ( '' === $webp_srcset && preg_match( '/\ssrc=("|\')(.*?)\1/is', $img_tag, $ms ) ) {
        $webp = gpa_webp_sibling_url( $ms[2] );
        if ( false !== $webp ) {
            $webp_srcset = $webp;
        }
    }

    if ( '' === $webp_srcset ) {
        return $img_tag;
    }

    $sizes_attr = '';
    if ( preg_match( '/\ssizes=("|\')(.*?)\1/is', $img_tag, $sz ) ) {
        $sizes_attr = ' sizes="' . esc_attr( $sz[2] ) . '"';
    }

    $source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"' . $sizes_attr . ' />';
    return '<picture>' . $source . $img_tag . '</picture>';
}

function gpa_webp_rewrite_html( $html ) {
    if ( is_admin() || ! is_string( $html ) || '' === $html
         || false === stripos( $html, '<img' ) ) {
        return $html;
    }
    return preg_replace_callback(
        '/<img\b[^>]*>/i',
        static function ( $mm ) { return gpa_webp_wrap_img( $mm[0] ); },
        $html
    );
}
add_filter( 'the_content', 'gpa_webp_rewrite_html', 20 );
add_filter( 'post_thumbnail_html', 'gpa_webp_rewrite_html', 20 );

add_filter( 'rank_math/json_ld', 'gpa_calc_faq_schema', 101, 2 );
function gpa_calc_faq_schema( $data, $jsonld ) {
	if ( ! gpa_is_calculator_page() ) {
		return $data;
	}

	$post = get_post();
	if ( ! $post || false === strpos( $post->post_content, 'rx-faq' ) ) {
		return $data;
	}

	$html = $post->post_content;

	$prev = libxml_use_internal_errors( true );
	$doc  = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xpath = new DOMXPath( $doc );
	$nodes = $xpath->query( "//details[contains(concat(' ', normalize-space(@class), ' '), ' rx-faq ')]" );
	if ( ! $nodes || 0 === $nodes->length ) {
		return $data;
	}

	$items = array();
	foreach ( $nodes as $details ) {
		$summary = $xpath->query( './summary', $details )->item( 0 );
		if ( ! $summary ) {
			continue;
		}

		$question = trim( preg_replace( '/\s+/', ' ', $summary->textContent ) );

		$answer = '';
		foreach ( $details->childNodes as $child ) {
			if ( $child->isSameNode( $summary ) ) {
				continue;
			}
			$answer .= $doc->saveHTML( $child );
		}
		$answer = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $answer ) ) );

		if ( '' === $question || '' === $answer ) {
			continue;
		}

		$items[] = array(
			'@type'          => 'Question',
			'name'           => $question,
			'acceptedAnswer' => array(
				'@type' => 'Answer',
				'text'  => $answer,
			),
		);
	}

	if ( empty( $items ) ) {
		return $data;
	}

	$data['FAQPage'] = array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( $post ) . '#faq',
		'mainEntity' => $items,
	);

	return $data;
}

function gpa_calc_hero_intro_block() {
    static $cache = null;
    if ( null !== $cache ) {
        return $cache;
    }
    $cache = false;

    if ( ! gpa_is_calculator_tool_page() && ! gpa_is_content_hero_page() ) {
        return $cache;
    }

    $post = get_post();
    if ( ! $post || '' === trim( $post->post_content ) ) {
        return $cache;
    }

    foreach ( parse_blocks( $post->post_content ) as $block ) {
        if ( empty( $block['blockName'] ) ) {
            continue;
        }
        if ( 'core/paragraph' !== $block['blockName'] ) {
            break;
        }

        $raw = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';

        if ( $raw !== strip_shortcodes( $raw ) ) {
            break;
        }

        $text = trim( wp_strip_all_tags( $raw ) );
        if ( strlen( $text ) < 40 ) {
            break;
        }

        $cache = $block;
        break;
    }

    return $cache;
}

add_action( 'generate_after_page_title', 'gpa_calc_render_hero_intro' );
function gpa_calc_render_hero_intro() {
    $block = gpa_calc_hero_intro_block();
    if ( ! $block ) {
        return;
    }
    echo '<div class=' . '"rx-hero-intro">' . wp_kses_post( $block['innerHTML'] ) . '</div>';
}

add_filter( 'render_block', 'gpa_calc_skip_hero_intro', 10, 2 );
function gpa_calc_skip_hero_intro( $html, $block ) {
    static $removed = false;
    if ( $removed ) {
        return $html;
    }
    $target = gpa_calc_hero_intro_block();
    if ( ! $target ) {
        return $html;
    }
    if ( ! isset( $block['innerHTML'] ) || $block['innerHTML'] !== $target['innerHTML'] ) {
        return $html;
    }
    $removed = true;
    return '';
}

function gpa_home_schema_dom() {
    static $xp = null;
    if ( null !== $xp ) {
        return $xp;
    }
    $xp   = false;
    $post = get_post();
    if ( ! $post || '' === trim( $post->post_content ) ) {
        return $xp;
    }
    if ( ! class_exists( 'DOMDocument' ) ) {
        return $xp;
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors( true );
    $dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $post->post_content . '</div>' );
    libxml_clear_errors();
    $xp = new DOMXPath( $dom );
    return $xp;
}

function gpa_home_faq_entities() {
    $xp = gpa_home_schema_dom();
    if ( ! $xp ) {
        return array();
    }
    $out   = array();
    $items = $xp->query( "//div[contains(concat(' ', normalize-space(@class), ' '), ' rank-math-faq-item ')]" );
    foreach ( $items as $item ) {
        $q = $xp->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' rank-math-question ')]", $item )->item( 0 );
        $a = $xp->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' rank-math-answer ')]", $item )->item( 0 );
        if ( ! $q || ! $a ) {
            continue;
        }
        $qt = trim( preg_replace( '/\s+/u', ' ', $q->textContent ) );
        $at = trim( preg_replace( '/\s+/u', ' ', $a->textContent ) );
        if ( '' === $qt || '' === $at ) {
            continue;
        }
        $out[] = array(
            '@type'          => 'Question',
            'name'           => $qt,
            'acceptedAnswer' => array(
                '@type' => 'Answer',
                'text'  => $at,
            ),
        );
    }
    return $out;
}

function gpa_home_howto_steps() {
    $xp = gpa_home_schema_dom();
    if ( ! $xp ) {
        return array();
    }
    $start = null;
    foreach ( $xp->query( '//h3' ) as $h3 ) {
        if ( false !== stripos( trim( $h3->textContent ), 'How to Calculate GPA' ) ) {
            $start = $h3;
            break;
        }
    }
    if ( ! $start ) {
        return array();
    }

    $steps = array();
    foreach ( $xp->query( 'following::*[self::h2 or self::h3 or self::h4]', $start ) as $node ) {
        $tag = strtolower( $node->nodeName );
        if ( 'h2' === $tag || 'h3' === $tag ) {
            break;
        }
        $name = trim( preg_replace( '/\s+/u', ' ', $node->textContent ) );
        if ( '' === $name ) {
            continue;
        }
        $text = '';
        $sib  = $node->nextSibling;
        while ( $sib ) {
            if ( XML_ELEMENT_NODE === $sib->nodeType ) {
                if ( 'p' === strtolower( $sib->nodeName ) ) {
                    $text = trim( preg_replace( '/\s+/u', ' ', $sib->textContent ) );
                }
                break;
            }
            $sib = $sib->nextSibling;
        }
        $step = array(
            '@type'    => 'HowToStep',
            'position' => count( $steps ) + 1,
            'name'     => $name,
        );
        if ( '' !== $text ) {
            $step['text'] = $text;
        }
        $steps[] = $step;
    }
    return $steps;
}

add_filter( 'rank_math/json_ld', 'gpa_home_schema_standard', 999, 2 );
function gpa_home_schema_standard( $data, $jsonld ) {
    if ( ! is_front_page() || ! is_array( $data ) ) {
        return $data;
    }

    foreach ( $data as $key => $node ) {
        if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
            continue;
        }
        $type  = $node['@type'];
        $match = is_array( $type )
            ? in_array( 'SoftwareApplication', $type, true )
            : 'SoftwareApplication' === $type;
        if ( ! $match ) {
            continue;
        }
        $data[ $key ]['@type'] = 'WebApplication';
        if ( empty( $data[ $key ]['applicationCategory'] ) ) {
            $data[ $key ]['applicationCategory'] = 'EducationalApplication';
        }
        if ( empty( $data[ $key ]['browserRequirements'] ) ) {
            $data[ $key ]['browserRequirements'] = 'Requires JavaScript';
        }
    }

    $home = trailingslashit( home_url( '/' ) );

      $has_faq = false;
    foreach ( $data as $n ) {
        if ( is_array( $n ) && isset( $n['@type'] ) && in_array( 'FAQPage', (array) $n['@type'], true ) ) {
            $has_faq = true;
            break;
        }
    }
    $faqs = $has_faq ? array() : gpa_home_faq_entities();
    if ( count( $faqs ) >= 2 ) {
        $data['gpaHomeFaq'] = array(
            '@type'      => 'FAQPage',
            '@id'        => $home . '#faq',
            'mainEntity' => $faqs,
        );
    }

    $steps = gpa_home_howto_steps();
    if ( count( $steps ) >= 2 ) {
        $data['gpaHomeHowTo'] = array(
            '@type' => 'HowTo',
            '@id'   => $home . '#howto',
            'name'  => 'How to Calculate GPA',
            'step'  => $steps,
        );
    }

    return $data;
}

add_filter( 'body_class', 'gpa_content_slug_body_class', 20 );
function gpa_content_slug_body_class( $classes ) {
    if ( ! is_page() ) {
        return $classes;
    }
    if ( 'gpa-content-page' !== get_page_template_slug() ) {
        return $classes;
    }
    if ( ! in_array( 'content-page', $classes, true ) ) {
        return $classes;
    }
    if ( ! in_array( 'gpa-template-content', $classes, true ) ) {
        $classes[] = 'gpa-template-content';
    }
    return $classes;
}

function gpa_is_content_hero_page() {
    if ( ! is_page() || is_front_page() ) {
        return false;
    }
    $tpl = get_page_template_slug();
    return ( 'page-templates/template-content.php' === $tpl || 'gpa-content-page' === $tpl );
}

add_filter( 'body_class', 'gpa_content_hero_band_class', 30 );
function gpa_content_hero_band_class( $classes ) {
    if ( gpa_is_content_hero_page() || is_singular( 'post' ) ) {
        $classes[] = 'gpa-hero-band';
    }
    return $classes;
}
add_filter( 'generate_schema_type', '__return_false' );

add_filter( 'rank_math/json_ld', 'gpa_heading_faq_schema', 102, 2 );
function gpa_heading_faq_schema( $data, $jsonld ) {

	if ( ! is_singular() || is_front_page() ) {
		return $data;
	}

	if ( isset( $data['FAQPage'] ) ) {
		return $data;
	}
	foreach ( $data as $node ) {
		if ( ! is_array( $node ) || ! isset( $node['@type'] ) ) {
			continue;
		}
		if ( in_array( 'FAQPage', (array) $node['@type'], true ) ) {
			return $data;
		}
	}

	$post = get_post();
	if ( ! $post || '' === trim( $post->post_content ) ) {
		return $data;
	}
	// Pages with a Rank Math FAQ block get their FAQPage from Rank Math; never output a second one.
	if ( has_block( 'rank-math/faq-block', $post ) ) {
		return $data;
	}

	$items = gpa_heading_faq_items( $post->post_content );
	if ( count( $items ) < 2 ) {
		return $data;
	}

	$faq = array(
		'@type'      => 'FAQPage',
		'@id'        => get_permalink( $post ) . '#faq',
		'mainEntity' => $items,
	);

	if ( isset( $data['WebPage']['@id'] ) ) {
		$faq['isPartOf']         = array( '@id' => $data['WebPage']['@id'] );
		$faq['mainEntityOfPage'] = array( '@id' => $data['WebPage']['@id'] );
	}

	$data['FAQPage'] = $faq;

	return $data;
}

function gpa_heading_faq_items( $content ) {

	$prev = libxml_use_internal_errors( true );
	$doc  = new DOMDocument();
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $content . '</div>' );
	libxml_clear_errors();
	libxml_use_internal_errors( $prev );

	$xpath = new DOMXPath( $doc );

	$lower = 'translate( ., "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz" )';
	$heads = $xpath->query( '//h2[ contains( ' . $lower . ', "frequently asked question" ) ]' );

	if ( ! $heads || 0 === $heads->length ) {
		return array();
	}

	$start    = $heads->item( 0 );
	$items    = array();
	$question = '';
	$answer   = array();

	foreach ( $xpath->query( 'following-sibling::*', $start ) as $node ) {

		$tag = strtolower( $node->nodeName );

		if ( 'h2' === $tag ) {
			break;
		}

		if ( 'h3' === $tag ) {
			if ( '' !== $question && ! empty( $answer ) ) {
				$items[] = gpa_faq_entity( $question, implode( ' ', $answer ) );
			}
			$question = trim( preg_replace( '/\s+/', ' ', $node->textContent ) );
			$answer   = array();
			continue;
		}

		if ( '' === $question ) {
			continue;
		}

		if ( in_array( $tag, array( 'p', 'ul', 'ol' ), true ) ) {
			$text = wp_strip_all_tags( $doc->saveHTML( $node ) );
			$text = trim( preg_replace( '/\s+/', ' ', $text ) );
			if ( '' !== $text ) {
				$answer[] = $text;
			}
		}
	}

	if ( '' !== $question && ! empty( $answer ) ) {
		$items[] = gpa_faq_entity( $question, implode( ' ', $answer ) );
	}

	return $items;
}

function gpa_faq_entity( $question, $answer ) {
	return array(
		'@type'          => 'Question',
		'name'           => $question,
		'acceptedAnswer' => array(
			'@type' => 'Answer',
			'text'  => $answer,
		),
	);
}

add_action( 'wp_footer', 'gpa_freestar_cmp_link', 20 );
function gpa_freestar_cmp_link() {

	if ( is_admin() ) {
		return;
	}
	?>
	<style>
		.gpa-cmp-link { text-align: center; padding: 0 0 18px; }
		#pmLink { visibility: hidden; text-decoration: none; cursor: pointer; background: transparent; border: none; font: inherit; padding: 0; }
		#pmLink:hover { visibility: visible; color: grey; }
	</style>
	<div class="gpa-cmp-link"><button id="pmLink">Privacy Manager</button></div>
	<?php
}

function gpa_freestar_slots() {
	return array(
		'incontent_bottom'     => '__240x400 __336x280',
		'incontent_midarticle' => '__240x400 __336x280',
		'siderail_right_1'     => '__336x600',
		'siderail_right_2'     => '__300x600',
		'siderail_right_3'     => '__300x600',
		'kargo_spotlight'      => '',
	);
}

add_shortcode( 'gpa_ad', 'gpa_freestar_ad_shortcode' );
function gpa_freestar_ad_shortcode( $atts ) {
	$atts = shortcode_atts( array( 'slot' => '' ), $atts, 'gpa_ad' );
	return gpa_freestar_ad_markup( $atts['slot'] );
}

function gpa_freestar_ad_markup( $slot ) {

	static $rendered = array();

	$slots = gpa_freestar_slots();
	$slot  = sanitize_key( $slot );

	if ( ! isset( $slots[ $slot ] ) || isset( $rendered[ $slot ] ) ) {
		return '';
	}
	if ( is_admin() || is_feed() || is_embed() ) {
		return '';
	}

	$rendered[ $slot ] = true;

	$id   = 'gpacalculator-net_' . $slot;
	$size = $slots[ $slot ];

	$html  = '<div align="center"';
	if ( '' !== $size ) {
		$html .= ' data-freestar-ad="' . esc_attr( $size ) . '"';
	}
	$html .= ' id="' . esc_attr( $id ) . '">';
	$html .= '<script data-cfasync="false" type="text/javascript">';
	$html .= 'freestar.config.enabled_slots.push({ placementName: "' . esc_js( $id ) . '", slotId: "' . esc_js( $id ) . '" });';
	$html .= '</' . 'script></div>';

	return $html;
}

add_filter( 'the_content', 'gpa_freestar_incontent_ads', 25 );
function gpa_freestar_incontent_ads( $content ) {

	if ( ! is_singular() || ! is_main_query() || doing_filter( 'get_the_excerpt' ) ) {
		return $content;
	}
	if ( ! apply_filters( 'gpa_freestar_auto_incontent', true ) ) {
		return $content;
	}

	if ( preg_match_all( '/<h2[\s>]/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {

		$middle = strlen( $content ) / 2;
		$best   = null;

		foreach ( $m[0] as $hit ) {
			if ( null === $best || abs( $hit[1] - $middle ) < abs( $best - $middle ) ) {
				$best = $hit[1];
			}
		}

		if ( null !== $best && $best > strlen( $content ) / 5 ) {
			$ad = gpa_freestar_ad_markup( 'incontent_midarticle' );
			if ( '' !== $ad ) {
				$content = substr( $content, 0, $best ) . $ad . substr( $content, $best );
			}
		}
	}

	return $content . gpa_freestar_ad_markup( 'incontent_bottom' );
}

if ( ! function_exists( 'get_field' ) ) {
	function get_field( $selector, $post_id = false, $format_value = true ) {
		if ( false === $post_id || null === $post_id || '' === $post_id ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id || ! is_numeric( $post_id ) ) {
			return false;
		}
		$value = get_post_meta( (int) $post_id, (string) $selector, true );
		return ( '' === $value ) ? false : $value;
	}
}

// Side rails V6 (2026-10-02): tiered rails that match the Freestar siderail size mapping and the column tiers in style.css.

/**
 * Freestar placements for the side rails, per side and rail tier (rail width in px): the first id always, the second
 * when the rail is tall enough for two 600px ads.
 * Freestar sizeMapping (fsdata.json v158, checked 2026-10-02): siderail_left_1/2/3 use the target tiers
 * (1260 = 160x600/120x600, 1350 = up to 300 wide, 1440 = up to 336 wide). siderail_right_1/2/3 still use
 * 1000/1349/1439/1440: at 1260-1348 they would ask for 300-336px ads in a 160px rail, but from 1350 up they never
 * ask for more than the rail holds. So the right rail keeps the established right_1 (+ right_3) from 1350 up and
 * uses left_3 only in the 160 tier. When Freestar corrects right_1/right_2, set every 'right' tier to right_1, right_2.
 */
function gpa_rail_placements() {
	// Digant 2026-10-03: left rail = left_1 + left_2, right rail = right_1 + right_2. At 1260-1349 (160px rails)
	// right_1/2's mapping would ask for 300-336px ads, so until Freestar fixes it the right rail there uses left_3 twice:
	// the second copy is the same placement under its own slot id ("--2"; the script requests placementName = id before "--").
	$left = array( 'gpacalculator-net_siderail_left_1', 'gpacalculator-net_siderail_left_2' );
	$right = array( 'gpacalculator-net_siderail_right_1', 'gpacalculator-net_siderail_right_2' );
	return array(
		'left'  => array( 160 => $left, 300 => $left, 336 => $left ),
		'right' => array( 160 => array( 'gpacalculator-net_siderail_left_3', 'gpacalculator-net_siderail_left_3--2' ), 300 => $right, 336 => $right ),
	);
}

add_action( 'wp_footer', 'gpa_freestar_siderails', 20 );
function gpa_freestar_siderails() {
	if ( is_admin() || is_feed() || is_404() || is_search() ) {
		return;
	}
	// Slots registered at init (unchanged): used for Kargo Spotlight.
	$tag = function ( $id, $size ) {
		$attr = $size ? ' data-freestar-ad="' . esc_attr( $size ) . '"' : '';
		return '<div align="center"' . $attr . ' id="' . esc_attr( $id ) . '">'
			. '<script data-cfasync="false" type="text/javascript">freestar.config.enabled_slots.push({ placementName: "' . esc_js( $id ) . '", slotId: "' . esc_js( $id ) . '" });</script>'
			. '</div>';
	};
	$placements = gpa_rail_placements();

	echo "\n<!-- Freestar side rails (GPA_RAILS_V6) -->\n";
	foreach ( array( 'left', 'right' ) as $side ) {
		echo '<div class="gpa-rail gpa-rail--' . $side . '">';
		foreach ( array_unique( call_user_func_array( 'array_merge', array_reverse( array_values( $placements[ $side ] ) ) ) ) as $id ) { // widest tier first: its order is the stacking order
			echo '<div class="gpa-rail__seg" style="display:none"><div class="gpa-rail__sticky"><div align="center" data-freestar-ad="__300x600" id="' . esc_attr( $id ) . '"></div></div></div>';
		}
		echo "</div>\n";
	}
// echo "\n<!-- Tag ID: gpacalculator-net_kargo_spotlight -->\n" . $tag( 'gpacalculator-net_kargo_spotlight', '' ) . "\n";
	?>
<script data-cfasync="false">
(function () {
	var PLACEMENTS = <?php echo wp_json_encode( $placements ); ?>; // edit in gpa_rail_placements() (functions.php)
	// Same breakpoints as GPA_COLUMN_TIERS in style.css and the siderail size mapping.
	var TIERS = [
		{ w: 336, mq: window.matchMedia('(min-width: 1440px)') },
		{ w: 300, mq: window.matchMedia('(min-width: 1350px)') },
		{ w: 160, mq: window.matchMedia('(min-width: 1260px)') }
	];
	var GAP_MIN = 20, GAP_MAX = 56, EDGE = 8, SEG2_MIN = 1240, AD_H = 600; // two 600px ads + spacing
	var rails = { left: document.querySelector('.gpa-rail--left'), right: document.querySelector('.gpa-rail--right') };
	if (!rails.left || !rails.right) { return; }
	var body = document.body;
	var header = document.querySelector('.entry-header');
	var siteHeader = document.querySelector('.site-header');
	var footer = document.querySelector('.site-footer');
	var heroes = ['.gpa-hero', '.db-hero', '.db-archive-hero'].map(function (s) { return document.querySelector(s); }).filter(Boolean);
	var cols = ['.db-container', '.entry-content', '#content'].map(function (s) { return document.querySelector(s); }).filter(Boolean);
	var afterExtra = null, state = '', raf = 0, t = 0, requested = {}, curTier = null, loadTier = null, tierSent = false;

	function tierNow() {
		for (var i = 0; i < TIERS.length; i++) { if (TIERS[i].mq.matches) { return TIERS[i].w; } }
		return 0;
	}

	// READ phase only.
	function measure(w) {
		var de = document.documentElement, vw = de.clientWidth, y = window.pageYOffset, c = null, r, i;
		for (i = 0; i < cols.length; i++) {
			r = cols[i].getBoundingClientRect();
			if (r.width && r.width < vw - 100) { c = r; break; }
		}
		if (!c) { return null; }
		var b = 0;
		if (header) {
			r = header.getBoundingClientRect();
			if (afterExtra === null) {
				var cs = getComputedStyle(header, '::after');
				afterExtra = cs.position === 'absolute' ? (parseFloat(cs.top) || 0) + (parseFloat(cs.height) || 0) : 0;
			}
			if (r.height) { b = r.top + y + Math.max(r.height, afterExtra); }
		}
		for (i = 0; i < heroes.length; i++) {
			r = heroes[i].getBoundingClientRect();
			if (r.height) { b = Math.max(b, r.bottom + y); }
		}
		if (!b && siteHeader) { b = siteHeader.getBoundingClientRect().bottom + y; }
		var top = b + 24;
		// Stop 24px above the bottom in-content ad (never beside it), else above the footer.
		var stop = document.getElementById('gpacalculator-net_incontent_bottom') || footer;
		var bottom = stop ? stop.getBoundingClientRect().top + y - 24 : de.scrollHeight - 24;
		var h = bottom - top;
		if (h < AD_H) { return null; }
		// Space beside the column: the rail must fit at its tier width, with at least GAP_MIN to the column and EDGE to the screen edge.
		// The column tiers leave exactly GAP_MIN beside a full-width rail, so allow 1px for subpixel layout (zoom, odd widths);
		// a strict check hid one side at random.
		var gl = Math.min(GAP_MAX, c.left - EDGE - w), gr = Math.min(GAP_MAX, vw - c.right - EDGE - w), ok = GAP_MIN - 1;
		return {
			top: Math.round(top), h: Math.round(h), two: h >= SEG2_MIN,
			left: gl >= ok ? Math.round(window.pageXOffset + c.left - gl - w) : null,
			right: gr >= ok ? Math.round(window.pageXOffset + c.right + gr) : null
		};
	}

	function want(side, p, w) { // placement ids a rail should hold now
		if (!p || p[side] === null || !PLACEMENTS[side][w]) { return []; }
		return PLACEMENTS[side][w].slice(0, p.two ? 2 : 1);
	}
	// Request newly shown slots. Slots are deleted only on a tier change (reset): a rail hidden for a moment while the
	// page loads keeps its ad, since deleting and re-requesting it left rails blank.
	function sync(ids, reset) {
		var add = ids.filter(function (id) { return !requested[id]; });
		var del = reset ? Object.keys(requested) : [];
		if (!window.freestar || (!add.length && !del.length)) { return; }
		if (reset) { requested = {}; }
		add.forEach(function (id) { requested[id] = 1; });
		freestar.queue.push(function () {
			if (del.length) { freestar.deleteAdSlots(del); }
			if (add.length) { freestar.newAdSlots(add.map(function (id) { return { placementName: id.split('--')[0], slotId: id }; })); }
		});
	}
	function sendTier(w) {
		if (tierSent) { return; }
		tierSent = true;
		var tier = w ? String(w) : 'none';
		if (window.gtag) { gtag('event', 'rail_tier', { tier: tier }); }
		else { (window.dataLayer = window.dataLayer || []).push(['event', 'rail_tier', { tier: tier }]); }
	}

	// WRITE phase.
	function apply() {
		raf = 0;
		var w = tierNow();
		sendTier(w);
		if (curTier !== null && w !== curTier) { sync([], true); } // tier changed: drop every slot, re-request for the new tier
		curTier = w;
		if (loadTier === null) { loadTier = w; }
		// Freestar fixes a slot's sizes at page load (re-created slots keep the load-time size list), so after a resize
		// only re-request when the new tier is at least as wide as the load tier; a narrower tier hides the rails until reload.
		var p = w && loadTier && w >= loadTier ? measure(w) : null; // loaded with no rails: none until reload
		var key = p ? [w, p.top, p.h, p.two, p.left, p.right].join() : 'off';
		if (key === state) { return; }
		state = key;
		var ids = [];
		['left', 'right'].forEach(function (side) {
			var el = rails[side], show = !!(p && p[side] !== null);
			el.style.display = show ? 'flex' : 'none';
			if (!show) { return; }
			el.style.setProperty('--gpa-rail-w', w + 'px');
			el.style.top = p.top + 'px';
			el.style.height = p.h + 'px';
			el.style.left = p[side] + 'px';
			var mine = want(side, p, w);
			// show only this tier's slots (markup order = placement order; never move a slot, that reloads its ad) and tag each with the tier size
			[].forEach.call(el.children, function (seg) {
				var slot = seg.querySelector('[id^="gpacalculator-net_siderail"]');
				var on = !!slot && mine.indexOf(slot.id) >= 0;
				seg.style.display = on ? '' : 'none';
				if (on) { slot.setAttribute('data-freestar-ad', '__' + w + 'x600'); }
			});
			ids = ids.concat(mine);
		});
		body.classList.toggle('gpa-rails-on', !!p && (p.left !== null || p.right !== null));
		sync(ids);
	}

	function schedule() { if (!raf) { raf = requestAnimationFrame(apply); } }
	function later() { clearTimeout(t); t = setTimeout(schedule, 200); }

	schedule();
	window.addEventListener('resize', later, { passive: true });
	window.addEventListener('load', schedule);
	TIERS.forEach(function (x) { if (x.mq.addEventListener) { x.mq.addEventListener('change', schedule); } });
	if (window.ResizeObserver) {
		var ro = new ResizeObserver(later);
		cols.concat(heroes, header ? [header] : [], footer ? [footer] : []).forEach(function (el) { ro.observe(el); });
		ro.observe(document.body);
	}
})();
</script>
	<?php
}

add_action( 'wp_head', function () {
    if ( is_page_template( 'template-calculator.php' ) ) {
        printf(
            '<style>:root{--updated-year:%d;}</style>',
            (int) wp_date( 'Y' )
        );
    }
} );

/**
 * Remove unused legacy scripts sitewide:
 * - jQuery UI 1.11.2 + its CSS (nothing on the site uses it)
 * - the old CollegeDB plugin's AngularJS app + CSS (loaded from the CollegeDB.disabled folder;
 *   the admissions pages now run on database-ajax.js and have no Angular markup)
 * jQuery core drops out automatically once nothing depends on it.
 */
add_action( 'wp_enqueue_scripts', 'gpa_dequeue_legacy_scripts', 100 );
function gpa_dequeue_legacy_scripts() {
	foreach ( array( 'jquery-ui', 'college-db' ) as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
	foreach ( array( 'jquery-ui', 'cdb_main' ) as $handle ) {
		wp_dequeue_style( $handle );
		wp_deregister_style( $handle );
	}
}
/**
 * Prerendered calculators: print the calculator's starting HTML into <div id="root"></div>.
 */
add_action( 'template_redirect', 'gpa_prerender_calculator_start', 1 );
function gpa_prerender_calculator_start() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! is_singular() && ! is_front_page() ) {
		return;
	}
	ob_start( 'gpa_prerender_calculator_inject' );
}

function gpa_prerender_calculator_inject( $html ) {
	$empty_root = '<div id="root"></div>';
	$pos        = strpos( $html, $empty_root );
	if ( false === $pos ) {
		return $html;
	}
	$name = '';
	global $wp_scripts;
	if ( $wp_scripts instanceof WP_Scripts ) {
		foreach ( (array) $wp_scripts->done as $handle ) {
			$src = isset( $wp_scripts->registered[ $handle ] ) ? (string) $wp_scripts->registered[ $handle ]->src : '';
			if ( preg_match( '#/calc-assets/([a-z0-9-]+)\.js#', $src, $m ) ) {
				$name = $m[1];
				break;
			}
		}
	}
	if ( '' === $name && preg_match( '#/calc-assets/([a-z0-9-]+)\.js#', $html, $m ) ) {
		$name = $m[1];
	}
	if ( '' === $name ) {
		return $html;
	}
	$file = get_stylesheet_directory() . '/calc-assets/' . $name . '.html';
	if ( ! is_readable( $file ) ) {
		return $html;
	}
	$markup = file_get_contents( $file );
	if ( ! $markup ) {
		return $html;
	}
	return substr_replace( $html, '<div id="root">' . $markup . '</div>', $pos, strlen( $empty_root ) );
}
/**
 * Default schema for EVERY page that uses the "GPA – Calculator Tool" template
 * (the university calculators, the grade calculators, the converters, etc.).
 * It runs after the other schema filters in this file and only adjusts what they already output.
 *  1. Breadcrumb from the page hierarchy: Home > (parent pages) > this page.
 *  2. Page node (WebPage, or the FAQPage that stands in for it): adds a description and points to its breadcrumb.
 *  3. Calculator (WebApplication): adds an @id, "free to use", the language and the publisher,
 *     and fills the description from the Rank Math description if it is empty.
 * Nothing here invents data: no ratings, no reviews, no names that are not already on the page.
 */
add_filter( 'rank_math/json_ld', 'gpa_calc_template_schema', 130, 2 );
function gpa_calc_template_schema( $data, $jsonld ) {
	if ( ! is_array( $data ) || ! function_exists( 'gpa_is_calculator_tool_page' ) || ! gpa_is_calculator_tool_page() ) {
		return $data;
	}
	$post = get_queried_object();
	if ( ! ( $post instanceof WP_Post ) ) {
		return $data;
	}

	// Find a node by its @type (a node can have several types, e.g. WebPage + FAQPage).
	$find = function ( $type ) use ( &$data ) {
		foreach ( $data as $key => $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && in_array( $type, (array) $node['@type'], true ) ) {
				return $key;
			}
		}
		return null;
	};
	$clean = function ( $text ) {
		return wp_specialchars_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES );
	};

	$url    = get_permalink( $post );
	$bc_id  = $url . '#breadcrumb';
	$app_id = $url . '#webapplication';

	// 1) Breadcrumb trail from the page hierarchy (parents first).
	$items = array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ) );
	$pos   = 2;
	foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => $clean( get_the_title( $ancestor_id ) ),
			'item'     => get_permalink( $ancestor_id ),
		);
	}
	$items[] = array( '@type' => 'ListItem', 'position' => $pos, 'name' => $clean( get_the_title( $post ) ), 'item' => $url );
	$bc_key          = $find( 'BreadcrumbList' );
	$bc_key          = null !== $bc_key ? $bc_key : 'BreadcrumbList';
	$data[ $bc_key ] = array( '@type' => 'BreadcrumbList', '@id' => $bc_id, 'itemListElement' => $items );

	$meta_desc = (string) get_post_meta( $post->ID, 'rank_math_description', true );

	// 2) The calculator (WebApplication).
	$app_key  = $find( 'WebApplication' );
	$app_desc = '';
	if ( null !== $app_key ) {
		if ( empty( $data[ $app_key ]['@id'] ) ) {
			$data[ $app_key ]['@id'] = $app_id;
		}
		$data[ $app_key ]['isAccessibleForFree'] = 'True';
		if ( empty( $data[ $app_key ]['inLanguage'] ) ) {
			$data[ $app_key ]['inLanguage'] = get_bloginfo( 'language' );
		}
		if ( empty( $data[ $app_key ]['publisher'] ) ) {
			$org_key = $find( 'Organization' );
			if ( null !== $org_key && ! empty( $data[ $org_key ]['@id'] ) ) {
				$data[ $app_key ]['publisher'] = array( '@id' => $data[ $org_key ]['@id'] );
			}
		}
		if ( empty( $data[ $app_key ]['description'] ) && '' !== $meta_desc ) {
			$data[ $app_key ]['description'] = $clean( $meta_desc );
		}
		$app_desc = isset( $data[ $app_key ]['description'] ) ? (string) $data[ $app_key ]['description'] : '';
	}

	// 3) The page node (never touches mainEntity, which holds the FAQ on many pages).
	$wp_key = $find( 'WebPage' );
	if ( null === $wp_key ) {
		// On some pages the FAQPage node is the page node (FAQPage is a kind of WebPage).
		$maybe = $find( 'FAQPage' );
		if ( null !== $maybe && isset( $data[ $maybe ]['@id'] ) && '#webpage' === substr( (string) $data[ $maybe ]['@id'], -8 ) ) {
			$wp_key = $maybe;
		}
	}
	if ( null !== $wp_key ) {
		$data[ $wp_key ]['breadcrumb'] = array( '@id' => $bc_id );
		if ( empty( $data[ $wp_key ]['description'] ) ) {
			$desc = '' !== $app_desc ? $app_desc : $meta_desc;
			if ( '' !== $desc ) {
				$data[ $wp_key ]['description'] = $clean( $desc );
			}
		}
	}

	return $data;
}
/**
 * Final-output schema cleanup (replaces the earlier gpa_schema_cleanup block).
 * Rank Math stringifies and HTML-escapes values AFTER every rank_math/json_ld filter has run,
 * so filters can't fix them. This cleans the finished <script type="application/ld+json"> instead:
 *  - decodes &amp; etc. in text fields and URLs (e.g. the author's Gravatar URL)
 *  - fills a missing description on WebPage / WebApplication from the page's meta description
 *  - position -> integer, isAccessibleForFree -> true/false
 *  - drops a FAQPage nested in subjectOf when the graph already has another FAQPage
 */
add_action( 'template_redirect', 'gpa_schema_output_start', 2 );
function gpa_schema_output_start() {
	if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( 'gpa_schema_output_cb' );
}

function gpa_schema_output_cb( $html ) {
	if ( false === strpos( $html, 'application/ld+json' ) ) {
		return $html;
	}
	$meta_desc = '';
	if ( preg_match( '#<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)["\']#i', $html, $dm ) ) {
		$meta_desc = trim( html_entity_decode( $dm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
	return preg_replace_callback(
		'#(<script[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
		function ( $m ) use ( $meta_desc ) {
			$json = json_decode( $m[2], true );
			if ( ! is_array( $json ) ) {
				return $m[0];
			}
			$json = gpa_schema_final_walk( $json, gpa_schema_count_faq( $json ) > 1, $meta_desc );
			$out  = wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			return $out ? $m[1] . $out . $m[3] : $m[0];
		},
		$html
	);
}

function gpa_schema_count_faq( $node ) {
	$count = 0;
	if ( is_array( $node ) ) {
		if ( isset( $node['@type'] ) && in_array( 'FAQPage', (array) $node['@type'], true ) ) {
			$count++;
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				$count += gpa_schema_count_faq( $value );
			}
		}
	}
	return $count;
}

function gpa_schema_final_walk( $node, $drop_nested_faq, $meta_desc = '' ) {
	if ( ! is_array( $node ) ) {
		return $node;
	}

	if ( $drop_nested_faq && isset( $node['subjectOf'] ) && is_array( $node['subjectOf'] ) ) {
		$is_faq = function ( $item ) {
			return is_array( $item ) && isset( $item['@type'] ) && in_array( 'FAQPage', (array) $item['@type'], true );
		};
		if ( isset( $node['subjectOf']['@type'] ) ) {
			if ( $is_faq( $node['subjectOf'] ) ) {
				unset( $node['subjectOf'] );
			}
		} else {
			$node['subjectOf'] = array_values( array_filter( $node['subjectOf'], function ( $i ) use ( $is_faq ) {
				return ! $is_faq( $i );
			} ) );
			if ( empty( $node['subjectOf'] ) ) {
				unset( $node['subjectOf'] );
			}
		}
	}

	if ( '' !== $meta_desc && isset( $node['@type'] ) && empty( $node['description'] )
		&& ( in_array( 'WebPage', (array) $node['@type'], true ) || in_array( 'WebApplication', (array) $node['@type'], true ) ) ) {
		$node['description'] = $meta_desc;
	}

	foreach ( $node as $key => $value ) {
		if ( is_array( $value ) ) {
			$node[ $key ] = gpa_schema_final_walk( $value, $drop_nested_faq, $meta_desc );
		} elseif ( is_string( $value ) && in_array( $key, array( 'name', 'headline', 'description', 'text', 'caption', 'url', '@id', 'contentUrl', 'image', 'item' ), true ) ) {
			$node[ $key ] = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} elseif ( 'position' === $key && is_numeric( $value ) ) {
			$node[ $key ] = (int) $value;
		} elseif ( 'isAccessibleForFree' === $key && in_array( strtolower( (string) $value ), array( '1', '0', 'true', 'false' ), true ) ) {
			$node[ $key ] = in_array( strtolower( (string) $value ), array( '1', 'true' ), true );
		}
	}

	return $node;
}
add_filter('script_loader_tag', function ($tag, $handle) {
  if (strpos($handle, 'main-js-') === 0) $tag = str_replace('<script ', '<script type="module" ', $tag);
  return $tag;
}, 10, 2);

// GA4 heartbeat: sends a ping every 15s while the tab is visible (max 30 min) so GA records engagement time reliably.
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
<script data-cfasync="false">
(function () {
	var INTERVAL = 15000, MAX = 120, n = 0, t = null;
	function stop() { if (t) { clearInterval(t); t = null; } }
	function beat() {
		if (document.visibilityState !== 'visible' || typeof window.gtag !== 'function') { return; }
		if (++n > MAX) { stop(); return; }
		window.gtag('event', 'heartbeat');
	}
	function start() { if (!t && n < MAX) { t = setInterval(beat, INTERVAL); } }
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'visible') { start(); } else { stop(); }
	});
	if (document.visibilityState === 'visible') { start(); }
})();
</script>
	<?php
}, 30 );


// GA4 calculator events: calculator_used (first grade pick or edit per page view), course_added, semester_added, planner_opened.
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
<script data-cfasync="false">
(function () {
	var CALC = '#root, .gpa-calc-portal, #middle-school-gpa';
	var used = false;
	function send(name) {
		if (typeof window.gtag === 'function') { window.gtag('event', name); }
	}
	document.addEventListener('change', function (e) {
		if (used || !e.target.closest || !e.target.closest(CALC)) { return; }
		used = true;
		send('calculator_used');
	}, true);
	document.addEventListener('click', function (e) {
		var b = e.target.closest && e.target.closest('button');
		if (!b || !b.closest(CALC)) { return; }
		var t = b.textContent.replace(/\s+/g, ' ').trim();
		if (t === 'Add class') { send('course_added'); }
		else if (t === 'Add semester / year') { send('semester_added'); }
		else if (t.indexOf('Open planner') === 0) { send('planner_opened'); }
	}, true);
})();
</script>
	<?php
}, 30 );

/**
 * CollegeDB placeholders. The CollegeDB plugin is retired; its [CollegeDB gpa="x.x"] tables on the
 * /gpa-scale/ pages will be rebuilt from Scorecard/CDS data. Until then these tags print nothing,
 * so no page shows raw shortcode text. Only registered when the plugin isn't active.
 */
add_action( 'init', function () {
	if ( ! shortcode_exists( 'CollegeDB' ) ) {
		add_shortcode( 'CollegeDB', '__return_empty_string' );
	}
	if ( ! shortcode_exists( 'CollegeDB_full' ) ) {
		add_shortcode( 'CollegeDB_full', '__return_empty_string' );
	}
}, 20 );

/**
 * GPA scale table on the /gpa-scale/<x-x>-gpa/ pages: a core Table block with the class gpa-scale-table
 * (columns: GPA, Percentage, Letter grade). Marks the row whose GPA matches the page (from the slug, e.g.
 * 3-6-gpa -> 3.6) with is-current-gpa and aria-current, and tags every row with its grade band
 * (is-band-a … is-band-f) for colour. Works on whatever rows the editor keeps; no per-row editing needed.
 */
add_filter( 'render_block', 'gpa_scale_table_mark_rows', 10, 2 );
function gpa_scale_table_mark_rows( $html, $block ) {
	if ( 'core/table' !== $block['blockName'] || false === strpos( $html, 'gpa-scale-table' ) ) {
		return $html;
	}
	// The page's own GPA is marked beside the chart on its nearest letter's row (both rows on a tie, both 4.0
	// rows for a 4.0), using the site-wide rule gpa_scale_figures(); rows carry data-letter so the weighted
	// view (gpa-scale-tools.js) can move the mark.
	$marks = array();
	$mark  = '';
	$slug  = is_singular() ? (string) get_post_field( 'post_name', get_queried_object_id() ) : '';
	if ( preg_match( '#^([0-4])-([0-9])-gpa$#', $slug, $m ) && function_exists( 'gpa_scale_figures' ) ) {
		$gs = $m[1] . '.' . $m[2];
		list( $letter, $pct ) = gpa_scale_figures( $gs );
		$marks = explode( '/', $letter );
		if ( '4.0' === $gs ) {
			$marks[] = 'A+';
		}
		$mark = 'Your ' . $gs . ' · ' . $pct;
	}
	return preg_replace_callback(
		'#<tr>(.*?)</tr>#s',
		function ( $row ) use ( $marks, $mark ) {
			if ( ! preg_match_all( '#<td\b[^>]*>(.*?)</td>#s', $row[1], $cells ) || count( $cells[1] ) < 3 ) {
				return $row[0]; // header row or unexpected shape
			}
			$letter_full = trim( wp_strip_all_tags( end( $cells[1] ) ) );
			$letter      = strtolower( substr( $letter_full, 0, 1 ) );
			$class       = in_array( $letter, array( 'a', 'b', 'c', 'd', 'f' ), true ) ? 'is-band-' . $letter : '';
			$attrs       = ' data-letter="' . esc_attr( $letter_full ) . '"';
			$inner       = $row[1];
			if ( $marks && in_array( $letter_full, $marks, true ) ) {
				$class .= ' is-current-gpa';
				$attrs .= ' aria-current="true"';
				$inner  = preg_replace( '#<td\b#', '<td data-mark="' . esc_attr( $mark ) . '"', $inner, 1 );
			}
			return '<tr class="' . esc_attr( trim( $class ) ) . '"' . $attrs . '>' . $inner . '</tr>';
		},
		$html
	);
}

/**
 * /gpa-scale/<x-x>-gpa/ pages: "Updated <date>" under the hero intro, and prev / next links to the
 * neighbouring GPA pages plus the /gpa-scale/ hub at the end of the content.
 */
function gpa_scale_page_gpa() {
	if ( ! is_page() ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || ! preg_match( '#^([0-4])-([0-9])-gpa$#', $post->post_name, $m ) ) {
		return null;
	}
	$parent = $post->post_parent ? get_post( $post->post_parent ) : null;
	if ( ! $parent || 'gpa-scale' !== $parent->post_name ) {
		return null;
	}
	return array( (int) $m[1] * 10 + (int) $m[2], $parent );
}

add_action( 'generate_after_page_title', 'gpa_scale_updated_date', 20 );
function gpa_scale_updated_date() {
	if ( ! gpa_scale_page_gpa() ) {
		return;
	}
	printf(
		'<p class="gpa-updated">Updated <time datetime="%s">%s</time></p>',
		esc_attr( get_the_modified_date( 'c' ) ),
		esc_html( get_the_modified_date( 'F j, Y' ) )
	);
}

add_filter( 'the_content', 'gpa_scale_page_nav', 20 );
function gpa_scale_page_nav( $content ) {
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$found = gpa_scale_page_gpa();
	if ( ! $found ) {
		return $content;
	}
	list( $tenths, $hub ) = $found;
	$link = function ( $t, $label_fmt ) use ( $hub ) {
		if ( $t < 10 || $t > 40 ) {
			return '';
		}
		$slug = intdiv( $t, 10 ) . '-' . ( $t % 10 ) . '-gpa';
		$page = get_page_by_path( $hub->post_name . '/' . $slug );
		if ( ! $page || 'publish' !== $page->post_status ) {
			return '';
		}
		$gpa = sprintf( '%d.%d', intdiv( $t, 10 ), $t % 10 );
		return sprintf( $label_fmt, esc_url( get_permalink( $page ) ), esc_html( $gpa ) );
	};
	$prev = $link( $tenths - 1, '<a class="gpa-scale-nav__prev" href="%1$s" rel="prev"><span aria-hidden="true">&larr;</span> %2$s GPA</a>' );
	$next = $link( $tenths + 1, '<a class="gpa-scale-nav__next" href="%1$s" rel="next">%2$s GPA <span aria-hidden="true">&rarr;</span></a>' );
	$hub_link = sprintf( '<a class="gpa-scale-nav__hub" href="%s">All GPA scale pages</a>', esc_url( get_permalink( $hub ) ) );
	return $content . '<nav class="gpa-scale-nav" aria-label="Other GPA values">' . $prev . $hub_link . $next . '</nav>';
}

/**
 * 404 guessing: only redirect to a post whose slug matches exactly.
 *
 * WordPress's default guess redirects a missing address to any published post whose slug starts with the same
 * text. After checkpoint C retired colleges with a 410 rule in Rank Math, which sets the status but lets the request
 * run on, /admissions/remington-college/ was still sent to remington-college-baton-rouge-campus, and
 * /admissions/auburn-university/ to auburn-university-at-montgomery. Exact matches keep working.
 */
add_filter( 'strict_redirect_guess_404_permalink', '__return_true' );

/**
 * Design overhaul phase 4: logo = "4.0" badge (inline SVG, colors from
 * layout.css tokens) + live wordmark "GPA Calculator" with "GPA" in the
 * primary blue. The site title text stays a real link for crawlers.
 */
function gpa_logo_badge_svg() {
	return '<svg class="gpa-logo-badge" viewBox="0 0 36 36" width="36" height="36" aria-hidden="true" focusable="false">'
		. '<defs><linearGradient id="gpa-logo-grad" x1="0" y1="0" x2="1" y2="1">'
		. '<stop offset="0" class="gpa-logo-badge__from"/><stop offset="1" class="gpa-logo-badge__to"/>'
		. '</linearGradient></defs>'
		. '<rect width="36" height="36" rx="9" fill="url(#gpa-logo-grad)"/>'
		. '<text x="18" y="23" text-anchor="middle" font-size="14">4.0</text>'
		. '</svg>';
}

add_filter( 'generate_logo_output', 'gpa_logo_output', 20, 2 );
function gpa_logo_output( $output, $logo_url ) {
	return sprintf(
		'<div class="site-logo"><a href="%1$s" rel="home" aria-label="%2$s">%3$s</a></div>',
		esc_url( apply_filters( 'generate_logo_href', home_url( '/' ) ) ),
		esc_attr( get_bloginfo( 'name', 'display' ) ),
		gpa_logo_badge_svg()
	);
}

add_filter( 'generate_site_title_output', 'gpa_site_title_wordmark', 20 );
function gpa_site_title_wordmark( $output ) {
	return preg_replace( '#(rel="home"[^>]*>)\s*GPA\b#', '$1<span class="gpa-wordmark-accent">GPA</span>', $output, 1 );
}

/**
 * Design overhaul phase 4: Rank Math FAQ blocks become an accordion with the
 * first question open. The answers stay in the HTML (and in the FAQ schema);
 * without JavaScript every answer shows. Styles: components.css section 8.
 */
add_action( 'wp_footer', 'gpa_faq_accordion', 30 );
function gpa_faq_accordion() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}
	?>
<script>
(function () {
	document.querySelectorAll('.entry-content .rank-math-block').forEach(function (block, b) {
		var items = block.querySelectorAll('.rank-math-list-item');
		if (!items.length) { return; }
		items.forEach(function (item, i) {
			var q = item.querySelector('.rank-math-question'), a = item.querySelector('.rank-math-answer');
			if (!q || !a) { return; }
			a.id = a.id || 'gpa-faq-' + b + '-' + i;
			q.setAttribute('role', 'button');
			q.setAttribute('tabindex', '0');
			q.setAttribute('aria-controls', a.id);
			function set(open) { item.classList.toggle('is-open', open); q.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			set(i === 0);
			q.addEventListener('click', function () { set(!item.classList.contains('is-open')); });
			q.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); set(!item.classList.contains('is-open')); }
			});
		});
		block.classList.add('gpa-faq-ready');
	});
})();
</script>
	<?php
}

/**
 * Design overhaul: "On this page" table of contents under the calculator (or above the first numbered section on
 * pages without one), listing exactly the H2s that layout.css section 10 numbers, so the numbers always match.
 * Shown on pages with 4 or more numbered sections; pages that already have a Rank Math TOC block keep theirs.
 * Built in the browser from the numbered headings, so no page content changes. Styles: layout.css section 11.
 */
add_action( 'wp_footer', 'gpa_toc_builder', 31 );
function gpa_toc_builder() {
	if ( is_admin() || ! is_singular() || is_front_page() ) {
		return;
	}
	?>
<script>
(function () {
	var c = document.querySelector('.entry-content');
	if (!c || c.querySelector('.wp-block-rank-math-toc-block')) { return; }
	var toc = document.createElement('div');
	toc.className = 'wp-block-rank-math-toc-block gpa-toc';
	toc.id = 'gpa-toc';
	toc.hidden = true;
	c.insertBefore(toc, c.firstChild);
	var heads = [].filter.call(c.querySelectorAll('h2'), function (h) {
		return /gpa-sec/.test(getComputedStyle(h, '::before').content || '');
	});
	if (heads.length < 4) { toc.remove(); return; }
	var top = function (el) { while (el.parentElement && el.parentElement !== c) { el = el.parentElement; } return el; };
	var root = c.querySelector('#root, .gpacalc-mount, .frm_forms');
	var before = root ? top(root).nextElementSibling : top(heads[0]);
	var used = {};
	var title = document.createElement('p');
	title.textContent = 'On this page';
	var list = document.createElement('ul');
	heads.forEach(function (h) {
		if (!h.id) {
			var base = (h.textContent || 'section').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60) || 'section', id = base, n = 2;
			while (used[id] || document.getElementById(id)) { id = base + '-' + n++; }
			h.id = id;
		}
		used[h.id] = 1;
		var li = document.createElement('li'), a = document.createElement('a');
		a.href = '#' + h.id;
		a.textContent = (h.textContent || '').trim();
		li.appendChild(a);
		list.appendChild(li);
	});
	var nav = document.createElement('nav');
	nav.setAttribute('aria-label', 'On this page');
	nav.appendChild(list);
	toc.appendChild(title);
	toc.appendChild(nav);
	c.insertBefore(toc, before || null);
	toc.hidden = false;
})();
</script>
	<?php
}

/**
 * Sitemap pages: break ties on the modified time by ID.
 *
 * Rank Math pages each post-type sitemap with "ORDER BY p.post_modified DESC LIMIT n OFFSET m" and offers no filter
 * for the order. Thousands of college posts share a modified time from the bulk imports, so MySQL could return them in
 * a different order for each page: 2 colleges appeared twice and 2 never (October 2026). Only that statement changes.
 */
add_filter( 'query', function ( $sql ) {
	if ( false !== strpos( $sql, 'ORDER BY p.post_modified DESC LIMIT' ) && false !== strpos( $sql, 'rank_math_robots' ) ) {
		$sql = str_replace( 'ORDER BY p.post_modified DESC LIMIT', 'ORDER BY p.post_modified DESC, p.ID DESC LIMIT', $sql );
	}
	return $sql;
} );
