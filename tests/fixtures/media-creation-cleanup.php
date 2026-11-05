<?php
/** Fixture-owned cleanup only; never packaged as a production recovery tool. */
function oai_fixture_media_claim_cleanup($id){
 if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1')throw new RuntimeException('Disposable fixture cleanup only');
 global $wpdb;$row=OAI_DB::get((int)$id);
 if(!$row||$row['type']!=='media_upload')return;
 $meta=json_decode((string)$row['meta_data'],true);$fingerprint=$meta['_wpai_creation_intent']??'';
 if(!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))return;
 $name='oai_media_creation_'.$fingerprint;$raw=$wpdb->get_var($wpdb->prepare('SELECT option_value FROM '.$wpdb->options.' WHERE option_name=%s',$name));$record=json_decode((string)$raw,true);
 if(!is_array($record)||($record['operation_id']??0)!==(int)$id||hash('sha256',(string)($record['claim']??''))!==($meta['_wpai_creation_claim']??''))return;
 $wpdb->query($wpdb->prepare('DELETE FROM '.$wpdb->options.' WHERE option_name=%s AND BINARY option_value=BINARY %s',$name,$raw));
 wp_cache_delete($name,'options');wp_cache_delete('notoptions','options');
}
