<?php
/**
 * percentager.com Calculators Plugin
 *
 * @package       Calculators
 * @author        Art Riyaka
 * @version       1.0.0
 *
 * @wordpress-plugin
 * Plugin Name:   Calculators
 * Plugin URI:    https://percentager.com/
 * Description:   Calculator plugin for percentager.com
 * Version:       3.0.1
 * Author:        Art Riyaka
 * Author URI:    https://www.facebook.com/artem.riyaka
 * Text Domain:   calcs-plugin
 * Domain Path:   /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

// ======== ADMIN MENU =========
add_action('admin_menu', 'calcs_plugin_admin_menu');
function calcs_plugin_admin_menu() {
    add_menu_page(
        'Calculators Settings',
        'Calculators',
        'manage_options',
        'calcs-plugin-settings',
        'calcs_plugin_settings_page',
        'dashicons-calculator',
        80
    );
}

// Handle export
function calcs_plugin_export_settings() {
    if (isset($_POST['calcs_plugin_export']) && check_admin_referer('calcs_plugin_export_action')) {
        $shortcodes = get_option('calcs_plugin_shortcodes', array());
        $export_data = array();
        foreach ($shortcodes as $shortcode) {
            $export_data[] = array(
                'name' => $shortcode['name'],
                'shortcode' => '[' . $shortcode['shortcode'] . ']',
                'css_path' => esc_url($shortcode['css_path']),
                'js_path' => esc_url($shortcode['js_path'])
            );
        }
        $json_data = json_encode($export_data, JSON_PRETTY_PRINT);

        // Ensure no output before JSON
        if (ob_get_length()) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="calculators-settings.json"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo $json_data;
        exit;
    }
}

// Handle import
function calcs_plugin_import_settings() {
    if (isset($_POST['calcs_plugin_import']) && check_admin_referer('calcs_plugin_import_action')) {
        if (isset($_FILES['calcs_plugin_import_file']) && $_FILES['calcs_plugin_import_file']['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES['calcs_plugin_import_file']['tmp_name'];
            $json_data = file_get_contents($file);
            $imported_data = json_decode($json_data, true);

            if ($imported_data !== null && is_array($imported_data)) {
                $sanitized_shortcodes = array();
                $existing_shortcodes = array();

                // Load existing shortcodes to check for duplicates
                $current_shortcodes = get_option('calcs_plugin_shortcodes', array());
                foreach ($current_shortcodes as $sc) {
                    $existing_shortcodes[] = $sc['shortcode'];
                    $sanitized_shortcodes[] = $sc; // Preserve existing shortcodes
                }

                foreach ($imported_data as $item) {
                    if (isset($item['shortcode']) && isset($item['css_path']) && isset($item['js_path'])) {
                        $shortcode_name = trim($item['shortcode'], '[]');
                        if (!empty($shortcode_name) && !in_array($shortcode_name, $existing_shortcodes)) {
                            $sanitized_shortcodes[] = array(
                                'name' => sanitize_text_field(isset($item['name']) ? $item['name'] : $shortcode_name),
                                'shortcode' => sanitize_text_field($shortcode_name),
                                'css_path' => esc_url_raw($item['css_path']),
                                'js_path' => esc_url_raw($item['js_path'])
                            );
                            $existing_shortcodes[] = $shortcode_name;
                        }
                    }
                }
                update_option('calcs_plugin_shortcodes', $sanitized_shortcodes);
                return '<div class="updated"><p>Settings imported successfully! Duplicate shortcodes were ignored.</p></div>';
            } else {
                return '<div class="error"><p>Invalid JSON file or data format. Ensure the file contains only the expected JSON structure.</p></div>';
            }
        } else {
            return '<div class="error"><p>Error uploading file. Please try again.</p></div>';
        }
    }
    return '';
}

// Admin settings page
function calcs_plugin_settings_page() {
    // Save settings if form is submitted
    if (isset($_POST['calcs_plugin_save']) && check_admin_referer('calcs_plugin_save_action')) {
        $shortcodes = array();
        if (!empty($_POST['name'])) {
            foreach ($_POST['name'] as $index => $name) {
                if (!empty($name) && !empty($_POST['shortcode_name'][$index]) && !empty($_POST['js_path'][$index]) && !empty($_POST['css_path'][$index])) {
                    $shortcodes[] = array(
                        'name' => sanitize_text_field($name),
                        'shortcode' => sanitize_text_field($_POST['shortcode_name'][$index]),
                        'js_path' => esc_url_raw($_POST['js_path'][$index]),
                        'css_path' => esc_url_raw($_POST['css_path'][$index])
                    );
                }
            }
        }
        update_option('calcs_plugin_shortcodes', $shortcodes);
        echo '<div class="updated"><p>Settings saved successfully!</p></div>';
    }

    // Handle export
    calcs_plugin_export_settings();

    // Handle import
    $import_message = calcs_plugin_import_settings();

    $shortcodes = get_option('calcs_plugin_shortcodes', array(
        array(
            'name' => 'Calculators United',
            'shortcode' => 'calculators-united',
            'js_path' => 'https://percentager-calculators.netlify.app/assets/index-Dn-eo0qD.js'
        )
    ));

    ?>
    <div class="wrap">
        <h1>Calculators Plugin Settings</h1>
        <?php echo $import_message; ?>
        <form method="post" action="">
            <?php wp_nonce_field('calcs_plugin_save_action'); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Shortcode</th>
                        <th>JS Path</th>
                        <th>CSS Path</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="shortcodes-table">
                    <?php foreach ($shortcodes as $index => $shortcode): ?>
                        <tr>
                            <td><input type="text" name="name[]" value="<?php echo esc_attr($shortcode['name']); ?>" required></td>
                            <td><input type="text" name="shortcode_name[]" value="<?php echo esc_attr($shortcode['shortcode']); ?>" required></td>
                            <td><input type="url" name="js_path[]" value="<?php echo esc_attr($shortcode['js_path']); ?>" required style="width: 100%;"></td>
                            <td><input type="url" name="css_path[]" value="<?php echo esc_attr($shortcode['css_path']); ?>" required style="width: 100%;"></td>
                            <td><button type="button" class="button button-secondary remove-row">Remove</button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="button" class="button button-secondary" id="add-shortcode">Add New Shortcode</button></p>
            <p><input type="submit" name="calcs_plugin_save" class="button button-primary" value="Save Changes"></p>
        </form>
        <hr>
        <h2>Export / Import Settings</h2>
        <form method="post" action="">
            <?php wp_nonce_field('calcs_plugin_export_action'); ?>
            <p><input type="submit" name="calcs_plugin_export" class="button button-secondary" value="Export Settings"></p>
        </form>
        <form method="post" action="" enctype="multipart/form-data">
            <?php wp_nonce_field('calcs_plugin_import_action'); ?>
            <p>
                <label for="calcs_plugin_import_file">Import Settings (JSON file):</label><br>
                <input type="file" name="calcs_plugin_import_file" id="calcs_plugin_import_file" accept=".json" required>
            </p>
            <p><input type="submit" name="calcs_plugin_import" class="button button-secondary" value="Import Settings"></p>
        </form>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Function to check for duplicate shortcodes
            function hasDuplicateShortcodes() {
                let shortcodes = [];
                let isDuplicate = false;
                $('input[name="shortcode_name[]"]').each(function() {
                    let value = $(this).val().trim();
                    if (value && shortcodes.includes(value)) {
                        isDuplicate = true;
                        return false; // Exit loop early if duplicate found
                    }
                    if (value) {
                        shortcodes.push(value);
                    }
                });
                return isDuplicate;
            }

    // Add new shortcode row
    $('#add-shortcode').click(function() {
        // Check for duplicates before adding new row
        if (hasDuplicateShortcodes()) {
            alert('Duplicate shortcode detected. Please ensure all shortcode names are unique.');
            return;
        }

        $('#shortcodes-table').append(
            '<tr>' +
            '<td><input type="text" name="name[]" required></td>' +
            '<td><input type="text" name="shortcode_name[]" required></td>' +
            '<td><input type="url" name="js_path[]" required style="width: 100%;"></td>' +
            '<td><input type="url" name="css_path[]" required style="width: 100%;"></td>' +
            '<td><button type="button" class="button button-secondary remove-row">Remove</button></td>' +
            '</tr>'
        );
    });

    // Remove shortcode row
    $(document).on('click', '.remove-row', function() {
        if ($('#shortcodes-table tr').length > 1) {
            $(this).closest('tr').remove();
        }
    });

    // Validate on form submission
    $('form').on('submit', function(e) {
        if (hasDuplicateShortcodes()) {
            e.preventDefault();
            alert('Duplicate shortcode detected. Please ensure all shortcode names are unique.');
        }
    });
});
</script>
    <style>
        .wp-list-table th, .wp-list-table td {
            padding: 10px;
        }
        .wp-list-table input[type="text"],
        .wp-list-table input[type="url"] {
            width: 100%;
            max-width: 400px;
        }
    </style>
    <?php
}

// Dynamic shortcode registration
add_action('init', 'calcs_plugin_register_shortcodes');
function calcs_plugin_register_shortcodes() {
    $shortcodes = get_option('calcs_plugin_shortcodes', array(
        array(
            'name' => 'Calculators United',
            'shortcode' => 'calculators-united',
            'js_path' => 'https://percentager-calculators.netlify.app/assets/index-Dn-eo0qD.js',
            'css_path' => 'https://percentager-calculators.netlify.app/assets/index-CjYIunhr.css'
        ),
        array(
            'name' => 'Calculator Derivative',
            'shortcode' => 'calculator-derivative',
            'js_path' => 'https://derivativecalculator.netlify.app/assets/index-BTYI-ye5.js',
            'css_path' => 'https://derivativecalculator.netlify.app/assets/index-CNmS3_Uq.css'
        )
    ));

    foreach ($shortcodes as $shortcode) {
        if ($shortcode['shortcode'] === 'gpa-scale') {
            // Specific handling for gpa-scale shortcode
            add_shortcode('gpa-scale', function($atts) use ($shortcode) {
                // Parse shortcode attributes
                $atts = shortcode_atts([
                    'gpa' => '',
                    'letter' => '',
                    'percent' => '',
                ], $atts, 'gpa-scale');

                // Build data attributes conditionally
                $data_attrs = '';
                if (!empty($atts['gpa'])) {
                    $data_attrs .= ' data-default-gpa="' . esc_attr($atts['gpa']) . '"';
                }
                if (!empty($atts['letter'])) {
                    $data_attrs .= ' data-default-letter="' . esc_attr($atts['letter']) . '"';
                }
                if (!empty($atts['percent'])) {
                    $data_attrs .= ' data-default-percent="' . esc_attr($atts['percent']) . '"';
                }

                // Enqueue scripts and styles
                wp_enqueue_script('main-js-gpa-scale', $shortcode['js_path'], [], '1.0.0', true);
                wp_enqueue_style('main-css-gpa-scale', $shortcode['css_path'], [], '1.0.0');

                // Output the React app container
                ob_start();
                ?>
                <div id="gpa-converter-app" <?php echo $data_attrs; ?>></div>
                <?php
                return ob_get_clean();
            });
            } elseif ($shortcode['shortcode'] === 'gpa_conversion') {
                // New handling for gpa_conversion
                add_shortcode('gpa_conversion', function($atts) use ($shortcode) {
                    // Parse shortcode attributes
                    $atts = shortcode_atts([
                        'country' => '',
                    ], $atts, 'gpa_conversion');

                    // Build data attributes conditionally
                    $data_attrs = '';
                    if (!empty($atts['country'])) {
                        $data_attrs .= ' data-country="' . esc_attr($atts['country']) . '"';
                    }

                    // Enqueue scripts and styles
                    wp_enqueue_script('main-js-gpa-conversion', $shortcode['js_path'], [], '1.0.0', true);
                    wp_enqueue_style('main-css-gpa-conversion', $shortcode['css_path'], [], '1.0.0');

                    // Output the React app container
                    ob_start();
                    ?>
                    <div id="gpa-conversion-app" <?php echo $data_attrs; ?>></div>
                    <?php
                    return ob_get_clean();
                });
            }  else {
            // Generic handling for other shortcodes
            add_shortcode($shortcode['shortcode'], function() use ($shortcode) {
                // Debug message
                error_log($shortcode['shortcode'] . ' called.');

                // Enqueue scripts and styles
                wp_enqueue_script('main-js-' . $shortcode['shortcode'], $shortcode['js_path'], array(), '1.0.0', true);
                wp_enqueue_style('main-css-' . $shortcode['shortcode'], $shortcode['css_path'], array(), '1.0.0');

                // Frontend part
                ob_start();
                include_once 'calculators/calculators.php';
                return ob_get_clean();
            });
        }
    }

}
?>