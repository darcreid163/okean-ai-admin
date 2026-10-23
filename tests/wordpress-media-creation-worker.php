<?php
if(getenv('OAI_DISPOSABLE_WP_TEST')!=='1'||!in_array(wp_parse_url(home_url(),PHP_URL_HOST),['localhost','127.0.0.1'],true)||!preg_match('/^oai-creation-[a-z0-9-]+\.png$/D',(string)getenv('OAI_CREATION_FILE')))throw new RuntimeException('Disposable media worker required');
wp_set_current_user(1);
$editor=getenv('OAI_MEDIA_TEST_EDITOR');if($editor)add_filter('wp_image_editors',static function()use($editor){return [$editor];});
$out=OAI_P0::media_upload(['filename'=>getenv('OAI_CREATION_FILE'),'content_base64'=>'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGPkiephYGBgYmBgYGBgAAALAgD2hsU36QAAAABJRU5ErkJggg==']);
echo wp_json_encode(is_wp_error($out)?['error'=>$out->get_error_code(),'status'=>$out->get_error_data()['status']??0]:['id'=>(int)$out['id'],'attachment'=>(int)$out['target']]);
