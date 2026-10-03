<?php

class CollegeDB_table{
  protected $wpdb;
  protected $db_name;
  protected $settings;

  public function __construct(){
    global $wpdb;

    $this->db_name = $wpdb->prefix . 'college_db';
    $this->wpdb = $wpdb;
    $this->settings = $this->get_settings();
  }

  public function get_settings(){
    $settings = unserialize(get_option('college_db_metadata'));
    return $settings;
  }

  public function get_column_name($title){
    foreach($this->settings['columns'] as  $column=>$t){
      if($t == $title){
        return $column;
      }
    }
    return false;
  }

  public function change_url($id, $new_url){
    $this->wpdb->query(
      $this->wpdb->prepare("UPDATE {$this->db_name} SET `url`=%s WHERE `id`=%d", array($new_url, $id))
    );

    $test = $this->wpdb->last_error;
    return $test;
  }

  public function get_rows($params = array()){
    $limit = 20;
    $conditions = array();
    $order = "school ASC";

    if(isset($params['state']) && $params['state'] != 'All'){
      $params['state'] = addslashes($params['state']);
      $conditions[] = "state = '{$params['state']}'";
    }

    if(isset($params['score_type']) && in_array($params['score_type'], array('GPA', 'SAT', 'ACT'))){
      $params['score'] = addslashes(substr($params['score'], 0, 6)); //limits the length of score to 6 characters and adds slashes to special characters
      if($params['score_type'] == 'GPA'){
        $conditions[] = "gpa <= {$params['score']}";
        $order = "gpa DESC";
      }
      if($params['score_type'] == 'SAT'){
        $conditions[] = "sat25 <= {$params['score']}";
        $conditions[] = "sat75 >= {$params['score']}";
        $order = "sat75 DESC";
      }
      if($params['score_type'] == 'ACT'){
        $conditions[] = "act25 <= {$params['score']}";
        $conditions[] = "act75 >= {$params['score']}";
        $order = "act75 DESC";
      }
    }
    if(isset($params['page'])){
      $current_page = addslashes($params['page']);
    }else{
      $current_page = 1;
    }
    $offset = $limit * ($current_page - 1);

    if(!empty($conditions)){
      $conditions = 'WHERE ' . implode(' AND ', $conditions);
    }else{
      $conditions = '';
    }

    $result = array();
    $result['rows'] = $this->wpdb->get_results("
      SELECT * FROM {$this->db_name}
      {$conditions}
      ORDER BY {$order}
      LIMIT {$limit} OFFSET {$offset}
      ");

    foreach($result['rows'] as $i=>$v){
      if($v->gpa > 0){
        $v->gpa = number_format($v->gpa, 1);
      } else {
        $v->gpa = '-';
      }
      $v->acceptance_rate .= '%';
    }

    $count = $this->wpdb->get_row("
      SELECT COUNT(*) FROM {$this->db_name}
      {$conditions}
      ");
    $last_error = $this->wpdb->last_error;
    $num_pages = ceil($count->{'COUNT(*)'} / $limit);
    $pages = array();
    $i = -1;
    foreach(range(1, $num_pages) as $page){
      if($page != 0 && (abs($page-$current_page) <= 2 || $page == 1 || $page == $num_pages || $page == $current_page)){
        if($i > -1 && $page - $pages[$i] > 1){
          $pages[] = '...';
          $i++;
        }
        $pages[] = $page;
        $i++;
      }
    }

    $result['pagination'] = $pages;
    $result['current_page'] = $current_page;
    return $result;
  }

}