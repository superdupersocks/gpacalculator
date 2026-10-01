<div class="university-template-wrap">

  <?php if ($website !== '-'): ?>
  <div class="website">
    <a href="<?= $this->print_url($website); ?>" target="_blank"><?= $website; ?></a>
  </div>
  <?php endif; ?>

  <!-- .box-container -->
  <div class="box-container">
    <div class="box">
      <div class="box-title">
        <i class="fa fa-money"></i>
      </div>
      <div class="box-content with-sub">
        <div class="main-content"><?= $tuition_and_fees; ?></div>
        <div class="sub-content">Tuition & Fees</div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <i class="fa fa-user"></i>
        <i class="fa fa-user"></i>
        <i class="fa fa-user"></i>
      </div>
      <div class="box-content with-sub">
        <div class="main-content"><?= $full_time_students; ?></div>
        <div class="sub-content">Full Time Enrollment</div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <i class="fa fa-user"></i>
        <i class="fa fa-thumbs-up"></i>
      </div>
      <div class="box-content with-sub">
        <div class="main-content"><?= $percent_admitted; ?></div>
        <div class="sub-content">Percent Admitted</div>
      </div>
    </div>
  </div>
  <!-- /.box-container -->

  <div class="ad-block">
    <?php echo do_shortcode('[adrotate banner="3"]'); ?>
  </div>

  <h3 class="calculator-title"><?= $institution_name; ?> GPA Calculator</h3>
  <?php echo do_shortcode('[gpacollege]'); ?>

  <!-- institution overview -->
  <h2 class="section-title"><?= $institution_name; ?> Overview</h2>
  <h3 class="admission-subtitle"><?= $percent_admitted; ?> of applicants are admitted.</h3>
  <div class="admission-table">
    <div class="row">
      <div class="title">Total Applicants</div>
      <div class="value"><?= $total_applicants; ?></div>
    </div>
    <div class="row">
      <div class="title">Total Admissions</div>
      <div class="value"><?= $total_admissions; ?></div>
    </div>
    <div class="row">
      <div class="title">% Admitted</div>
      <div class="value"><?= $percent_admitted; ?></div>
    </div>

    <div class="row">
      <div class="title">Total Students</div>
      <div class="value"><?= $total_students; ?></div>
    </div>
    <div class="row">
      <div class="title">Full-time Students</div>
      <div class="value"><?= $full_time_students; ?></div>
    </div>
    <div class="row">
      <div class="title">Part-time Students</div>
      <div class="value"><?= $part_time_students; ?></div>
    </div>
    <div class="row">
      <div class="title">Total Undergrads</div>
      <div class="value"><?= $total_undergrads; ?></div>
    </div>
    <div class="row">
      <div class="title">Total Graduates</div>
      <div class="value"><?= $total_graduates; ?></div>
    </div>


    <div class="women">
      <div class="row">
        <div class="title">Applicants - Women</div>
        <div class="value"><?= $applicants_women; ?></div>
      </div>
      <div class="row">
        <div class="title">Admissions - Women</div>
        <div class="value"><?= $admissions_women; ?></div>
      </div>
      <div class="row">
        <div class="title">% Admitted - Women</div>
        <div class="value"><?= $percent_admitted_women; ?></div>
      </div>
    </div>

    <div class="men">
      <div class="row">
        <div class="title">Applicants - Men</div>
        <div class="value"><?= $applicants_men; ?></div>
      </div>
      <div class="row">
        <div class="title">Admissions - Men</div>
        <div class="value"><?= $admissions_men; ?></div>
      </div>
      <div class="row">
        <div class="title">% Admitted - Men</div>
        <div class="value"><?= $percent_admitted_men; ?></div>
      </div>
    </div>
  </div>
  <!-- /institution overview -->


  <!-- act -->
  <h2 class="section-title">ACT</h2>
  <div class="box-container">
    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Scores</div>
        <div class="sub-title">% of Students Submitting</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $percent_of_students_submitting_act_scores; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Composite</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_composite_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Composite</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_composite_75th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT English</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_english_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT English</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_english_75th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Math</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_math_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Math</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_math_75th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Writing</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_writing_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">ACT Writing</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $act_writing_75th_percentile; ?></div>
      </div>
    </div>
  </div>
  <!-- /act -->

  <!-- sat -->
  <h2 class="section-title">SAT</h2>
  <div class="box-container">
    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Scores</div>
        <div class="sub-title">% of Students Submitting</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $percent_of_students_submitting_sat_scores; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Reading</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_reading_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Reading</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_reading_75th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Math</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_math_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Math</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_math_75th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Writing</div>
        <div class="sub-title">25th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_writing_25th_percentile; ?></div>
      </div>
    </div>

    <div class="box">
      <div class="box-title">
        <div class="main-title">SAT Writing</div>
        <div class="sub-title">75th Percentile</div>
      </div>
      <div class="box-content">
        <div class="main-content"><?= $sat_writing_75th_percentile; ?></div>
      </div>
    </div>
  </div>
  <!-- /act -->


  <!-- admission requirements -->
  <h2 class="section-title">Admission Requirements</h2>
  <div class="admission-table orange">
    <div class="row">
      <div class="title">Open Admission</div>
      <div class="value"><?= $open_admission; ?></div>
    </div>
    <div class="row">
      <div class="title">Secondary School GPA</div>
      <div class="value"><?= $secondary_school_gpa; ?></div>
    </div>
    <div class="row">
      <div class="title">Secondary School Rank</div>
      <div class="value"><?= $secondary_school_rank; ?></div>
    </div>
    <div class="row">
      <div class="title">Secondary School Record</div>
      <div class="value"><?= $secondary_school_record; ?></div>
    </div>
    <div class="row">
      <div class="title">Completion of College Prep Program</div>
      <div class="value"><?= $completion_of_college_prep_program; ?></div>
    </div>
    <div class="row">
      <div class="title">Recommendations</div>
      <div class="value"><?= $recommendations; ?></div>
    </div>
    <div class="row">
      <div class="title">Formal Demonstration of Competencies</div>
      <div class="value"><?= $formal_demonstration_of_competencies; ?></div>
    </div>
    <div class="row">
      <div class="title">Admission Test Scores</div>
      <div class="value"><?= $admission_test_scores; ?></div>
    </div>
    <div class="row">
      <div class="title">Other Test (Wonderlic, WISC-III, etc)</div>
      <div class="value"><?= $other_test_wonderlic_wisc_iii_etc; ?></div>
    </div>
    <div class="row">
      <div class="title">TOEFL</div>
      <div class="value"><?= $toefl; ?></div>
    </div>
    <div class="row">
      <div class="title">Dual Credit</div>
      <div class="value"><?= $dual_credit; ?></div>
    </div>
    <div class="row">
      <div class="title">Credit For Life Experiences</div>
      <div class="value"><?= $credit_for_life_experiences; ?></div>
    </div>
    <div class="row">
      <div class="title">Advanced placement (AP) Credits</div>
      <div class="value"><?= $advanced_placement_ap_credits; ?></div>
    </div>
  </div>
  <!-- /admission requirements -->


<h2 class="section-title">Location & Contact</h2>
<div class="location-wrapper">
  <div class="address">
    <div class="institution-name">
      <?= $institution_name; ?>
    </div>
    <div class="main-address">
      <?= $address; ?>, <?= $city; ?>, <?= $state; ?> <?= $zip_c; ?>
    </div>
    <div class="contact-line">
      <span class="name">Phone:</span> <span class="value"><?= $phone_number; ?></span>
    </div>
    <?php if ($website !== '-'): ?>
      <div class="contact-line">
        <span class="name">Website:</span> <span class="value">
          <a href="<?= $this->print_url($website); ?>" target="_blank"><?= $website; ?></a>
        </span>
      </div>
    <?php endif; ?>


  </div>
  <div class="google-map">
    <?php
      $query = "$address, $city, $state, $zip_c, USA";
      $query = urlencode($query);
    ?>
    <iframe width="100%" height="450" frameborder="0" style="border:0"
            src="https://www.google.com/maps/embed/v1/place?q=<?= $query; ?>&key=AIzaSyDBxufF4Sruda1MMD0bcVpr5v7rb1OpIoQ"></iframe>
  </div>
</div>
</div>
