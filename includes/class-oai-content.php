<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Content {
 public static function get($id){$p=get_post((int)$id);if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404));return array('id'=>$p->ID,'type'=>$p->post_type,'status'=>$p->post_status,'title'=>$p->post_title,'excerpt'=>$p->post_excerpt,'content'=>$p->post_content,'modified'=>$p->post_modified,'author'=>$p->post_author,'permalink'=>get_permalink($p));}
 public static function patch($id,$field,$old,$new,$all=false){$p=get_post((int)$id);if(!$p)return new WP_Error('oai_not_found','Content not found',array('status'=>404));if(!in_array($field,array('post_title','post_excerpt','post_content'),true))return new WP_Error('oai_field','Field is not editable',array('status'=>400));$cur=(string)$p->{$field};if($old===$cur){$next=$new;}elseif($old===''){return new WP_Error('oai_match','old text is empty but the current field is not empty',array('status'=>409,'matches'=>0));}elseif($all){$next=str_replace($old,$new,$cur);}else{if(substr_count($cur,$old)!==1)return new WP_Error('oai_match','old text must match exactly once',array('status'=>409,'matches'=>substr_count($cur,$old)));$next=str_replace($old,$new,$cur);}return array('before'=>$cur,'after'=>$next,'field'=>$field,'post_id'=>(int)$id);}
 public static function apply_patch($id,$field,$value){$ok=wp_update_post(array('ID'=>(int)$id,$field=>$value),true);return is_wp_error($ok)?$ok:true;}
}
