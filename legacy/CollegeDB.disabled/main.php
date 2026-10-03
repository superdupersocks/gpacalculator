<?php
/*
Plugin Name: CollegeDB
Description: This plugin generates a table of more than 1500 colleges with the ability to filter and sort them.
Version: 1.0
Author: Walt Green.
*/

require_once 'base_table_class.php';
require_once 'sortable_table_class.php';

require_once 'vendor/autoload.php';
use League\Csv\Reader;

class CollegeDB{
  private $sortable_table;

  public function __construct(){
    $this->sortable_table = new CollegeDB_sortable_table();

    add_action('admin_menu', array($this, 'admin_init'));
    add_action( 'wp_enqueue_scripts', array($this, 'enqueue_scripts'));

    add_shortcode('CollegeDB_full', array($this->sortable_table, 'render_full_table'));
    add_shortcode('CollegeDB', array($this->sortable_table, 'render_pre_filtered_table'));

    add_action('wp_ajax_cdb_search', array($this, 'handle_ajax_search'));
    add_action('wp_ajax_nopriv_cdb_search', array($this, 'handle_ajax_search'));

    add_action('wp_ajax_cdb_change_url', array($this, 'handle_ajax_change_url'));
    add_action('wp_ajax_nopriv_cdb_change_url', array($this, 'handle_ajax_change_url'));
  }

  public function enqueue_scripts(){
    $dir = plugin_dir_url(__FILE__);

    wp_enqueue_style('cdb_main', $dir . 'css/main.css');
    wp_enqueue_style('jquery-ui', 'https://code.jquery.com/ui/1.11.2/themes/smoothness/jquery-ui.css');

    wp_enqueue_script('jquery-ui', 'https://code.jquery.com/ui/1.11.2/jquery-ui.js', array('jquery'));
    wp_enqueue_script('college-db', $dir . 'js/application.js', array('jquery'));
  }

  public function admin_init(){
    add_submenu_page('plugins.php', 'CollegeDB', 'CollegeDB', 'manage_options', 'CollegeDB', array($this, 'render_menu'));
  }

  public function handle_ajax_change_url(){
    $table = new CollegeDB_table();
    $table->change_url($_POST['id'], $_POST['url']);
    echo 'true';
    die();
  }

  public function handle_ajax_search(){
    $params = array(
      'state' => $_POST['state'],
      'score_type' => $_POST['score_type'],
      'score' => $_POST['score'],
      'page' => $_POST['page']
    );
    echo json_encode($this->sortable_table->get_rows($params));
    die();
  }

  public function create_db(){
    global $wpdb;

    //all this stuff won't work on php < 5.4.0 :(
    $db_name = $wpdb->prefix . 'college_db';
    $wpdb->query("DROP TABLE IF EXISTS `$db_name` ");

    $file = $_FILES['db_file']['tmp_name'];
    $csv = Reader::createFromPath($file)->setDelimiter(',');
    $headers = $csv->fetchOne(0);
    $first_row = $csv->fetchOne(1);
    $columns = array("`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT");
    $col_names = array();
    foreach($headers as $v){
      $col_names[] = strtolower(trim($v));
    }

    //determining data types based on first row
    foreach($first_row as $i=>$v){
      $col_name = $col_names[$i];
      switch($this->get_type($v)){
        case 'integer':
          $columns[] = "`$col_name` INT NULL";
          break;
        case 'float':
          $columns[] = "`$col_name` FLOAT NULL";
          break;
        default:
          $columns[] = "`$col_name` varchar(255) NULL DEFAULT ''";
          break;
      }
    }

    $columns[] = "PRIMARY KEY (`id`)";
    $columns = implode(',', $columns);

    $wpdb->query("CREATE TABLE `$db_name` ($columns) ENGINE=MyISAM DEFAULT CHARSET=utf8 ;");
    print_r($wpdb->last_error);

    //inserting data into db
    $csv->setOffset(1);
    $csv->each(function($row) use ($db_name, &$wpdb, $col_names){
      $columns = array();
      $values_query = array();
      $values_actual = array();
      foreach($row as $i=>$v){
        $v = trim($v);
        $columns[] =  $col_names[$i];
        switch($this->get_type($v)){
          case 'integer':
            $values_query[] = '%d';
            $values_actual[] = preg_replace('/[^\d]+/', '', $v);
            break;
          case 'float':
            $values_query[] = '%f';
            $values_actual[] = str_replace(',', '.', $v);
            break;
          default:
            $values_query[] = '%s';
            $values_actual[] = $v;
            break;
        }
      }
      $columns = implode(',', $columns);
      $values_query = implode(',', $values_query);

      $wpdb->query(
        $wpdb->prepare("INSERT INTO `$db_name` ($columns) VALUES ($values_query)", $values_actual)
      );
      return true;
    });
  }

  public function get_type($s){
    $s = trim($s);
    if(preg_match('/^[0-9]+%?$/', $s)){
      return 'integer';
    }
    if(preg_match('/^[0-9.,]+$/', $s)){
      return 'float';
    }
    return 'string';
  }

  public function render_menu(){
    if(isset($_FILES['db_file'])){
      $this->create_db();
    }

    echo '<div class="wrap">';
    echo '<h2>College DB</h2>';

    echo '<form enctype="multipart/form-data" method="post" action="plugins.php?page=CollegeDB">';

    echo '<label for="db_file"><h3>Chose CSV file:</h3></label>';
    echo '<input type="file" class=""  name="db_file" id="db_file">';


    echo '<input class="button" value="Upload" type="submit"></div>';
    echo '</form>';

    echo '</div>';
  }
}

new CollegeDB();