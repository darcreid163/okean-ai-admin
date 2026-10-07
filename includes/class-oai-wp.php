<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_WP {
 public static function plugins(){require_once ABSPATH.'wp-admin/includes/plugin.php';return get_plugins();}
 public static function themes(){require_once ABSPATH.'wp-admin/includes/theme.php';return wp_get_themes();}
 public static function menus(){return wp_get_nav_menus();}
 public static function taxonomies(){return get_taxonomies(array(),'objects');}
 public static function post_types(){return get_post_types(array(),'objects');}
}

