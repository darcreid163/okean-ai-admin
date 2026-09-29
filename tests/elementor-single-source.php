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
 echo "PASS: one source, in-place image/text edits, stale/ambiguous rejection, layout preservation and manual-content conflict\n";
}
