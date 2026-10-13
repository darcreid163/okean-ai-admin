<?php
// Exercise a second recovery request during the first request's authoritative read.
wp_set_current_user(1);
function recovery_race_expect($ok,$message){if(!$ok)throw new RuntimeException($message);}
function recovery_race_request($id,$action){
    $r=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.$id.'/'.$action);
    $r->set_url_params(array('id'=>$id,'action'=>$action));
    $r->set_header('X-WP-Nonce',wp_create_nonce('wp_rest'));
    return $r;
}
function recovery_race_probe($key,$request,&$nested){
    $filter=null;
    $filter=function($value)use($key,$request,&$nested,&$filter){
        remove_filter('option_'.$key,$filter);
        $nested=OAI_REST::sandbox_action($request);
        return $value;
    };
    add_filter('option_'.$key,$filter);
}
$key='release_ci_recovery_race';
try{
    update_option($key,'before',false);
    $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox');
    $req->set_param('type','option_update');$req->set_param('target',$key);$req->set_param('new_data','after');
    $op=OAI_REST::sandbox_create($req);
    recovery_race_expect(!is_wp_error($op),'Recovery race sandbox creation failed');
    $id=(int)$op['id'];
    recovery_race_expect(!is_wp_error(OAI_REST::sandbox_action(recovery_race_request($id,'approve'))),'Recovery race approval failed');
    recovery_race_expect(OAI_DB::transition($id,array('approved'),'applying')===1,'Cannot seed abandoned Apply');
    update_option($key,'after',false);
    $apply=recovery_race_request($id,'apply');$nested=null;
    recovery_race_probe($key,$apply,$nested);
    $result=OAI_REST::sandbox_action($apply);
    recovery_race_expect(is_wp_error($nested)&&$nested->get_error_code()==='oai_operation_busy','Concurrent Apply recovery did not respect an execution lease');
    recovery_race_expect(!is_wp_error($result)&&$result['status']==='applied','Winning Apply recovery did not finalize');
    recovery_race_expect(OAI_DB::operation_lease($id)===null,'Apply recovery leaked its execution lease');

    recovery_race_expect(OAI_DB::transition($id,array('applied'),'rolling_back')===1,'Cannot seed abandoned Rollback');
    update_option($key,'before',false);
    $rollback=recovery_race_request($id,'rollback');$nested=null;
    recovery_race_probe($key,$rollback,$nested);
    $result=OAI_REST::sandbox_action($rollback);
    recovery_race_expect(is_wp_error($nested)&&$nested->get_error_code()==='oai_operation_busy','Concurrent Rollback recovery did not respect an execution lease');
    recovery_race_expect(!is_wp_error($result)&&$result['status']==='rolled_back','Winning Rollback recovery did not finalize');
    recovery_race_expect(OAI_DB::operation_lease($id)===null,'Rollback recovery leaked its execution lease');
    recovery_race_expect(get_option($key)==='before','Recovery replayed a live write');
    echo "PASS concurrent Apply/Rollback recovery has one holder, no replay and no leaked lease\n";
}finally{delete_option($key);}
