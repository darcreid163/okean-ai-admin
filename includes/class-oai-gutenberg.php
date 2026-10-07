<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Gutenberg { public static function blocks($id){return OAI_Gutenberg_Resolver::resolve_post($id);} public static function serialize($blocks){return serialize_blocks(is_array($blocks)?$blocks:array());} }

