<?php
/*
Plugin Name: University Template
Description: This plugin adds shortcode [UniversityTemplate] which renders university pages.
Version: 1.0
Author: Walt Green.
*/

namespace WaltGreen\UniversityTemplate;

class Plugin {
  public function __construct() {
    add_shortcode('UniversityTemplate', array($this, 'render_template'));
    $this->update_stuff();
  }

  public function render_template($atts) {
    global $post;

    wp_enqueue_style('font-awesome', '//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css');
    wp_enqueue_style('university-template', plugin_dir_url(__FILE__) . 'university_template.css');

    foreach($atts as $k=>$v) {
      $atts[$k] = trim($v);
    }

    $atts = shortcode_atts(array(
      'institution_name' => '-',
      'address' => '-',
      'city' => '-',
      'state' => '-',
      'zip_c' => '-',
      'phone_number' => '-',
      'website' => '-',
      'tuition_and_fees' => '-',
      'open_admission' => '-',
      'secondary_school_gpa' => '-',
      'secondary_school_rank' => '-',
      'secondary_school_record' => '-',
      'completion_of_college_prep_program' => '-',
      'recommendations' => '-',
      'formal_demonstration_of_competencies' => '-',
      'admission_test_scores' => '-',
      'other_test_wonderlic_wisc_iii_etc' => '-',
      'toefl' => '-',
      'dual_credit' => '-',
      'credit_for_life_experiences' => '-',
      'advanced_placement_ap_credits' => '-',
      'percent_of_students_submitting_sat_scores' => '-',
      'percent_of_students_submitting_act_scores' => '-',
      'sat_reading_25th_percentile' => '-',
      'sat_reading_75th_percentile' => '-',
      'sat_math_25th_percentile' => '-',
      'sat_math_75th_percentile' => '-',
      'sat_writing_25th_percentile' => '-',
      'sat_writing_75th_percentile' => '-',
      'act_composite_25th_percentile' => '-',
      'act_composite_75th_percentile' => '-',
      'act_english_25th_percentile' => '-',
      'act_english_75th_percentile' => '-',
      'act_math_25th_percentile' => '-',
      'act_math_75th_percentile' => '-',
      'act_writing_25th_percentile' => '-',
      'act_writing_75th_percentile' => '-',
      'total_applicants' => '-',
      'applicants_men' => '-',
      'applicants_women' => '-',
      'total_admissions' => '-',
      'admissions_men' => '-',
      'admissions_women' => '-',
      'total_students' => '-',
      'full_time_students' => '-',
      'part_time_students' => '-',
      'total_undergrads' => '-',
      'total_graduates' => '-',
      'percent_admitted' => '-',
      'percent_admitted_men' => '-',
      'percent_admitted_women' => '-'
    ), $atts, 'UniversityTemplate');
    extract($atts);

//    var_dump(get_post_meta($post->ID, '_yoast_wpseo_title', true));

    $path = dirname(__FILE__) . '/template.php';
    ob_start();
    include($path);
    return ob_get_clean();
  }

  public function print_url($url) {
    if (preg_match('#$https?://#', $url)) return $url;
    return 'http://' . $url;
  }


  public function update_stuff() {
    global $wpdb;
    if (!isset($_GET['update_universities'])) return;

    $posts = $wpdb->get_results("select id, post_title from gpa_posts where post_author = 29 and post_status = 'publish'");
    foreach($posts as $post) {
      $name = $post->post_title;
      update_post_meta($post->id, '_yoast_wpseo_title', "{$name} GPA and Admission Test Requirements");
      update_post_meta($post->id, '_yoast_wpseo_metadesc', "Find {$name} GPA Calculator and GPA; SAT scores; ACT scores and scholarship and financial aid data of current and past students.");
      update_post_meta($post->id, '_yoast_wpseo_metakeywords', "{$name} GPA and Admission Test Requirements; {$name} GPA and Admission Test Requirements calculator; {$name} GPA and Admission Test Requirements and admission");
      update_post_meta($post->id, '_yoast_wpseo_focuskw', "{$name}");
    }
  }
}

new Plugin();