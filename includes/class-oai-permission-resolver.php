<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Permission_Resolver {
 public static function resolve($cmd){$write=empty($cmd['read']);return array('allowed'=>current_user_can('manage_options'),'capability'=>'manage_options','write'=>$write,'approval_required'=>$write||!empty($cmd['approval']),'reason'=>current_user_can('manage_options')?'ok':'manage_options required');}
}

