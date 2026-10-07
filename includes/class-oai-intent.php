<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;

/**
 * Normalizes natural-language requests into a deterministic, read-only intent.
 * It never writes data and deliberately returns confidence instead of guessing.
 */
class OAI_Intent {
 public static function normalize($intent,$context=array()){
  $intent=trim(wp_strip_all_tags((string)$intent));
  if($intent==='')return OAI_Errors::make('oai_intent','intent is required',400);
  $low=mb_strtolower($intent);
  $domain='site'; $action='inspect'; $signals=array();
  if(preg_match('/elementor|виджет|контейнер|секци|hero|заголов|кнопк/ui',$low)){ $domain='elementor'; $signals[]='elementor'; }
  elseif(preg_match('/woocommerce|товар|цена|стоимост|остат|склад|sku/ui',$low)){ $domain='woocommerce'; $signals[]='woocommerce'; }
  elseif(preg_match('/файл|css|php|style|functions|шаблон/ui',$low)){ $domain='files'; $signals[]='files'; }
  elseif(preg_match('/страниц|запис|контент|текст|заголовок/ui',$low)){ $domain='content'; $signals[]='content'; }
  elseif(preg_match('/меню/ui',$low)){ $domain='menus'; $signals[]='menus'; }
  elseif(preg_match('/пользовател|юзер|роль/ui',$low)){ $domain='users'; $signals[]='users'; }
  if(preg_match('/создай|создать|добавь|добавить|измени|изменить|поменяй|замени|обнови|обновить|сделай|сделать|удали|удалить|запиши|записать/ui',$low))$action='mutate';
  if(preg_match('/проверь|проверить|диагност|покажи|показать|найди|найти|прочитай|прочитать|получи|получить|посмотри/ui',$low))$action='inspect';
  if(preg_match('/план|спланируй|как лучше/ui',$low))$action='plan';
  if(preg_match('/откати|откатить|rollback|восстанови|восстановить/ui',$low))$action='rollback';
  $confidence=count($signals)?0.85:0.45;
  return array('contract'=>'ai-admin/intent/v1','intent'=>$intent,'domain'=>$domain,'action'=>$action,'signals'=>$signals,'confidence'=>$confidence,'safe_default'=>true,'requires_read_before_write'=>$action==='mutate','context'=>is_array($context)?$context:array());
 }
}

