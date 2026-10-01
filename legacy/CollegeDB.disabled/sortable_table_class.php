<?php
class CollegeDB_sortable_table extends CollegeDB_table{

  public function render_table($defaults, $css_class=""){
    $dir = dirname(__FILE__);
//    $include_url = plugin_dir_url(__FILE__);
    $ajax_url = admin_url('admin-ajax.php');
    $html = file_get_contents($dir.'/templates/layout.html');

    $preloaded = array(
      'defaults' => $defaults,
      'entries' => $this->get_rows($defaults)
    );
    $preloaded = json_encode($preloaded);

    $res = <<<EOT
    <div ng-app="cdb" id="college_db_full" class="$css_class">
     <script type="application/javascript">
        angular.module('cdb.preloaded', [])
        .constant('\$preloaded', $preloaded)
        .constant('ajaxURL', '$ajax_url');
      </script>
      $html
    </div>
EOT;

    return $res;
  }

  public function render_full_table(){
    $defaults = array(
      'state' => 'All',
      'score_type' => 'GPA',
      'score' => '5',
      'page' => '1'
    );
    return $this->render_table($defaults);
  }

  public function render_pre_filtered_table($atts){
    $atts = shortcode_atts(array(
      'gpa' => '5',
    ), $atts, 'CollegeDB');

    if(preg_match('/[^0-9.]/', $atts['gpa'])){
      return false;
    }

    $defaults = array(
      'state' => 'All',
      'score_type' => 'GPA',
      'score' => $atts['gpa'],
      'page' => '1'
    );
    return $this->render_table($defaults);
  }
}