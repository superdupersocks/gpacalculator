<?php
/**
 * ONE-SHOT MIGRATION — replace inline sample-table HTML in existing posts
 * with [gpa_table] shortcode blocks so future PHP edits propagate live.
 *
 * Usage: visit /?gpa_migrate_tables=1 while logged in as admin (one query
 * required to fire). Each post that matches gets a backup of its previous
 * content stored in post_meta `_gpa_table_migration_backup_<timestamp>`.
 *
 * DELETE THIS FILE AFTER A SUCCESSFUL RUN.
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'gpa_register_migration_runner');
function gpa_register_migration_runner() {
    if (!isset($_GET['gpa_migrate_tables'])) return;
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    add_action('wp', 'gpa_run_table_migration', 1);
}

function gpa_run_table_migration() {
    $dry = !empty($_GET['dry']);

    $replacements = [
        // Sample GPA Calculation table (3-col, calc-table) → sample-courses
        [
            'needles' => [
                '<!-- wp:table {"className":"calc-table"} -->',
                '<figure class="wp-block-table calc-table"><table class="has-fixed-layout"><thead><tr><th>Course</th><th>Grade</th><th>Credits</th></tr></thead><tbody><tr><td>ENG 101</td><td>A</td><td>3</td></tr><tr><td>MATH 121</td><td>B+</td><td>4</td></tr><tr><td>PSY 201</td><td>A&#8211;</td><td>3</td></tr><tr><td>BIO 110</td><td>C+</td><td>2</td></tr></tbody></table></figure>',
                '<!-- /wp:table -->',
            ],
            'shortcode' => '[gpa_table type="sample-courses"]',
        ],
        // GPA Calculation Formula table (5-col, calc-table-gray) → quality-points
        [
            'needles' => [
                '<!-- wp:table {"className":"calc-table calc-table-gray"} -->',
                '<figure class="wp-block-table calc-table calc-table-gray"><table class="has-fixed-layout"><thead><tr><th>Course</th><th>Credits</th><th>Grade</th><th>Grade Points</th><th>Quality Points</th></tr></thead><tbody><tr><td>ENG 101</td><td>3</td><td>A</td><td>4.0</td><td>12.0</td></tr><tr><td>MATH 121</td><td>4</td><td>B+</td><td>3.3</td><td>13.2</td></tr><tr><td>PSY 201</td><td>3</td><td>A&#8211;</td><td>3.7</td><td>11.1</td></tr><tr><td>BIO 110</td><td>2</td><td>C+</td><td>2.3</td><td>4.6</td></tr><tr><td><strong>Total</strong></td><td><strong>12</strong></td><td>&#8212;</td><td>&#8212;</td><td><strong>40.9</strong></td></tr></tbody></table></figure>',
                '<!-- /wp:table -->',
            ],
            'shortcode' => '[gpa_table type="quality-points"]',
        ],
        // Content sample table (5-col, content-table-gray) → content-sample
        [
            'needles' => [
                '<!-- wp:table {"className":"content-table content-table-gray"} -->',
                '<figure class="wp-block-table content-table content-table-gray"><table class="has-fixed-layout"><thead><tr><th>Course</th><th>Grade</th><th>Credits</th><th>Grade Points</th><th>Quality Points</th></tr></thead><tbody><tr><td>English 101</td><td>A</td><td>3</td><td>4.0</td><td>12.0</td></tr><tr><td>Math 121</td><td>B+</td><td>4</td><td>3.3</td><td>13.2</td></tr><tr><td>Biology 110</td><td>B</td><td>3</td><td>3.0</td><td>9.0</td></tr><tr><td><strong>Total</strong></td><td>&#8212;</td><td><strong>10</strong></td><td>&#8212;</td><td><strong>34.2</strong></td></tr></tbody></table></figure>',
                '<!-- /wp:table -->',
            ],
            'shortcode' => '[gpa_table type="content-sample"]',
        ],
    ];

    // Build candidate query: posts/pages whose content references the unique HTML markers.
    global $wpdb;
    $like_clauses = [];
    foreach ($replacements as $r) {
        $like_clauses[] = $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like('class="wp-block-table calc-table"') . '%');
        $like_clauses[] = $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like('class="wp-block-table calc-table calc-table-gray"') . '%');
        $like_clauses[] = $wpdb->prepare('post_content LIKE %s', '%' . $wpdb->esc_like('class="wp-block-table content-table content-table-gray"') . '%');
        break;
    }
    $where = '(' . implode(' OR ', $like_clauses) . ')';
    $rows = $wpdb->get_results(
        "SELECT ID, post_title, post_type, post_status, post_content
         FROM {$wpdb->posts}
         WHERE post_status IN ('publish','draft','pending','private')
           AND post_type IN ('post','page')
           AND {$where}",
        ARRAY_A
    );

    $report = [
        'dry_run' => (bool) $dry,
        'matches_found' => count($rows),
        'updated' => [],
        'no_change' => [],
        'errors' => [],
    ];

    foreach ($rows as $row) {
        $original = $row['post_content'];
        $new      = $original;
        $changes  = [];

        foreach ($replacements as $r) {
            $combined_old = implode("\n", $r['needles']);
            // Wrap shortcode in a wp:shortcode block to preserve block-aware editing.
            $combined_new = "<!-- wp:shortcode -->\n{$r['shortcode']}\n<!-- /wp:shortcode -->";

            if (strpos($new, $combined_old) !== false) {
                $new = str_replace($combined_old, $combined_new, $new);
                $changes[] = $r['shortcode'];
            }
        }

        if ($new === $original || empty($changes)) {
            $report['no_change'][] = ['ID' => $row['ID'], 'title' => $row['post_title']];
            continue;
        }

        if ($dry) {
            $report['updated'][] = [
                'ID' => $row['ID'],
                'title' => $row['post_title'],
                'changes' => $changes,
                'status' => 'would-update',
            ];
            continue;
        }

        // Backup current content
        $backup_key = '_gpa_table_migration_backup_' . time();
        update_post_meta($row['ID'], $backup_key, $original);

        $update = wp_update_post([
            'ID' => $row['ID'],
            'post_content' => $new,
        ], true);

        if (is_wp_error($update)) {
            $report['errors'][] = [
                'ID' => $row['ID'],
                'title' => $row['post_title'],
                'error' => $update->get_error_message(),
            ];
        } else {
            $report['updated'][] = [
                'ID' => $row['ID'],
                'title' => $row['post_title'],
                'changes' => $changes,
                'backup_meta_key' => $backup_key,
            ];
        }
    }

    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
