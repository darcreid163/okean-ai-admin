<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
/**
 * Converts an AI request into a read-only execution plan.
 * No database or WordPress mutation is performed here.
 */
class OAI_Planner {
 public static function plan($req) {
  $normalized=OAI_Intent::normalize($req->get_param('intent'),$req->get_param('context'));
  if(is_wp_error($normalized)) return $normalized;
  return self::plan_normalized($normalized);
 }
 public static function plan_normalized($normalized) {
  $intent=(string)($normalized['intent']??'');
  $low=mb_strtolower($intent);
  $steps=array();
  $steps[]=array('step'=>1,'action'=>'inspect_site','description'=>'Получить состояние WordPress, темы и активных компонентов.','mutating'=>false);
  if(strpos($low,'elementor')!==false || strpos($low,'страниц')!==false || strpos($low,'страниц')!==false)
   $steps[]=array('step'=>2,'action'=>'inspect_content','description'=>'Найти целевую страницу и проверить Elementor/post_content.','mutating'=>false);
  if(strpos($low,'elementor')!==false || strpos($low,'hero')!==false || strpos($low,'виджет')!==false || strpos($low,'контейнер')!==false)
   $steps[]=array('step'=>count($steps)+1,'action'=>'inspect_elementor','description'=>'Найти Elementor node/widget, его settings и доступную schema controls.','mutating'=>false);
  if(strpos($low,'товар')!==false || strpos($low,'woocommerce')!==false || strpos($low,'цен')!==false)
   $steps[]=array('step'=>2,'action'=>'inspect_woocommerce','description'=>'Найти товары, цены, остатки и идентификаторы.','mutating'=>false);
  if(strpos($low,'файл')!==false || strpos($low,'css')!==false || strpos($low,'php')!==false)
   $steps[]=array('step'=>2,'action'=>'inspect_files','description'=>'Найти соответствующий файл в разрешённом scope.','mutating'=>false);
  $steps[]=array('step'=>count($steps)+1,'action'=>'build_sandbox','description'=>'Сформировать изменение в sandbox с before/after/diff.','mutating'=>true,'requires_approval'=>true);
  $steps[]=array('step'=>count($steps)+1,'action'=>'verify','description'=>'После применения проверить фактическое состояние объекта.','mutating'=>false);
  return array(
   'ok'=>true,'mode'=>'plan_only','direct_live_writes'=>false,
   'intent'=>$intent,'normalized_intent'=>$normalized,'steps'=>$steps,
   'approval_required'=>true,
   'recommended_flow'=>array('read','plan','sandbox','diff','approve','apply','verify','rollback')
  );
 }
}
