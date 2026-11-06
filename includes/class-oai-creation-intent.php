<?php
/**
 * One canonical identity for a creation intent.
 *
 * Creation paths reserve a unique WordPress option before they write an
 * artifact. The option name is the atomic admission barrier; this class only
 * decides whether two requests describe the same logical creation. It never
 * releases, rewrites or expires a reservation.
 *
 * Canonical rules (documented in docs/CREATION-INTENT.md):
 * - associative array keys are sorted with SORT_STRING at every depth;
 * - list order is semantic and is preserved;
 * - strings are encoded as UTF-8 JSON with unescaped Unicode and slashes;
 * - integers/floats keep their JSON representation; -0.0 is preserved.
 *
 * Records written before payload_scheme/payload_fingerprint existed cannot be
 * compared safely. They are classified as legacy_unverifiable and must remain
 * held for evidence-based reconciliation.
 */
if (!defined('ABSPATH')) exit;

final class OAI_Creation_Intent {
 const SCHEME='oai/creation-intent/v1';
 const MAX_DEPTH=128;

 /** Deterministic, recursively key-sorted copy. Lists keep their order. */
 public static function canonical($value,$depth=0){
  if(!is_array($value))return $value;
  // Extremely deep caller input is left untouched and will normally fail JSON
  // encoding; never recurse without a bound.
  if($depth>self::MAX_DEPTH)return $value;
  if(!array_is_list($value))ksort($value,SORT_STRING);
  foreach($value as $key=>$item)$value[$key]=self::canonical($item,$depth+1);
  return $value;
 }

 /** Canonical JSON text, or null when the payload cannot be encoded. */
 public static function encode($value){
  $encoded=wp_json_encode(self::canonical($value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
  return is_string($encoded)?$encoded:null;
 }

 /** SHA-256 of the canonical payload, or null when it cannot be encoded. */
 public static function fingerprint($payload){
  $encoded=self::encode($payload);
  return $encoded===null?null:hash('sha256',$encoded);
 }

 /** Attach the canonical scheme to a new reservation record. */
 public static function record($fingerprint,array $fields=array()){
  return array_merge($fields,array(
   'payload_scheme'=>self::SCHEME,
   'payload_fingerprint'=>(string)$fingerprint,
   // Compatibility for existing readers and older diagnostics. New code must
   // use payload_fingerprint/classify(), never payload_hash for comparisons.
   'payload_hash'=>(string)$fingerprint,
  ));
 }

 public static function has_canonical($record){
  return is_array($record)
   &&($record['payload_scheme']??'')===self::SCHEME
   &&is_string($record['payload_fingerprint']??null)
   &&preg_match('/^[a-f0-9]{64}$/D',$record['payload_fingerprint'])===1;
 }

 /**
  * @return string same|conflict|legacy_unverifiable|unreadable
  * - same: canonical record with the identical normalized payload;
  * - conflict: canonical record for a deliberately different payload;
  * - legacy_unverifiable: historical record without a canonical fingerprint.
  *   Never guessed equal, never released by age.
  */
 public static function classify($record,$fingerprint){
  if(!is_array($record))return 'unreadable';
  if(!self::has_canonical($record))return 'legacy_unverifiable';
  if(!is_string($fingerprint)||preg_match('/^[a-f0-9]{64}$/D',$fingerprint)!==1)return 'unreadable';
  return hash_equals((string)$record['payload_fingerprint'],$fingerprint)?'same':'conflict';
 }
}
