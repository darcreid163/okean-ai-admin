<?php
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true)||!preg_match('/^oai-creation-[a-z0-9-]+\.png$/D',(string)getenv('OAI_CREATION_FILE')))throw new RuntimeException('Disposable media worker required');
wp_set_current_user(1);
$detail=[];
$remember=static function($value)use(&$detail){$text=$value instanceof Throwable?get_class($value).': '.$value->getMessage():(is_scalar($value)?(string)$value:gettype($value));$detail[]=substr($text,0,300);if(count($detail)>4)array_shift($detail);};
add_action('oai_media_creation_insert_retry',static function($attempt,$error)use($remember){$remember('retry '.$attempt.': '.$error);});
add_action('oai_media_creation_insert_failed',static function($result,$error)use($remember){$remember('insert '.var_export($result,true).': '.$error);});
add_action('oai_media_creation_uncertain',static function($error)use($remember){$remember($error);});
$editor=getenv('OAI_MEDIA_TEST_EDITOR');if($editor)add_filter('wp_image_editors',static function()use($editor){return [$editor];});
try{
 $out=OAI_P0::media_upload(['filename'=>getenv('OAI_CREATION_FILE'),'content_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGPkiephYGBgYmBgYGBgAAALAgD2hsU36QAAAABJRU5ErkJggg==']);
}catch(Throwable $error){
 $remember($error);
 $out=new WP_Error('oai_creation_unresolved','Media creation outcome requires reconciliation',['status'=>409]);
}
$payload=is_wp_error($out)?['error'=>$out->get_error_code(),'status'=>$out->get_error_data()['status']??0]:['id'=>(int)$out['id'],'attachment'=>(int)$out['target']];
if($detail)$payload['detail']=$detail;
echo wp_json_encode($payload);
