<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Diagnostics {
 public static function run() {
  global $wpdb;
  require_once ABSPATH.'wp-admin/includes/plugin.php';
  $theme=wp_get_theme();
  $checks=array();
  $checks['core']=array('wp_version'=>get_bloginfo('version'),'php_version'=>PHP_VERSION,'site_url'=>home_url('/'),'wp_url'=>site_url('/'));
  $checks['theme']=array('name'=>$theme->get('Name'),'version'=>$theme->get('Version'),'stylesheet'=>$theme->get_stylesheet(),'template'=>$theme->get_template());
  $checks['database']=array('ok'=>(bool)$wpdb->get_var('SELECT 1'),'prefix'=>$wpdb->prefix,'last_error'=>$wpdb->last_error?:null);
  $uploads=wp_upload_dir();
  $checks['filesystem']=array(
   'uploads_path'=>'wp-content/uploads',
   'uploads_writable'=>is_writable($uploads['basedir']),
   'stylesheet_directory'=>'wp-content/themes/'.$theme->get_stylesheet()
  );
  $checks['rest']=array('rest_url'=>rest_url(),'home_url'=>home_url('/'));
  $checks['woocommerce']=array('active'=>class_exists('WooCommerce'),'version'=>defined('WC_VERSION')?WC_VERSION:null);
  $checks['elementor']=array('active'=>defined('ELEMENTOR_VERSION'),'version'=>defined('ELEMENTOR_VERSION')?ELEMENTOR_VERSION:null);
  $checks['memory']=array('limit'=>ini_get('memory_limit'),'usage'=>function_exists('memory_get_usage')?memory_get_usage(true):null,'peak'=>function_exists('memory_get_peak_usage')?memory_get_peak_usage(true):null);
  $checks['plugins']=array('active'=>count(get_option('active_plugins',array())),'total'=>count(get_plugins()));
  $issues=array();
  foreach($checks as $name=>$data){
   $ok=true;
   if($name==='database') $ok=!empty($data['ok']);
   if($name==='filesystem') $ok=!empty($data['uploads_writable']);
   $issues[]=array('component'=>$name,'status'=>$ok?'ok':'warning','details'=>$data);
  }
  return array('ok'=>true,'generated_at'=>current_time('mysql',true),'issues'=>$issues,'checks'=>$checks);
 }
}
