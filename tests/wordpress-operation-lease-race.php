<?php
// Real WordPress option reads interleaved with a competing request's lease.
wp_set_current_user(1);
function lease_race_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function lease_race_replace_on_read($key,$replacement){
    $filter=null;
    $filter=function($value)use($key,$replacement,&$filter){
        remove_filter('option_'.$key,$filter);
        // A second PHP request can replace an expired lease after this request
        // read it. Change the database directly to reproduce that interleaving.
        global $wpdb;
        $wpdb->update($wpdb->options,array('option_value'=>maybe_serialize($replacement)),array('option_name'=>$key));
        wp_cache_delete($key,'options');
        return $value;
    };
    add_filter('option_'.$key,$filter);
}
$id=987650;$key='oai_operation_lease_'.$id;
$oldToken='fixture-old-lease';$newToken='fixture-competing-lease';
$old=array('token_hash'=>hash('sha256',$oldToken),'action'=>'apply','acquired_at'=>time()-400,'expires_at'=>time()-1);
$replacement=array('token_hash'=>hash('sha256',$newToken),'action'=>'apply','acquired_at'=>time(),'expires_at'=>time()+300);
try{
    delete_option($key);add_option($key,$old,'',false);
    lease_race_replace_on_read($key,$replacement);
    $acquired=OAI_DB::acquire_operation_lease($id,'rollback');
    lease_race_expect(is_wp_error($acquired)&&$acquired->get_error_code()==='oai_operation_busy','Expired-lease contender erased a new active lease');
    lease_race_expect((get_option($key)['token_hash']??'')===$replacement['token_hash'],'Competing lease was overwritten during acquisition');

    update_option($key,array_merge($old,array('expires_at'=>time()+300)),false);
    lease_race_replace_on_read($key,$replacement);
    lease_race_expect(OAI_DB::release_operation_lease($id,$oldToken)===false,'Old holder released a replacement lease');
    lease_race_expect((get_option($key)['token_hash']??'')===$replacement['token_hash'],'Competing lease was deleted during release');

    update_option($key,$old,false);
    lease_race_replace_on_read($key,$replacement);
    $lease=OAI_DB::operation_lease($id);
    lease_race_expect(is_array($lease)&&$lease['expires_at']===$replacement['expires_at'],'Expiry inspection failed to retain/reload a replacement lease');
    lease_race_expect(OAI_DB::release_operation_lease($id,$newToken)===true,'Current holder could not release its own lease');
    lease_race_expect(get_option($key,null)===null,'Released lease remained cached');
    echo "PASS operation lease acquisition/release/expiry stale-read interleavings preserve the competing holder\n";
}finally{delete_option($key);}
