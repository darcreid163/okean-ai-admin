<?php
namespace Elementor {
 class Plugin {
  public static $instance;
  public $documents;
  public function __construct(){ $this->documents=new class {
   public function get($id,$refresh=false){return new class {
    public function save($payload){$GLOBALS['test_posts'][42]['post_content']='Elementor generated mirror';return true;}
   };}
  };}
 }
}
namespace {
 define('ABSPATH',__DIR__.'/');
 define('ELEMENTOR_VERSION','test');
 class WP_Error {private $code;public function __construct($code,$message='',$data=array()){$this->code=$code;}public function get_error_code(){return $this->code;}}
 function is_wp_error($v){return $v instanceof WP_Error;}
 function absint($v){return abs((int)$v);}
 function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
 function wp_slash($v){return $v;}
 function get_post($id){return isset($GLOBALS['test_posts'][$id])?(object)$GLOBALS['test_posts'][$id]:null;}
 function get_post_field($field,$id){return $GLOBALS['test_posts'][$id][$field]??'';}
 function wp_update_post($data,$error=false){$id=(int)$data['ID'];foreach($data as $key=>$value)if($key!=='ID')$GLOBALS['test_posts'][$id][$key]=$value;return $id;}
 function get_post_meta($id,$key,$single=true){return $GLOBALS['test_meta'][$id][$key]??'';}
 function update_post_meta($id,$key,$value){$GLOBALS['test_meta'][$id][$key]=$value;return true;}
 function clean_post_cache($id){}
 function wp_cache_delete($id,$group){}
 function do_action($hook){}
 \Elementor\Plugin::$instance=new \Elementor\Plugin();
 $GLOBALS['test_posts']=array(42=>array('post_content'=>'Original page duplicated in WordPress and Elementor'),43=>array('post_content'=>'Independent WordPress content'));
 $GLOBALS['test_meta']=array();
 require dirname(__DIR__).'/includes/class-oai-elementor.php';
 function expect($value,$message){if(!$value)throw new \RuntimeException($message);}
 $html='<section><h1>Океан</h1><img src="old.webp"></section>';
 $new=\OAI_Elementor::replace_html_fragment($html,'old.webp','new.webp',hash('sha256',$html));
 expect($new==='<section><h1>Океан</h1><img src="new.webp"></section>','Image edit changed other HTML');
 $letter=\OAI_Elementor::replace_html_fragment($new,'Океан','Океан!',hash('sha256',$new));
 expect(str_contains($letter,'<h1>Океан!</h1>') && substr_count($letter,'<section>')===1,'Text edit duplicated page');
 expect(is_wp_error(\OAI_Elementor::replace_html_fragment($html,'old.webp','x',hash('sha256',$new))),'Stale widget hash accepted');
 expect(is_wp_error(\OAI_Elementor::replace_html_fragment('xx','x','y',hash('sha256','xx'))),'Ambiguous fragment accepted');
 $large=str_repeat('<section>Existing content</section>',100);
 $duplicate=\OAI_Elementor::reject_cloned_widget($large,$large.$large.' New text');
 expect(is_wp_error($duplicate)&&$duplicate->get_error_code()==='oai_elementor_duplicate','Full widget was cloned twice');
 expect(\OAI_Elementor::reject_cloned_widget($large,$large.' New text')===true,'Single widget rejected');
 expect(\OAI_Elementor::activate_single_source(42)===true,'Single-source activation failed');
 expect(get_post_field('post_content',42)==='' && get_post_meta(42,'_oai_elementor_single_source',true)==='1','Mirrored content remains');
 expect(is_wp_error(\OAI_Elementor::content_write_allowed(42)),'WordPress content write accepted for builder page');
 expect(\OAI_Elementor::content_write_allowed(43)===true,'Independent WordPress content write rejected');
 $tree=array(array('id'=>'root','elType'=>'container','settings'=>array('content_width'=>'full'),'elements'=>array(array('id'=>'widget','elType'=>'widget','widgetType'=>'html','settings'=>array('html'=>$letter),'elements'=>array()))));
 expect(\OAI_Elementor::save_document(42,$tree)===true,'Builder save failed');
 expect(get_post_field('post_content',42)==='','Elementor save reintroduced mirrored content');
 expect(json_decode(get_post_meta(42,'_elementor_data',true),true)===$tree,'Layout/widget tree changed');
 $GLOBALS['test_posts'][42]['post_content']='Human edit';
 $conflict=\OAI_Elementor::save_document(42,$tree);
 expect(is_wp_error($conflict)&&$conflict->get_error_code()==='oai_content_conflict','Human WordPress edit overwritten');

 // Test Automation must keep an Elementor draft created in-session eligible
 // for later exact widget/fragment revisions in the same session.
 define('OAI_BUILD_ID','2.0.60-test-created-draft-scope-20260929');
 $GLOBALS['test_options']=array(
  'oai_test_automation'=>array(
   'enabled'=>true,'session_id'=>'tas_fixture','enabled_at'=>time()-10,'expires_at'=>time()+3600,
   'owner_user_id'=>7,'bound_client_id_hash'=>'','allowed_post_ids'=>array(),
   'allowed_product_ids'=>array(),'session_created_post_ids'=>array(),'action_count'=>0,'max_actions'=>100000,
   'build_id'=>OAI_BUILD_ID
  )
 );
 if(!function_exists('get_option')){function get_option($key,$default=false){return $GLOBALS['test_options'][$key]??$default;}}
 if(!function_exists('update_option')){function update_option($key,$value,$autoload=false){$GLOBALS['test_options'][$key]=$value;return true;}}
 if(!function_exists('user_can')){function user_can($id,$cap){return (int)$id===7 && $cap==='manage_options';}}
 if(!function_exists('sanitize_text_field')){function sanitize_text_field($v){return trim((string)$v);}}
 if(!function_exists('sanitize_key')){function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}}
 if(!function_exists('wp_strip_all_tags')){function wp_strip_all_tags($v){return strip_tags((string)$v);}}
 if(!class_exists('OAI_DB')){class OAI_DB {
  public static $rows=array();
  public static function get($id){return self::$rows[(int)$id]??null;}
  public static function update($id,$data){foreach($data as $k=>$v)self::$rows[(int)$id][$k]=$v;return true;}
 }}
 if(!class_exists('OAI_Audit')){class OAI_Audit {public static function log($event,$row,$context=array()){return true;}}}
 require dirname(__DIR__).'/includes/class-oai-test-automation.php';
 $create_meta=array('preview_page_id'=>42,'final_status'=>'draft');
 OAI_DB::$rows[901]=array('id'=>901,'type'=>'elementor_page_create','target'=>'elementor_page:42','meta_data'=>json_encode($create_meta));
 $tag_create=\OAI_Test_Automation::tag_operation(901,'elementor.create_draft','fixture-client',7);
 expect(!empty($tag_create['tagged']) && !empty($tag_create['eligible']),'Created Elementor draft was not tagged');
 $scope=\OAI_Test_Automation::status();
 expect(in_array(42,$scope['allowed_post_ids'],true),'Created Elementor draft was not added to allowed_post_ids');
 expect(in_array(42,$scope['session_created_post_ids'],true),'Created Elementor draft was not tracked as session-created');
 OAI_DB::$rows[902]=array('id'=>902,'type'=>'elementor_widget_html','target'=>'42:widget','meta_data'=>json_encode(array()));
 $tag_patch=\OAI_Test_Automation::tag_operation(902,'elementor.patch_widget','fixture-client',7);
 expect(!empty($tag_patch['tagged']) && !empty($tag_patch['eligible']),'Follow-up Elementor patch did not inherit test-session scope');
 echo "PASS: one source, in-place image/text edits, stale/ambiguous rejection, layout preservation, manual-content conflict and created-draft test scope\n";
}
