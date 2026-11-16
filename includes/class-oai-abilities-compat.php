<?php
/**
 * WordPress Abilities discovery compatibility.
 *
 * Copyright (C) 2026 Андриянов Н.Г.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This is an original WP AI Admin adapter informed by the public WordPress
 * Abilities API and the WPVibe implementation (GPL-2.0). No WPVibe source is
 * copied into this file. Execution is intentionally not exposed here: every
 * mutating ability must first be mapped to a WP AI Admin operation.
 */
if (!defined('ABSPATH')) exit;

class OAI_Abilities_Compat {
 const MAX_PER_PAGE = 100;

 private static function mcp_public($meta) {
  if (!is_array($meta)) return false;
  $mcp = isset($meta['mcp']) ? $meta['mcp'] : array();
  if (!is_array($mcp)) return false;
  if (array_key_exists('public', $mcp)) return true === $mcp['public'];
  return isset($meta['public']) && true === $meta['public'];
 }

 private static function rest_public($meta) {
  return is_array($meta) && !empty($meta['show_in_rest']);
 }

 private static function is_exposed($meta) {
  return self::rest_public($meta) || self::mcp_public($meta);
 }

 private static function schema_for_client($schema) {
  if (!is_array($schema)) return array();
  return function_exists('wp_prepare_json_schema_for_client')
   ? wp_prepare_json_schema_for_client($schema)
   : $schema;
 }

 private static function item($ability) {
  return array(
   'name' => (string)$ability->get_name(),
   'label' => (string)$ability->get_label(),
   'description' => (string)$ability->get_description(),
   'category' => (string)$ability->get_category(),
   'input_schema' => self::schema_for_client($ability->get_input_schema()),
   'output_schema' => self::schema_for_client($ability->get_output_schema()),
   'meta' => $ability->get_meta(),
   'execution_policy' => 'plan_required',
  );
 }

 public static function list_public($category='', $page=1, $per_page=50) {
  if (!function_exists('wp_get_abilities') || !current_user_can('read')) {
   return new WP_Error('oai_abilities_unavailable', 'WordPress Abilities API is unavailable or access is denied', array('status'=>403));
  }
  $category = sanitize_key((string)$category);
  $page = max(1, (int)$page);
  $per_page = max(1, min(self::MAX_PER_PAGE, (int)$per_page));
  $all = array();
  foreach ((array)wp_get_abilities() as $ability) {
   if (!is_object($ability) || !method_exists($ability, 'get_meta')) continue;
   $meta = $ability->get_meta();
   if (!self::is_exposed($meta)) continue;
   $name = (string)$ability->get_name();
   if ($name === '' || strlen($name) > 191) continue;
   if ($category !== '' && $category !== (string)$ability->get_category()) continue;
   $all[] = $ability;
  }
  usort($all, static function($a,$b) {
   return strcmp((string)$a->get_name(), (string)$b->get_name());
  });
  $total = count($all);
  $items = array();
  foreach (array_slice($all, ($page-1)*$per_page, $per_page) as $ability) {
   $items[] = self::item($ability);
  }
  return array(
   'items'=>$items, 'total'=>$total, 'page'=>$page, 'per_page'=>$per_page,
   'total_pages'=>(int)ceil($total/$per_page),
   'execution'=>'metadata_only; invoke through WP AI Admin planner',
  );
 }

 public static function get_public($name) {
  $name = is_string($name) ? trim($name) : '';
  if ($name === '' || strlen($name) > 191 || !function_exists('wp_has_ability') || !wp_has_ability($name)) {
   return new WP_Error('oai_ability_not_found', 'Ability not found or not exposed', array('status'=>404));
  }
  $ability = wp_get_ability($name);
  if (!$ability || !self::is_exposed($ability->get_meta())) {
   return new WP_Error('oai_ability_not_found', 'Ability not found or not exposed', array('status'=>404));
  }
  return self::item($ability);
 }

 /**
  * Never execute an arbitrary registered ability directly. A separate
  * operation adapter must declare the ability safe, validate its input,
  * produce a sandbox diff, and route apply through Approval Engine.
  */
 public static function run($name, $input=array()) {
  $definition = self::get_public($name);
  if (is_wp_error($definition)) return $definition;
  if (!is_array($input)) return new WP_Error('oai_ability_input', 'Ability input must be an object', array('status'=>400));
  return new WP_Error(
   'oai_ability_requires_plan',
   'Ability execution is not exposed directly. Create a mapped WP AI Admin sandbox operation and require explicit approval.',
   array('status'=>409, 'ability'=>$name, 'execution_policy'=>'plan_required')
  );
 }
}
