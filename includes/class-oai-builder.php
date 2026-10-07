<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

interface OAI_Builder_Adapter {
 public function id();
 public function supports($post_id);
 public function read($post_id);
 public function schema($name='');
 public function save($post_id,array $data,array $options=array());
 public function create(array $args);
 public function verify($post_id,array $requested=array());
 public function preview($post_id);
 public function rollback($post_id,array $snapshot);
}

class OAI_Builder_Manager {
 private static $adapters=array();
 public static function register(OAI_Builder_Adapter $adapter){ self::$adapters[$adapter->id()]=$adapter; }
 public static function get($id){ return self::$adapters[$id]??null; }
 public static function all(){ return self::$adapters; }
 public static function detect($post_id){
  foreach(self::$adapters as $a){ if($a->supports($post_id)) return $a; }
  return null;
 }
 public static function info(){
  $out=array(); foreach(self::$adapters as $id=>$a)$out[$id]=array('id'=>$id,'supports'=>true); return $out;
 }
}

