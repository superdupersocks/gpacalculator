<?php
/**
 * Plugin Name: Grade + GPA
 * Description: Shared university GPA calculators plus the universal international grade conversion engine and reviewed per-country configurations.
 * Version: 0.6.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: GPAcalculator.net
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GPCM_VERSION', '0.6.0' );
define( 'GPCM_CAP', 'gpcm_manage_calculators' );
define( 'GPCM_PROFILE_OPTION', 'gpcm_university_profiles' );
define( 'GPCM_SHARED_OPTION', 'gpcm_shared_assets' );
define( 'GPCM_LEGACY_OPTION', 'gpcm_calculators' );
define( 'GPCM_INTL_PROFILE_OPTION', 'gpcm_international_profiles' );
define( 'GPCM_INTL_META_OPTION', 'gpcm_international_meta' );
define( 'GPCM_INTL_SHARED_OPTION', 'gpcm_international_shared_assets' );
define( 'GPCM_INTL_ENGINE_VERSION', '1.1.0' );

// Calculator engine: theme calculators and the Calculators plugin's shortcodes, served from this plugin.
require_once __DIR__ . '/includes/bootstrap.php';

function gpcm_activate() {
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        $admin->add_cap( GPCM_CAP );
    }
}
register_activation_hook( __FILE__, 'gpcm_activate' );

function gpcm_profiles() {
    $profiles = get_option( GPCM_PROFILE_OPTION, array() );
    return is_array( $profiles ) ? $profiles : array();
}

function gpcm_builtin_stanford() {
    static $profile = null;
    if ( null === $profile ) {
        $json = file_get_contents( plugin_dir_path( __FILE__ ) . 'profiles/stanford.json' );
        $profile = $json ? json_decode( $json, true ) : false;
    }
    return is_array( $profile ) ? $profile : null;
}

function gpcm_profile( $id ) {
    $profiles = gpcm_profiles();
    if ( isset( $profiles[ $id ] ) && is_array( $profiles[ $id ] ) ) {
        return $profiles[ $id ];
    }
    return 'stanford' === $id ? gpcm_builtin_stanford() : null;
}

function gpcm_legacy_items() {
    $items = get_option( GPCM_LEGACY_OPTION, array() );
    return is_array( $items ) ? $items : array();
}

function gpcm_asset_url( $asset, $extension ) {
    if ( ! is_array( $asset ) || ! isset( $asset['kind'], $asset['path'] ) || ! is_string( $asset['path'] ) ) {
        return '';
    }
    if ( 'bundled' === $asset['kind'] ) {
        $allowed = array( 'assets/shared-calculator.' . $extension, 'assets/stanford-gpa-calculator.' . $extension );
        return in_array( $asset['path'], $allowed, true ) && is_file( plugin_dir_path( __FILE__ ) . $asset['path'] )
            ? plugins_url( $asset['path'], __FILE__ ) : '';
    }
    if ( 'upload' === $asset['kind'] &&
        preg_match( '~^gpacalc-calculators/[a-z0-9-]+/[a-z0-9-]+-[a-f0-9]{16}\.' . preg_quote( $extension, '~' ) . '$~', $asset['path'] ) ) {
        $uploads = wp_get_upload_dir();
        return empty( $uploads['error'] ) &&
            is_file( trailingslashit( $uploads['basedir'] ) . $asset['path'] )
            ? trailingslashit( $uploads['baseurl'] ) . $asset['path'] : '';
    }
    return '';
}

function gpcm_shared_asset( $extension ) {
    $custom = get_option( GPCM_SHARED_OPTION, array() );
    if ( is_array( $custom ) && isset( $custom[ $extension ] ) && gpcm_asset_url( $custom[ $extension ], $extension ) ) {
        return $custom[ $extension ];
    }
    return array( 'kind' => 'bundled', 'path' => 'assets/shared-calculator.' . $extension, 'version' => GPCM_VERSION );
}

function gpcm_register_shortcode() {
    if ( ! shortcode_exists( 'gpcm_calculator' ) ) {
        add_shortcode( 'gpcm_calculator', 'gpcm_shortcode' );
    }
}
add_action( 'init', 'gpcm_register_shortcode' );

function gpcm_shortcode( $attributes ) {
    $attributes = shortcode_atts( array( 'id' => '' ), $attributes, 'gpcm_calculator' );
    $id = is_string( $attributes['id'] ) ? sanitize_title( $attributes['id'] ) : '';
    if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $id ) ) {
        return '';
    }

    $profile = gpcm_profile( $id );
    if ( $profile ) {
        $js = gpcm_shared_asset( 'js' );
        $css = gpcm_shared_asset( 'css' );
        $js_url = gpcm_asset_url( $js, 'js' );
        $css_url = gpcm_asset_url( $css, 'css' );
        if ( ! $js_url || ! $css_url ) {
            return '';
        }
        $js_version = isset( $js['version'] ) ? $js['version'] : GPCM_VERSION;
        $css_version = isset( $css['version'] ) ? $css['version'] : GPCM_VERSION;
        $css_url = add_query_arg( 'ver', $css_version, $css_url );
        wp_enqueue_script( 'gpcm-loader', plugins_url( 'assets/gpcm-loader.js', __FILE__ ), array(), GPCM_VERSION, true );
        wp_enqueue_script( 'gpcm-shared-engine', $js_url, array( 'gpcm-loader' ), $js_version, true );
        wp_enqueue_script( 'gpcm-init', plugins_url( 'assets/gpcm-init.js', __FILE__ ), array( 'gpcm-shared-engine' ), GPCM_VERSION, true );
        $json = wp_json_encode( $profile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( ! $json ) {
            return '';
        }
        return '<div class="gpcm-host" data-gpcm-profile-id="' . esc_attr( $id ) . '" data-gpacalc-css="' . esc_url( $css_url ) . '">' .
            '<script type="application/json" data-gpcm-profile>' . $json . '</script></div>';
    }

    // Earlier calculator entries remain available during the move to shared profiles.
    $items = gpcm_legacy_items();
    if ( ! isset( $items[ $id ] ) || ! is_array( $items[ $id ] ) ) {
        return '';
    }
    $item = $items[ $id ];
    $js = isset( $item['js'] ) ? $item['js'] : null;
    $css = isset( $item['css'] ) ? $item['css'] : null;
    $js_url = gpcm_asset_url( $js, 'js' );
    $css_url = gpcm_asset_url( $css, 'css' );
    if ( ! $js_url || ! $css_url ) {
        return '';
    }
    wp_enqueue_script( 'gpcm-loader', plugins_url( 'assets/gpcm-loader.js', __FILE__ ), array(), GPCM_VERSION, true );
    wp_enqueue_script( 'gpcm-legacy-' . $id, $js_url, array( 'gpcm-loader' ), isset( $js['version'] ) ? $js['version'] : GPCM_VERSION, true );
    return '<div class="gpcm-host" data-gpacalc-id="' . esc_attr( $id ) . '" data-gpacalc-css="' . esc_url( $css_url ) . '"></div>';
}

function gpcm_shortcode_conflict_notice() {
    global $shortcode_tags;
    if ( current_user_can( GPCM_CAP ) && isset( $shortcode_tags['gpcm_calculator'] ) &&
        'gpcm_shortcode' !== $shortcode_tags['gpcm_calculator'] ) {
        echo '<div class="notice notice-error"><p>Another plugin owns the gpcm_calculator shortcode. Deactivate the duplicate before using this plugin.</p></div>';
    }
}
add_action( 'admin_notices', 'gpcm_shortcode_conflict_notice' );

function gpcm_country_shortcode_conflict_notice() {
    global $shortcode_tags;
    if ( current_user_can( GPCM_CAP ) && isset( $shortcode_tags['country_grade'] ) &&
        'gpcm_international_shortcode' !== $shortcode_tags['country_grade'] ) {
        echo '<div class="notice notice-error"><p>Another plugin owns the country_grade shortcode. Deactivate the duplicate before using Country.</p></div>';
    }
}
add_action( 'admin_notices', 'gpcm_country_shortcode_conflict_notice' );

function gpcm_admin_menu() {
    add_menu_page( 'Grade + GPA', 'Grade + GPA', GPCM_CAP, 'gpcm-calculators', 'gpcm_admin_page', 'dashicons-calculator', 26 );
    add_submenu_page( 'gpcm-calculators', 'University', 'University', GPCM_CAP, 'gpcm-calculators', 'gpcm_admin_page' );
    add_submenu_page( 'gpcm-calculators', 'Country', 'Country', GPCM_CAP, 'gpcm-international', 'gpcm_international_admin_page' );
}
add_action( 'admin_menu', 'gpcm_admin_menu' );

function gpcm_admin_page() {
    if ( ! current_user_can( GPCM_CAP ) ) {
        wp_die( 'You cannot manage calculators.', '', array( 'response' => 403 ) );
    }
    $profiles = gpcm_profiles();
    if ( ! isset( $profiles['stanford'] ) ) {
        $profiles['stanford'] = gpcm_builtin_stanford();
    }
    ksort( $profiles );
    $legacy = array_diff_key( gpcm_legacy_items(), $profiles );
    ?>
    <div class="wrap">
        <h1>University</h1>
        <p>One shared calculator design, with a reviewed rules profile for each university. Insert the shortcode in a Shortcode block.</p>
        <?php if ( isset( $_GET['saved'] ) && '1' === $_GET['saved'] ) : ?>
            <div class="notice notice-success is-dismissible"><p>Calculator files saved.</p></div>
        <?php endif; ?>
        <table class="widefat striped" style="max-width: 1050px">
            <thead><tr><th>University</th><th>Profile ID</th><th>Rules version</th><th>QA status</th><th>Shortcode</th></tr></thead>
            <tbody>
            <?php foreach ( $profiles as $id => $profile ) : if ( ! is_array( $profile ) ) { continue; } ?>
                <tr><td><?php echo esc_html( $profile['universityName'] ?? $id ); ?></td>
                    <td><code><?php echo esc_html( $id ); ?></code></td>
                    <td><?php echo esc_html( $profile['configVersion'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $profile['qaStatus'] ?? '—' ); ?></td>
                    <td><code><?php echo esc_html( '[gpcm_calculator id="' . $id . '"]' ); ?></code></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <h2>Import or update one university</h2>
        <p>Upload the approved JSON profile exported from the rules workbook. Existing profiles are replaced by ID. The shared JS and CSS stay in place.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="gpcm_save_profile">
            <?php wp_nonce_field( 'gpcm_save_profile', 'gpcm_nonce' ); ?>
            <input type="file" name="profile_json" accept=".json,application/json" required>
            <?php submit_button( 'Import approved profile', 'primary', 'submit', false ); ?>
        </form>
        <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <h2>Shared design assets</h2>
            <p>Updating either file changes every profile-based calculator. Upload compatible files only after testing several profiles.</p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gpcm_save_shared">
                <?php wp_nonce_field( 'gpcm_save_shared', 'gpcm_nonce' ); ?>
                <p><label>Shared JavaScript <input type="file" name="js_file" accept=".js"></label></p>
                <p><label>Shared CSS <input type="file" name="css_file" accept=".css"></label></p>
                <?php submit_button( 'Update shared assets', 'secondary', 'submit', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gpcm_restore_shared">
                <?php wp_nonce_field( 'gpcm_restore_shared', 'gpcm_nonce' ); ?>
                <?php submit_button( 'Use bundled shared assets', 'secondary', 'submit', false ); ?>
            </form>
        <?php endif; ?>
        <?php if ( $legacy ) : ?>
            <h2>Older calculators</h2>
            <p>These still use their existing uploaded JS/CSS. Import a reviewed JSON profile with the same ID to move one to the shared engine.</p>
            <ul><?php foreach ( $legacy as $id => $item ) : ?>
                <li><?php echo esc_html( $item['title'] ?? $id ); ?> — <code><?php echo esc_html( '[gpcm_calculator id="' . $id . '"]' ); ?></code></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
    <?php
}

function gpcm_upload_asset( $field, $id, $extension ) {
    if ( ! isset( $_FILES[ $field ] ) ) {
        return null;
    }
    $file = $_FILES[ $field ];
    if ( ! is_array( $file ) || ! isset( $file['error'] ) || ! is_int( $file['error'] ) ) {
        throw new RuntimeException( 'Invalid upload.' );
    }
    if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
        return null;
    }
    if ( UPLOAD_ERR_OK !== $file['error'] || ! isset( $file['name'], $file['size'], $file['tmp_name'] ) ||
        ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_int( $file['size'] ) ||
        strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== $extension ||
        $file['size'] < 1 || $file['size'] > 1024 * 1024 || ! is_uploaded_file( $file['tmp_name'] ) ) {
        throw new RuntimeException( 'Upload a valid .js or .css file under 1 MB.' );
    }
    $contents = file_get_contents( $file['tmp_name'] );
    if ( false === $contents || false !== strpos( $contents, '<?php' ) ) {
        throw new RuntimeException( 'Uploaded asset could not be used.' );
    }
    $hash = substr( hash( 'sha256', $contents ), 0, 16 );
    $uploads = wp_upload_dir();
    if ( ! empty( $uploads['error'] ) ) {
        throw new RuntimeException( 'Uploads directory is unavailable.' );
    }
    $relative = 'gpacalc-calculators/' . $id;
    $directory = trailingslashit( $uploads['basedir'] ) . $relative;
    if ( ! wp_mkdir_p( $directory ) ) {
        throw new RuntimeException( 'Could not create calculator asset directory.' );
    }
    $filename = $id . '-' . $hash . '.' . $extension;
    $destination = trailingslashit( $directory ) . $filename;
    if ( ! is_file( $destination ) && ! move_uploaded_file( $file['tmp_name'], $destination ) ) {
        throw new RuntimeException( 'Could not save calculator asset.' );
    }
    return array( 'kind' => 'upload', 'path' => $relative . '/' . $filename, 'version' => $hash );
}

function gpcm_validate_profile( $profile ) {
    if ( ! is_array( $profile ) || 1 !== ( $profile['schemaVersion'] ?? null ) ||
        ! in_array( $profile['qaStatus'] ?? '', array( 'QA passed', 'Live' ), true ) ||
        ! isset( $profile['slug'], $profile['universityName'], $profile['baseContext'], $profile['records'], $profile['sampleData'] ) ||
        ! is_string( $profile['slug'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $profile['slug'] ) ||
        ! is_string( $profile['universityName'] ) || '' === trim( $profile['universityName'] ) ||
        ! is_array( $profile['baseContext'] ) || ! is_array( $profile['records'] ) ||
        ! count( $profile['records'] ) || count( $profile['records'] ) > 20 ||
        ! is_array( $profile['sampleData'] ) ) {
        throw new RuntimeException( 'The profile is missing required ID, record, or QA fields.' );
    }
    $context = $profile['baseContext'];
    $max = $context['scaleMax'] ?? null;
    $max_units = $context['courseUnitMax'] ?? null;
    if ( ! is_numeric( $max ) || $max <= 0 || $max > 10 ||
        ! is_numeric( $max_units ) || $max_units <= 0 || $max_units > 1000 ||
        ! isset( $context['grades'] ) || ! is_array( $context['grades'] ) || ! count( $context['grades'] ) ||
        ! isset( $context['specialTypes'] ) || ! is_array( $context['specialTypes'] ) ||
        ! isset( $profile['officialLinks'] ) || ! is_array( $profile['officialLinks'] ) || ! count( $profile['officialLinks'] ) ||
        ! isset( $profile['sampleData']['completed'], $profile['sampleData']['planned'] ) ||
        ! is_array( $profile['sampleData']['completed'] ) || ! is_array( $profile['sampleData']['planned'] ) ) {
        throw new RuntimeException( 'The profile lacks a supported GPA scale, grades, sources, or sample.' );
    }
    foreach ( $context['grades'] as $grade => $points ) {
        if ( ! is_string( $grade ) || strlen( $grade ) > 12 ||
            ( null !== $points && ( ! is_numeric( $points ) || $points < 0 || $points > $max ) ) ) {
            throw new RuntimeException( 'A grade value exceeds the verified scale.' );
        }
    }
    if ( count( $context['specialTypes'] ) < 1 || count( $context['specialTypes'] ) > 30 ) {
        throw new RuntimeException( 'The profile needs a bounded set of course sources.' );
    }
    foreach ( $context['specialTypes'] as $special ) {
        if ( ! is_array( $special ) || empty( $special['name'] ) || ! is_string( $special['name'] ) ) {
            throw new RuntimeException( 'Every course source needs a name.' );
        }
        if ( isset( $special['gradePoints'] ) ) {
            if ( ! is_array( $special['gradePoints'] ) ) {
                throw new RuntimeException( 'A course source grade map is invalid.' );
            }
            foreach ( $special['gradePoints'] as $points ) {
                if ( null !== $points && ( ! is_numeric( $points ) || $points < 0 || $points > $max ) ) {
                    throw new RuntimeException( 'A course source grade exceeds the GPA scale.' );
                }
            }
        }
    }
    foreach ( $profile['officialLinks'] as $link ) {
        if ( ! is_array( $link ) || ! isset( $link['url'] ) || ! is_string( $link['url'] ) ||
            ! preg_match( '~^https://[^\s]+$~i', $link['url'] ) ) {
            throw new RuntimeException( 'Official policy links must use HTTPS.' );
        }
    }
    foreach ( $profile['records'] as $record ) {
        if ( ! is_array( $record ) || empty( $record['name'] ) || ! is_string( $record['name'] ) ) {
            throw new RuntimeException( 'Every academic record needs a name.' );
        }
    }
    $repeat = $context['repeatPolicy'] ?? array();
    if ( ! is_array( $repeat ) || ! in_array( $repeat['mode'] ?? '', array( 'replace', 'retain', 'none' ), true ) ) {
        throw new RuntimeException( 'The repeat policy is not supported by this engine.' );
    }
    foreach ( array( 'originalGrades', 'newGrades' ) as $field ) {
        if ( isset( $repeat[ $field ] ) && ( ! is_array( $repeat[ $field ] ) ||
            array_diff( $repeat[ $field ], array_keys( $context['grades'] ) ) ) ) {
            throw new RuntimeException( 'Repeat grade choices must match the profile grade table.' );
        }
    }
    if ( count( $profile['sampleData']['completed'] ) + count( $profile['sampleData']['planned'] ) < 1 ) {
        throw new RuntimeException( 'The profile needs a tested sample course.' );
    }
    $walk = function ( $value ) use ( &$walk ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $part ) { $walk( $part ); }
        } elseif ( is_string( $value ) && ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) ) {
            throw new RuntimeException( 'Profile text must be plain text, without HTML.' );
        }
    };
    $walk( $profile );
    return $profile['slug'];
}

function gpcm_save_profile() {
    if ( ! current_user_can( GPCM_CAP ) ) {
        wp_die( 'You cannot import profiles.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'gpcm_save_profile', 'gpcm_nonce' );
    $file = $_FILES['profile_json'] ?? null;
    if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) ||
        ! isset( $file['name'], $file['size'], $file['tmp_name'] ) ||
        ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_int( $file['size'] ) ||
        strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'json' ||
        $file['size'] < 1 || $file['size'] > 512 * 1024 || ! is_uploaded_file( $file['tmp_name'] ) ) {
        wp_die( 'Upload an approved JSON profile under 512 KB.', '', array( 'response' => 400 ) );
    }
    $profile = json_decode( file_get_contents( $file['tmp_name'] ), true );
    try {
        $id = gpcm_validate_profile( $profile );
    } catch ( RuntimeException $error ) {
        wp_die( esc_html( $error->getMessage() ), 'Profile import failed', array( 'response' => 400 ) );
    }
    $profiles = gpcm_profiles();
    $profiles[ $id ] = $profile;
    update_option( GPCM_PROFILE_OPTION, $profiles, false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-calculators', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_save_profile', 'gpcm_save_profile' );

function gpcm_save_shared() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Only administrators can change shared code.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'gpcm_save_shared', 'gpcm_nonce' );
    try {
        $js = gpcm_upload_asset( 'js_file', 'shared', 'js' );
        $css = gpcm_upload_asset( 'css_file', 'shared', 'css' );
        if ( ! $js && ! $css ) {
            throw new RuntimeException( 'Choose a JS or CSS file.' );
        }
    } catch ( RuntimeException $error ) {
        wp_die( esc_html( $error->getMessage() ), 'Shared asset upload failed', array( 'response' => 400 ) );
    }
    $assets = get_option( GPCM_SHARED_OPTION, array() );
    $assets = is_array( $assets ) ? $assets : array();
    if ( $js ) { $assets['js'] = $js; }
    if ( $css ) { $assets['css'] = $css; }
    update_option( GPCM_SHARED_OPTION, $assets, false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-calculators', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_save_shared', 'gpcm_save_shared' );

function gpcm_restore_shared() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Only administrators can change shared code.', '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'gpcm_restore_shared', 'gpcm_nonce' );
    update_option( GPCM_SHARED_OPTION, array(), false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-calculators', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_restore_shared', 'gpcm_restore_shared' );


/* ==========================================================================
 * International Grade Converter
 * One shared vanilla-JS/CSS engine + one reviewed JSON configuration per country.
 * Country data is imported into WordPress and embedded into country shortcodes.
 * The universal picker resolves published configs through a same-origin REST route.
 * ========================================================================== */

function gpcm_international_profiles() {
    $profiles = get_option( GPCM_INTL_PROFILE_OPTION, array() );
    return is_array( $profiles ) ? $profiles : array();
}

function gpcm_international_meta() {
    $meta = get_option( GPCM_INTL_META_OPTION, array() );
    return is_array( $meta ) ? $meta : array();
}

function gpcm_international_profile( $slug ) {
    $profiles = gpcm_international_profiles();
    return isset( $profiles[ $slug ] ) && is_array( $profiles[ $slug ] ) ? $profiles[ $slug ] : null;
}

function gpcm_international_asset_url( $asset, $extension ) {
    if ( ! is_array( $asset ) || ! isset( $asset['kind'], $asset['path'] ) || ! is_string( $asset['path'] ) ) {
        return '';
    }
    if ( 'bundled' === $asset['kind'] ) {
        $expected = 'assets/international-grade-converter.' . $extension;
        return $asset['path'] === $expected && is_file( plugin_dir_path( __FILE__ ) . $expected )
            ? plugins_url( $expected, __FILE__ ) : '';
    }
    return gpcm_asset_url( $asset, $extension );
}

function gpcm_international_shared_asset( $extension ) {
    $custom = get_option( GPCM_INTL_SHARED_OPTION, array() );
    if ( is_array( $custom ) && isset( $custom[ $extension ] ) && gpcm_international_asset_url( $custom[ $extension ], $extension ) ) {
        return $custom[ $extension ];
    }
    return array(
        'kind' => 'bundled',
        'path' => 'assets/international-grade-converter.' . $extension,
        'version' => GPCM_INTL_ENGINE_VERSION,
    );
}

function gpcm_compare_semver( $left, $right ) {
    $a = array_map( 'intval', array_pad( explode( '.', (string) $left ), 3, '0' ) );
    $b = array_map( 'intval', array_pad( explode( '.', (string) $right ), 3, '0' ) );
    for ( $i = 0; $i < 3; $i++ ) {
        if ( $a[ $i ] < $b[ $i ] ) { return -1; }
        if ( $a[ $i ] > $b[ $i ] ) { return 1; }
    }
    return 0;
}

function gpcm_intl_plain_text_walk( $value ) {
    if ( is_array( $value ) ) {
        foreach ( $value as $part ) { gpcm_intl_plain_text_walk( $part ); }
        return;
    }
    if ( is_string( $value ) && ( false !== strpos( $value, '<' ) || false !== strpos( $value, '>' ) ) ) {
        throw new RuntimeException( 'Country configuration text must be plain text, without HTML.' );
    }
}

function gpcm_intl_validate_system( $system, &$seen_ids, $published ) {
    if ( ! is_array( $system ) || empty( $system['id'] ) || empty( $system['label'] ) ||
        ! is_string( $system['id'] ) || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,79}$/', $system['id'] ) ||
        ! is_string( $system['label'] ) ) {
        throw new RuntimeException( 'Every grading system needs a valid id and label.' );
    }
    if ( isset( $seen_ids['system:' . $system['id']] ) ) {
        throw new RuntimeException( 'Duplicate grading-system id: ' . $system['id'] . '.' );
    }
    $seen_ids['system:' . $system['id']] = true;

    if ( ! in_array( $system['gradeDirection'] ?? '', array( 'higher_is_better', 'lower_is_better' ), true ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' needs a supported gradeDirection.' );
    }
    $method = $system['transcriptMethod'] ?? 'convert_each_course_then_weight';
    if ( ! in_array( $method, array( 'convert_each_course_then_weight', 'not_supported' ), true ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' requests an unsupported transcript method.' );
    }
    $manual = $system['manualReviewPolicy'] ?? 'block_result';
    if ( ! in_array( $manual, array( 'block_result', 'partial_result_allowed' ), true ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' has an unsupported manual-review policy.' );
    }

    $rules = $system['inputRules'] ?? null;
    if ( ! is_array( $rules ) || ! in_array( $rules['type'] ?? '', array( 'numeric', 'select' ), true ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' needs supported inputRules.' );
    }
    if ( 'numeric' === $rules['type'] ) {
        if ( ! isset( $rules['min'], $rules['max'] ) || ! is_numeric( $rules['min'] ) || ! is_numeric( $rules['max'] ) || $rules['min'] > $rules['max'] ) {
            throw new RuntimeException( 'Numeric grading system ' . $system['id'] . ' has an invalid input range.' );
        }
        if ( isset( $rules['precision'] ) && ( ! is_int( $rules['precision'] ) || $rules['precision'] < 0 || $rules['precision'] > 6 ) ) {
            throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid precision.' );
        }
        if ( isset( $rules['step'] ) && ( ! is_numeric( $rules['step'] ) || $rules['step'] <= 0 ) ) {
            throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid step.' );
        }
        if ( isset( $rules['allowedValues'] ) && ! is_array( $rules['allowedValues'] ) ) {
            throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid allowedValues.' );
        }
    } elseif ( empty( $rules['options'] ) || ! is_array( $rules['options'] ) ) {
        throw new RuntimeException( 'Select grading system ' . $system['id'] . ' needs options.' );
    }

    $bands = $system['conversionBands'] ?? null;
    if ( ! is_array( $bands ) || ! count( $bands ) || count( $bands ) > 200 ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' needs a bounded conversion-band list.' );
    }
    $source_ids = array();
    $sources = $system['sources'] ?? array();
    if ( ! is_array( $sources ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid sources.' );
    }
    foreach ( $sources as $source ) {
        if ( ! is_array( $source ) || empty( $source['id'] ) || ! is_string( $source['id'] ) ) {
            throw new RuntimeException( 'Every source in ' . $system['id'] . ' needs an id.' );
        }
        $source_ids[ $source['id'] ] = true;
        $url = $source['url'] ?? '';
        if ( $published && ( ! is_string( $url ) || ! preg_match( '~^https://[^\s]+$~i', $url ) ) ) {
            throw new RuntimeException( 'Published grading systems require HTTPS source URLs.' );
        }
        if ( '' !== $url && ( ! is_string( $url ) || ! preg_match( '~^https://[^\s]+$~i', $url ) ) ) {
            throw new RuntimeException( 'Source URLs must use HTTPS.' );
        }
    }
    if ( $published && ! count( $source_ids ) ) {
        throw new RuntimeException( 'Published grading systems require source references.' );
    }

    $numeric_intervals = array();
    foreach ( $bands as $band ) {
        if ( ! is_array( $band ) || empty( $band['id'] ) || ! is_string( $band['id'] ) ) {
            throw new RuntimeException( 'Every conversion band in ' . $system['id'] . ' needs an id.' );
        }
        if ( isset( $seen_ids['band:' . $band['id']] ) ) {
            throw new RuntimeException( 'Duplicate conversion-band id: ' . $band['id'] . '.' );
        }
        $seen_ids['band:' . $band['id']] = true;
        if ( ! array_key_exists( 'pass', $band ) || ! is_bool( $band['pass'] ) ) {
            throw new RuntimeException( 'Band ' . $band['id'] . ' needs an explicit pass/fail value.' );
        }
        if ( array_key_exists( 'matchValue', $band ) ) {
            if ( is_array( $band['matchValue'] ) || is_object( $band['matchValue'] ) ) {
                throw new RuntimeException( 'Band ' . $band['id'] . ' has invalid matchValue.' );
            }
        } else {
            if ( ! isset( $band['min'], $band['max'] ) || ! is_numeric( $band['min'] ) || ! is_numeric( $band['max'] ) || $band['min'] > $band['max'] ||
                ! array_key_exists( 'minInclusive', $band ) || ! is_bool( $band['minInclusive'] ) ||
                ! array_key_exists( 'maxInclusive', $band ) || ! is_bool( $band['maxInclusive'] ) ) {
                throw new RuntimeException( 'Band ' . $band['id'] . ' needs valid min/max and explicit endpoint rules.' );
            }
            $numeric_intervals[] = array(
                'id' => $band['id'], 'min' => (float) $band['min'], 'max' => (float) $band['max'],
                'min_i' => $band['minInclusive'], 'max_i' => $band['maxInclusive'],
            );
        }
        if ( isset( $band['usGpa'] ) && ( ! is_numeric( $band['usGpa'] ) || $band['usGpa'] < 0 || $band['usGpa'] > 4.5 ) ) {
            throw new RuntimeException( 'Band ' . $band['id'] . ' has an invalid US GPA value.' );
        }
        $refs = $band['sourceRefs'] ?? array();
        if ( $published && ( ! is_array( $refs ) || ! count( $refs ) ) ) {
            throw new RuntimeException( 'Published band ' . $band['id'] . ' needs sourceRefs.' );
        }
        if ( is_array( $refs ) ) {
            foreach ( $refs as $ref ) {
                if ( ! is_string( $ref ) || ! isset( $source_ids[ $ref ] ) ) {
                    throw new RuntimeException( 'Band ' . $band['id'] . ' references an unknown source.' );
                }
            }
        }
    }

    usort( $numeric_intervals, function ( $a, $b ) { return $a['min'] <=> $b['min']; } );
    for ( $i = 1; $i < count( $numeric_intervals ); $i++ ) {
        $prev = $numeric_intervals[ $i - 1 ];
        $curr = $numeric_intervals[ $i ];
        if ( $curr['min'] < $prev['max'] || ( $curr['min'] === $prev['max'] && $curr['min_i'] && $prev['max_i'] ) ) {
            throw new RuntimeException( 'Conversion bands ' . $prev['id'] . ' and ' . $curr['id'] . ' overlap.' );
        }
    }

    $specials = $system['specialResults'] ?? ( $system['excludedResults'] ?? array() );
    if ( ! is_array( $specials ) || count( $specials ) > 100 ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid specialResults.' );
    }
    foreach ( $specials as $special ) {
        if ( ! is_array( $special ) || ! array_key_exists( 'value', $special ) || empty( $special['label'] ) ) {
            throw new RuntimeException( 'Every special result needs value and label.' );
        }
        $behavior = $special['behavior'] ?? ( $special['type'] ?? 'excluded' );
        if ( ! in_array( $behavior, array( 'included', 'excluded', 'manual_review', 'unsupported' ), true ) ) {
            throw new RuntimeException( 'A special result in ' . $system['id'] . ' has unsupported behavior.' );
        }
        if ( 'included' === $behavior && ( ! isset( $special['usGpa'] ) || ! is_numeric( $special['usGpa'] ) ) ) {
            throw new RuntimeException( 'Included special results need a US GPA value.' );
        }
    }

    $weight = $system['weightingRules'] ?? array();
    if ( ! is_array( $weight ) ) {
        throw new RuntimeException( 'Grading system ' . $system['id'] . ' has invalid weightingRules.' );
    }
    foreach ( array( 'creditsSupported', 'creditsRequired', 'equalWeightingAllowed' ) as $field ) {
        if ( isset( $weight[ $field ] ) && ! is_bool( $weight[ $field ] ) ) {
            throw new RuntimeException( 'Weighting rule ' . $field . ' in ' . $system['id'] . ' must be true or false.' );
        }
    }
    if ( ! empty( $weight['creditsRequired'] ) && empty( $weight['creditsSupported'] ) ) {
        throw new RuntimeException( 'A system cannot require credits while creditsSupported is false.' );
    }
}

function gpcm_validate_international_profile( $profile ) {
    if ( ! is_array( $profile ) || 1 !== ( $profile['schemaVersion'] ?? null ) ||
        empty( $profile['configVersion'] ) || ! is_string( $profile['configVersion'] ) ||
        empty( $profile['minEngineVersion'] ) || ! is_string( $profile['minEngineVersion'] ) ||
        ! in_array( $profile['status'] ?? '', array( 'draft', 'testing', 'published', 'review_required' ), true ) ||
        empty( $profile['country'] ) || ! is_string( $profile['country'] ) ||
        empty( $profile['countrySlug'] ) || ! is_string( $profile['countrySlug'] ) ||
        ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $profile['countrySlug'] ) ||
        empty( $profile['policyReviewDate'] ) || ! is_string( $profile['policyReviewDate'] ) ||
        ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $profile['policyReviewDate'] ) ||
        empty( $profile['conversionDirections'] ) || ! is_array( $profile['conversionDirections'] ) ||
        empty( $profile['educationLevels'] ) || ! is_array( $profile['educationLevels'] ) ) {
        throw new RuntimeException( 'The country configuration is missing required schema, identity, status, review, direction, or education-level fields.' );
    }
    if ( gpcm_compare_semver( $profile['minEngineVersion'], GPCM_INTL_ENGINE_VERSION ) > 0 ) {
        throw new RuntimeException( 'This configuration requires International engine ' . $profile['minEngineVersion'] . ' or later.' );
    }
    $date = DateTime::createFromFormat( 'Y-m-d', $profile['policyReviewDate'] );
    if ( ! $date || $date->format( 'Y-m-d' ) !== $profile['policyReviewDate'] ) {
        throw new RuntimeException( 'policyReviewDate must be a real YYYY-MM-DD date.' );
    }
    $published = 'published' === $profile['status'];
    $seen = array();
    $direction_ids = array();
    foreach ( $profile['conversionDirections'] as $direction ) {
        if ( ! is_array( $direction ) || empty( $direction['id'] ) || empty( $direction['label'] ) || ! is_string( $direction['id'] ) || ! is_string( $direction['label'] ) ) {
            throw new RuntimeException( 'Every conversion direction needs id and label.' );
        }
        if ( isset( $direction_ids[ $direction['id'] ] ) ) {
            throw new RuntimeException( 'Duplicate conversion-direction id.' );
        }
        $direction_ids[ $direction['id'] ] = true;
        $modes = $direction['modes'] ?? array();
        if ( ! is_array( $modes ) || ! count( $modes ) || array_diff( $modes, array( 'quick', 'transcript' ) ) ) {
            throw new RuntimeException( 'Conversion direction ' . $direction['id'] . ' has unsupported calculator modes.' );
        }
    }

    $level_ids = array();
    foreach ( $profile['educationLevels'] as $level ) {
        if ( ! is_array( $level ) || empty( $level['id'] ) || empty( $level['label'] ) || ! is_string( $level['id'] ) || ! is_string( $level['label'] ) ) {
            throw new RuntimeException( 'Every education level needs id and label.' );
        }
        if ( isset( $level_ids[ $level['id'] ] ) ) { throw new RuntimeException( 'Duplicate education-level id.' ); }
        $level_ids[ $level['id'] ] = true;
        $degree_levels = $level['degreeLevels'] ?? array();
        if ( $degree_levels && ! is_array( $degree_levels ) ) { throw new RuntimeException( 'degreeLevels must be an array.' ); }
        $groups = $degree_levels ? $degree_levels : array( array( 'qualifications' => $level['qualifications'] ?? array() ) );
        foreach ( $groups as $group ) {
            if ( $degree_levels && ( ! is_array( $group ) || empty( $group['id'] ) || empty( $group['label'] ) ) ) {
                throw new RuntimeException( 'Every degree level needs id and label.' );
            }
            $quals = is_array( $group ) ? ( $group['qualifications'] ?? ( $level['qualifications'] ?? array() ) ) : array();
            if ( ! is_array( $quals ) || ! count( $quals ) ) {
                throw new RuntimeException( 'Every education/degree branch needs at least one qualification.' );
            }
            foreach ( $quals as $qual ) {
                if ( ! is_array( $qual ) || empty( $qual['id'] ) || empty( $qual['label'] ) || ! is_array( $qual['gradingSystems'] ?? null ) || ! count( $qual['gradingSystems'] ) ) {
                    throw new RuntimeException( 'Every qualification needs id, label, and at least one grading system.' );
                }
                foreach ( $qual['gradingSystems'] as $system ) {
                    gpcm_intl_validate_system( $system, $seen, $published );
                }
            }
        }
    }
    gpcm_intl_plain_text_walk( $profile );
    return $profile['countrySlug'];
}

function gpcm_register_international_shortcode() {
    if ( ! shortcode_exists( 'country_grade' ) ) {
        add_shortcode( 'country_grade', 'gpcm_international_shortcode' );
    }
    if ( ! shortcode_exists( 'country_grade_scale' ) ) {
        add_shortcode( 'country_grade_scale', 'gpcm_country_grade_scale_shortcode' );
    }
}
add_action( 'init', 'gpcm_register_international_shortcode' );

function gpcm_enqueue_international_assets() {
    $js = gpcm_international_shared_asset( 'js' );
    $css = gpcm_international_shared_asset( 'css' );
    $js_url = gpcm_international_asset_url( $js, 'js' );
    $css_url = gpcm_international_asset_url( $css, 'css' );
    if ( ! $js_url || ! $css_url ) { return false; }
    $js_version = $js['version'] ?? GPCM_INTL_ENGINE_VERSION;
    $css_version = $css['version'] ?? GPCM_INTL_ENGINE_VERSION;
    $css_url = add_query_arg( 'ver', $css_version, $css_url );
    wp_enqueue_script( 'gpcm-loader', plugins_url( 'assets/gpcm-loader.js', __FILE__ ), array(), GPCM_VERSION, true );
    wp_enqueue_script( 'gpcm-international-engine', $js_url, array( 'gpcm-loader' ), $js_version, true );
    wp_enqueue_script( 'gpcm-international-init', plugins_url( 'assets/gpcm-international-init.js', __FILE__ ), array( 'gpcm-international-engine' ), GPCM_VERSION, true );
    return $css_url;
}

function gpcm_international_manifest( $include_unpublished = false ) {
    $profiles = gpcm_international_profiles();
    $countries = array();
    foreach ( $profiles as $slug => $profile ) {
        if ( ! is_array( $profile ) ) { continue; }
        try { gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) { continue; }
        if ( ! $include_unpublished && 'published' !== ( $profile['status'] ?? '' ) ) { continue; }
        $countries[] = array(
            'slug' => $slug,
            'name' => $profile['country'] ?? $slug,
            'status' => $profile['status'] ?? '',
            'configVersion' => $profile['configVersion'] ?? '',
            'schemaVersion' => $profile['schemaVersion'] ?? 1,
            'policyReviewDate' => $profile['policyReviewDate'] ?? '',
        );
    }
    usort( $countries, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
    return $countries;
}

function gpcm_international_unavailable( $message = 'This grade converter is temporarily unavailable.' ) {
    return '<div class="gpcm-international-unavailable" role="status" style="max-width:800px;margin:0 auto;padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#fff;color:#475569;">' . esc_html( $message ) . '</div>';
}

function gpcm_international_shortcode( $attributes ) {
    $attributes = shortcode_atts( array( 'id' => '' ), $attributes, 'country_grade' );
    $country = is_string( $attributes['id'] ) ? sanitize_title( $attributes['id'] ) : '';
    $css_url = gpcm_enqueue_international_assets();
    if ( ! $css_url ) { return gpcm_international_unavailable(); }

    $host_attrs = ' class="gpcm-international-host" data-gpcm-international="1" data-gpacalc-css="' . esc_url( $css_url ) . '"';
    $rest_base = trailingslashit( rest_url( 'gpcm/v1/international' ) );

    if ( $country ) {
        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $country ) ) { return ''; }
        $profile = gpcm_international_profile( $country );
        if ( ! $profile ) { return gpcm_international_unavailable(); }
        try { gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) {
            return current_user_can( GPCM_CAP ) ? gpcm_international_unavailable( $error->getMessage() ) : gpcm_international_unavailable();
        }
        if ( 'published' !== ( $profile['status'] ?? '' ) && ! current_user_can( GPCM_CAP ) ) {
            return gpcm_international_unavailable();
        }
        $json = wp_json_encode( $profile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
        if ( ! $json ) { return gpcm_international_unavailable(); }
        return '<div' . $host_attrs . ' data-gpcm-international-country="' . esc_attr( $country ) . '">' .
            '<script type="application/json" data-gpcm-international-config>' . $json . '</script></div>';
    }

    $manifest = gpcm_international_manifest( false );
    if ( ! count( $manifest ) ) { return gpcm_international_unavailable( 'No published country converters are available yet.' ); }
    $json = wp_json_encode( $manifest, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    if ( ! $json ) { return gpcm_international_unavailable(); }
    return '<div' . $host_attrs . ' data-gpcm-international-universal="1" data-config-base="' . esc_url( $rest_base ) . '">' .
        '<script type="application/json" data-gpcm-international-countries>' . $json . '</script></div>';
}

function gpcm_find_international_system( $profile, $system_id ) {
    foreach ( $profile['educationLevels'] ?? array() as $level ) {
        $groups = ! empty( $level['degreeLevels'] ) ? $level['degreeLevels'] : array( array( 'qualifications' => $level['qualifications'] ?? array() ) );
        foreach ( $groups as $group ) {
            $quals = $group['qualifications'] ?? ( $level['qualifications'] ?? array() );
            foreach ( $quals as $qual ) {
                foreach ( $qual['gradingSystems'] ?? array() as $system ) {
                    if ( ( $system['id'] ?? '' ) === $system_id ) { return $system; }
                }
            }
        }
    }
    return null;
}

function gpcm_country_grade_scale_shortcode( $attributes ) {
    $attributes = shortcode_atts( array( 'id' => '', 'system' => '' ), $attributes, 'country_grade_scale' );
    $country = sanitize_title( (string) $attributes['id'] );
    $system_id = sanitize_key( (string) $attributes['system'] );
    if ( ! $country ) { return ''; }
    $profile = gpcm_international_profile( $country );
    if ( ! $profile ) { return ''; }
    try { gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) { return ''; }
    if ( 'published' !== ( $profile['status'] ?? '' ) && ! current_user_can( GPCM_CAP ) ) { return ''; }

    if ( ! $system_id ) {
        $systems = array();
        foreach ( $profile['educationLevels'] ?? array() as $level ) {
            $groups = ! empty( $level['degreeLevels'] ) ? $level['degreeLevels'] : array( array( 'qualifications' => $level['qualifications'] ?? array() ) );
            foreach ( $groups as $group ) {
                foreach ( $group['qualifications'] ?? ( $level['qualifications'] ?? array() ) as $qual ) {
                    foreach ( $qual['gradingSystems'] ?? array() as $system ) { $systems[ $system['id'] ] = $system; }
                }
            }
        }
        if ( 1 !== count( $systems ) ) { return ''; }
        $system = reset( $systems );
    } else {
        $system = gpcm_find_international_system( $profile, $system_id );
    }
    if ( ! is_array( $system ) || empty( $system['conversionBands'] ) ) { return ''; }

    $title = $system['fullScaleTitle'] ?? ( $profile['country'] . ' Grade Conversion Scale' );
    $html = '<div class="gpcm-country-grade-scale"><table><caption>' . esc_html( $title ) . '</caption><thead><tr>' .
        '<th scope="col">Local Grade</th><th scope="col">Local Classification</th><th scope="col">US Letter Grade</th><th scope="col">Estimated US GPA</th></tr></thead><tbody>';
    foreach ( $system['conversionBands'] as $band ) {
        if ( array_key_exists( 'matchValue', $band ) ) {
            $local = (string) $band['matchValue'];
        } else {
            $local = (string) $band['min'] . '–' . (string) $band['max'];
        }
        $html .= '<tr><td>' . esc_html( $local ) . '</td><td>' . esc_html( $band['localClassification'] ?? '—' ) . '</td>' .
            '<td>' . esc_html( $band['usLetter'] ?? '—' ) . '</td><td>' . esc_html( isset( $band['usGpa'] ) ? number_format_i18n( (float) $band['usGpa'], 1 ) : '—' ) . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function gpcm_register_international_rest() {
    register_rest_route( 'gpcm/v1', '/international/(?P<slug>[a-z0-9-]+)\\.json', array(
        'methods' => 'GET',
        'callback' => 'gpcm_international_rest_config',
        'permission_callback' => '__return_true',
        'args' => array( 'slug' => array( 'sanitize_callback' => 'sanitize_title' ) ),
    ) );
}
add_action( 'rest_api_init', 'gpcm_register_international_rest' );

function gpcm_international_rest_config( WP_REST_Request $request ) {
    $slug = sanitize_title( $request['slug'] );
    $profile = gpcm_international_profile( $slug );
    if ( ! $profile ) { return new WP_Error( 'gpcm_not_found', 'Country configuration not found.', array( 'status' => 404 ) ); }
    try { gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) {
        return new WP_Error( 'gpcm_invalid_config', 'Country configuration is unavailable.', array( 'status' => 503 ) );
    }
    if ( 'published' !== ( $profile['status'] ?? '' ) && ! current_user_can( GPCM_CAP ) ) {
        return new WP_Error( 'gpcm_unpublished', 'Country configuration is unavailable.', array( 'status' => 404 ) );
    }
    $response = rest_ensure_response( $profile );
    if ( 'published' === ( $profile['status'] ?? '' ) ) {
        $response->header( 'Cache-Control', 'public, max-age=300' );
    } else {
        $response->header( 'Cache-Control', 'private, no-store' );
    }
    return $response;
}

function gpcm_international_admin_page() {
    if ( ! current_user_can( GPCM_CAP ) ) { wp_die( 'You cannot manage country configurations.', '', array( 'response' => 403 ) ); }
    $profiles = gpcm_international_profiles();
    $meta = gpcm_international_meta();
    ksort( $profiles );
    ?>
    <div class="wrap">
        <h1>Country</h1>
        <p>One shared country-grade engine, with one reviewed JSON configuration per country. Only <strong>published</strong> configs are available to public visitors.</p>
        <?php if ( isset( $_GET['intl_saved'] ) && '1' === $_GET['intl_saved'] ) : ?><div class="notice notice-success is-dismissible"><p>Country configuration saved.</p></div><?php endif; ?>
        <table class="widefat striped" style="max-width:1250px">
            <thead><tr><th>Country</th><th>Slug</th><th>Config</th><th>Schema</th><th>Status</th><th>Policy review</th><th>Imported</th><th>Shortcode</th></tr></thead>
            <tbody>
            <?php if ( ! $profiles ) : ?><tr><td colspan="8">No international country configurations imported yet.</td></tr><?php endif; ?>
            <?php foreach ( $profiles as $slug => $profile ) : if ( ! is_array( $profile ) ) { continue; }
                $valid = true; $validation_error = '';
                try { gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) { $valid = false; $validation_error = $error->getMessage(); }
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $profile['country'] ?? $slug ); ?></strong><?php if ( ! $valid ) : ?><br><span style="color:#b32d2e" title="<?php echo esc_attr( $validation_error ); ?>">Validation failed</span><?php endif; ?></td>
                    <td><code><?php echo esc_html( $slug ); ?></code></td>
                    <td><?php echo esc_html( $profile['configVersion'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $profile['schemaVersion'] ?? '—' ); ?></td>
                    <td><strong><?php echo esc_html( $profile['status'] ?? '—' ); ?></strong></td>
                    <td><?php echo esc_html( $profile['policyReviewDate'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $meta[ $slug ]['importedAt'] ?? '—' ); ?></td>
                    <td><code><?php echo esc_html( '[country_grade id="' . $slug . '"]' ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Import or replace one country</h2>
        <p>Upload the verified Pass 4 JSON. Importing the same <code>countrySlug</code> replaces that country configuration; the shared engine stays unchanged.</p>
        <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="gpcm_save_international_profile">
            <?php wp_nonce_field( 'gpcm_save_international_profile', 'gpcm_nonce' ); ?>
            <input type="file" name="country_json" accept=".json,application/json" required>
            <?php submit_button( 'Import country JSON', 'primary', 'submit', false ); ?>
        </form>

        <h2>Shortcodes</h2>
        <p><code>[country_grade id="germany"]</code> — country page, country locked.</p>
        <p><code>[country_grade]</code> — universal picker, published countries only.</p>
        <p><code>[country_grade_scale id="germany" system="system-id"]</code> — server-rendered article scale from the same JSON.</p>

        <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <h2>Country converter shared engine assets</h2>
            <p>Changing these files affects every international country calculator. Use only a tested engine/CSS pair.</p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gpcm_save_international_shared">
                <?php wp_nonce_field( 'gpcm_save_international_shared', 'gpcm_nonce' ); ?>
                <p><label>Country converter JavaScript <input type="file" name="intl_js_file" accept=".js"></label></p>
                <p><label>Country converter CSS <input type="file" name="intl_css_file" accept=".css"></label></p>
                <?php submit_button( 'Update country converter assets', 'secondary', 'submit', false ); ?>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="gpcm_restore_international_shared">
                <?php wp_nonce_field( 'gpcm_restore_international_shared', 'gpcm_nonce' ); ?>
                <?php submit_button( 'Use bundled country converter assets', 'secondary', 'submit', false ); ?>
            </form>
        <?php endif; ?>

    </div>
    <?php
}

function gpcm_save_international_profile() {
    if ( ! current_user_can( GPCM_CAP ) ) { wp_die( 'You cannot import country configurations.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'gpcm_save_international_profile', 'gpcm_nonce' );
    $file = $_FILES['country_json'] ?? null;
    if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! isset( $file['name'], $file['size'], $file['tmp_name'] ) ||
        ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) || ! is_int( $file['size'] ) ||
        strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'json' || $file['size'] < 1 || $file['size'] > 1024 * 1024 ||
        ! is_uploaded_file( $file['tmp_name'] ) ) {
        wp_die( 'Upload a country JSON file under 1 MB.', '', array( 'response' => 400 ) );
    }
    $raw = file_get_contents( $file['tmp_name'] );
    $profile = is_string( $raw ) ? json_decode( $raw, true ) : null;
    if ( JSON_ERROR_NONE !== json_last_error() ) { wp_die( 'The uploaded file is not valid JSON.', 'Country import failed', array( 'response' => 400 ) ); }
    try { $slug = gpcm_validate_international_profile( $profile ); } catch ( RuntimeException $error ) {
        wp_die( esc_html( $error->getMessage() ), 'Country import failed', array( 'response' => 400 ) );
    }
    $profiles = gpcm_international_profiles();
    $profiles[ $slug ] = $profile;
    update_option( GPCM_INTL_PROFILE_OPTION, $profiles, false );
    $meta = gpcm_international_meta();
    $meta[ $slug ] = array( 'importedAt' => current_time( 'mysql', true ), 'filename' => sanitize_file_name( $file['name'] ) );
    update_option( GPCM_INTL_META_OPTION, $meta, false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-international', 'intl_saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_save_international_profile', 'gpcm_save_international_profile' );



function gpcm_save_international_shared() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Only administrators can change shared code.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'gpcm_save_international_shared', 'gpcm_nonce' );
    try {
        $js = gpcm_upload_asset( 'intl_js_file', 'international-shared', 'js' );
        $css = gpcm_upload_asset( 'intl_css_file', 'international-shared', 'css' );
        if ( ! $js && ! $css ) { throw new RuntimeException( 'Choose a JS or CSS file.' ); }
    } catch ( RuntimeException $error ) {
        wp_die( esc_html( $error->getMessage() ), 'International asset upload failed', array( 'response' => 400 ) );
    }
    $assets = get_option( GPCM_INTL_SHARED_OPTION, array() );
    $assets = is_array( $assets ) ? $assets : array();
    if ( $js ) { $assets['js'] = $js; }
    if ( $css ) { $assets['css'] = $css; }
    update_option( GPCM_INTL_SHARED_OPTION, $assets, false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-international', 'intl_saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_save_international_shared', 'gpcm_save_international_shared' );

function gpcm_restore_international_shared() {
    if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Only administrators can change shared code.', '', array( 'response' => 403 ) ); }
    check_admin_referer( 'gpcm_restore_international_shared', 'gpcm_nonce' );
    update_option( GPCM_INTL_SHARED_OPTION, array(), false );
    wp_safe_redirect( add_query_arg( array( 'page' => 'gpcm-international', 'intl_saved' => '1' ), admin_url( 'admin.php' ) ) );
    exit;
}
add_action( 'admin_post_gpcm_restore_international_shared', 'gpcm_restore_international_shared' );