<?php
/** Browser-executed visual QA probe for authenticated/signed WordPress pages. */
if (!defined('ABSPATH')) exit;

class OAI_Visual_QA {
 const TTL=3600;
 const REPORT_TTL=DAY_IN_SECONDS;

 private static function key($probe){return 'oai_vqa_'.substr(hash('sha256',(string)$probe),0,40);}
 private static function report_key($probe){return 'oai_vqar_'.substr(hash('sha256',(string)$probe),0,40);}
 private static function token($probe,$post_id,$expires){return hash_hmac('sha256',(string)$probe.'|'.(int)$post_id.'|'.(int)$expires,wp_salt('auth'));}

 public static function self_test_contract(){
  return array(
   'signed_probe'=>method_exists(__CLASS__,'signed_perm'),
   'three_viewports'=>self::viewports()===array('desktop'=>1440,'tablet'=>768,'mobile'=>390),
   'report_bounded'=>self::REPORT_TTL<=DAY_IN_SECONDS,
   'no_publication_required'=>true,
   'no_external_browser_service'=>true,
  );
 }
 public static function viewports(){return array('desktop'=>1440,'tablet'=>768,'mobile'=>390);}

 public static function start($post_id){
  $post_id=absint($post_id);$post=$post_id?get_post($post_id):null;
  if(!$post)return new WP_Error('oai_visual_post','Post not found',array('status'=>404));
  if(!current_user_can('manage_options')||!current_user_can('edit_post',$post_id))return new WP_Error('oai_visual_auth','Administrator edit permission is required',array('status'=>403));
  if(!in_array((string)$post->post_status,array('publish','draft','private','pending'),true))return new WP_Error('oai_visual_state','Unsupported post status for visual QA',array('status'=>409,'post_status'=>$post->post_status));
  $probe='vqa_'.str_replace('-','',wp_generate_uuid4());$expires=time()+self::TTL;
  $meta=array('probe_id'=>$probe,'post_id'=>$post_id,'post_status'=>(string)$post->post_status,'title'=>get_the_title($post_id),'created_at'=>gmdate('c'),'expires'=>$expires,'viewports'=>self::viewports(),'build_id'=>defined('OAI_BUILD_ID')?OAI_BUILD_ID:'unknown');
  set_transient(self::key($probe),$meta,self::TTL);
  $token=self::token($probe,$post_id,$expires);
  $url=add_query_arg(array('token'=>$token,'expires'=>$expires),rest_url('okean-ai/v1/visual-qa/run/'.$probe));
  return array('ok'=>true,'probe_id'=>$probe,'post_id'=>$post_id,'post_status'=>$post->post_status,'expires'=>$expires,'expires_in'=>self::TTL,'viewports'=>self::viewports(),'url'=>$url,'requires_browser_open'=>true,'publishes_content'=>false);
 }
 public static function result($probe){
  $probe=sanitize_text_field((string)$probe);$meta=get_transient(self::key($probe));$report=get_transient(self::report_key($probe));
  if(!$meta&&!$report)return new WP_Error('oai_visual_probe','Visual QA probe not found or expired',array('status'=>404));
  if(!current_user_can('manage_options'))return new WP_Error('oai_visual_auth','Administrator permission is required',array('status'=>403));
  if(!$report)return array('ok'=>true,'probe_id'=>$probe,'status'=>'pending','meta'=>$meta,'report'=>null);
  return array('ok'=>true,'probe_id'=>$probe,'status'=>'complete','meta'=>$meta?:($report['meta']??null),'report'=>$report);
 }
 public static function rest_start($req){return self::start(absint($req->get_param('post_id')));}
 public static function rest_result($req){return self::result((string)$req->get_param('probe'));}

 public static function signed_perm($req){
  $probe=sanitize_text_field((string)$req->get_param('probe'));$meta=get_transient(self::key($probe));
  if(!is_array($meta))return false;
  $expires=absint($req->get_param('expires'));$token=(string)$req->get_param('token');
  if(!$expires||$expires<time()||$expires!==(int)($meta['expires']??0)||$token==='')return false;
  return hash_equals(self::token($probe,absint($meta['post_id']??0),$expires),$token);
 }

 private static function render_post($post_id){
  $post=get_post(absint($post_id));if(!$post)return new WP_Error('oai_visual_post','Post not found',array('status'=>404));
  $body='';$previous_post=$GLOBALS['post']??null;
  try{
   if(defined('ELEMENTOR_VERSION')&&class_exists('Elementor\\Plugin')&&isset(\Elementor\Plugin::$instance->frontend)&&get_post_meta($post_id,'_elementor_edit_mode',true)==='builder'){
    $frontend=\Elementor\Plugin::$instance->frontend;
    if(method_exists($frontend,'enqueue_styles'))$frontend->enqueue_styles();
    $dummy_id=absint(get_option('page_on_front'));
    if(!$dummy_id||$dummy_id===$post_id)$dummy_id=absint(get_option('page_for_posts'));
    if(!$dummy_id||$dummy_id===$post_id)$dummy_id=absint(get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>1,'exclude'=>array($post_id),'fields'=>'ids'))[0]??0);
    if($dummy_id&&$dummy_id!==$post_id){$dummy=get_post($dummy_id);if($dummy){$GLOBALS['post']=$dummy;setup_postdata($dummy);}}
    if(method_exists($frontend,'get_builder_content_for_display'))$body=(string)$frontend->get_builder_content_for_display($post_id,true);
    if($body===''&&method_exists($frontend,'get_builder_content'))$body=(string)$frontend->get_builder_content($post_id,true);
    if($body===''){$document=\Elementor\Plugin::$instance->documents->get($post_id);if($document&&method_exists($document,'get_content'))$body=(string)$document->get_content();}
   }
   if($body===''){$GLOBALS['post']=$post;setup_postdata($post);$body=(string)apply_filters('the_content',(string)$post->post_content);}
  }catch(Throwable $e){
   if($previous_post!==null)$GLOBALS['post']=$previous_post;else unset($GLOBALS['post']);wp_reset_postdata();
   return new WP_Error('oai_visual_render','Could not render visual QA frame',array('status'=>500,'message'=>$e->getMessage()));
  }
  if($previous_post!==null)$GLOBALS['post']=$previous_post;else unset($GLOBALS['post']);wp_reset_postdata();
  if($body==='')return new WP_Error('oai_visual_empty','Visual QA renderer returned empty HTML',array('status'=>422));
  return $body;
 }

 public static function frame($req){
  $probe=sanitize_text_field((string)$req->get_param('probe'));$meta=get_transient(self::key($probe));if(!is_array($meta))return new WP_Error('oai_visual_probe','Probe expired',array('status'=>404));
  $name=sanitize_key((string)$req->get_param('viewport'));$viewports=self::viewports();if(!isset($viewports[$name]))return new WP_Error('oai_visual_viewport','Unknown viewport',array('status'=>400));
  $body=self::render_post(absint($meta['post_id']));if(is_wp_error($body))return $body;
  nocache_headers();status_header(200);header('Content-Type: text/html; charset='.get_bloginfo('charset'));header('X-Robots-Tag: noindex, nofollow');
  $title=get_the_title(absint($meta['post_id']));
  ?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html($title); ?> — Visual QA</title><?php wp_head(); ?></head><body class="oai-visual-qa-frame"><?php echo $body; ?>
<script>
(function(){
 const viewport=<?php echo wp_json_encode($name); ?>;
 function visible(el){const s=getComputedStyle(el),r=el.getBoundingClientRect();return s.display!=='none'&&s.visibility!=='hidden'&&parseFloat(s.opacity||'1')>0&&r.width>0&&r.height>0;}
 function label(el){let x=(el.id?'#'+el.id:'');if(!x&&el.classList&&el.classList.length)x='.'+Array.from(el.classList).slice(0,2).join('.');if(!x)x=el.tagName.toLowerCase();return x.slice(0,120);}
 function rect(el){const r=el.getBoundingClientRect();return {x:Math.round(r.x),y:Math.round(r.y),width:Math.round(r.width),height:Math.round(r.height),right:Math.round(r.right),bottom:Math.round(r.bottom)};}
 function run(){
  const w=window.innerWidth,h=window.innerHeight,de=document.documentElement,body=document.body;
  const all=Array.from(document.querySelectorAll('body *')).filter(visible).slice(0,1200);
  const offscreen=[],clipped=[],smallTargets=[],smallText=[];
  for(const el of all){
   const r=el.getBoundingClientRect(),s=getComputedStyle(el);
   if((r.left<-2||r.right>w+2)&&r.width>1&&offscreen.length<30)offscreen.push({element:label(el),rect:rect(el)});
   const ox=s.overflowX,oy=s.overflowY;
   if(((ox==='hidden'||ox==='clip')&&el.scrollWidth>el.clientWidth+2)||((oy==='hidden'||oy==='clip')&&el.scrollHeight>el.clientHeight+2)){
    if(clipped.length<30)clipped.push({element:label(el),client:[el.clientWidth,el.clientHeight],scroll:[el.scrollWidth,el.scrollHeight],overflow:[ox,oy]});
   }
  }
  for(const el of Array.from(document.querySelectorAll('a,button,input,select,textarea,[role="button"]')).filter(visible).slice(0,300)){
   const r=el.getBoundingClientRect();if((r.width<24||r.height<24)&&smallTargets.length<30)smallTargets.push({element:label(el),rect:rect(el),text:(el.innerText||el.value||'').trim().slice(0,100)});
  }
  for(const el of Array.from(document.querySelectorAll('p,a,button,li,td,th,label,input,select,textarea,h1,h2,h3,span')).filter(visible).slice(0,600)){
   if(!(el.innerText||el.value||'').trim())continue;const fs=parseFloat(getComputedStyle(el).fontSize||'0');if(fs>0&&fs<12&&smallText.length<30)smallText.push({element:label(el),font_size:fs,text:(el.innerText||el.value||'').trim().slice(0,100)});
  }
  const overlaps=[],interactive=Array.from(document.querySelectorAll('a,button,input,select,textarea,[role="button"]')).filter(visible).slice(0,120);
  const overlays=all.filter(el=>{const p=getComputedStyle(el).position;return p==='fixed'||p==='sticky';}).slice(0,40);
  for(const a of overlays){for(const b of interactive){if(a===b||a.contains(b)||b.contains(a))continue;const ra=a.getBoundingClientRect(),rb=b.getBoundingClientRect();const iw=Math.max(0,Math.min(ra.right,rb.right)-Math.max(ra.left,rb.left)),ih=Math.max(0,Math.min(ra.bottom,rb.bottom)-Math.max(ra.top,rb.top));const area=iw*ih,min=Math.min(ra.width*ra.height,rb.width*rb.height);if(min>0&&area/min>.35&&overlaps.length<30)overlaps.push({overlay:label(a),target:label(b),ratio:Math.round(area/min*100)/100});}}
  const report={viewport,width:w,height:h,device_pixel_ratio:window.devicePixelRatio||1,document_width:Math.max(de.scrollWidth,body?body.scrollWidth:0),document_height:Math.max(de.scrollHeight,body?body.scrollHeight:0),horizontal_overflow:Math.max(de.scrollWidth,body?body.scrollWidth:0)>w+2,offscreen,clipped,small_targets:smallTargets,small_text:smallText,overlaps,counts:{offscreen:offscreen.length,clipped:clipped.length,small_targets:smallTargets.length,small_text:smallText.length,overlaps:overlaps.length},captured_at:new Date().toISOString()};
  parent.postMessage({type:'oai-visual-qa',probe:<?php echo wp_json_encode($probe); ?>,report},location.origin);
 }
 if(document.readyState==='complete')setTimeout(run,250);else addEventListener('load',()=>setTimeout(run,250),{once:true});
})();
</script><?php wp_footer(); ?></body></html><?php exit;
 }

 public static function page($req){
  $probe=sanitize_text_field((string)$req->get_param('probe'));$meta=get_transient(self::key($probe));if(!is_array($meta))return new WP_Error('oai_visual_probe','Probe expired',array('status'=>404));
  $token=(string)$req->get_param('token');$expires=absint($req->get_param('expires'));$frames=array();
  foreach(self::viewports() as $name=>$width)$frames[$name]=add_query_arg(array('viewport'=>$name,'token'=>$token,'expires'=>$expires),rest_url('okean-ai/v1/visual-qa/frame/'.$probe));
  $report_url=add_query_arg(array('token'=>$token,'expires'=>$expires),rest_url('okean-ai/v1/visual-qa/report/'.$probe));
  nocache_headers();status_header(200);header('Content-Type: text/html; charset='.get_bloginfo('charset'));header('X-Robots-Tag: noindex, nofollow');
  ?><!doctype html><html><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP AI Admin Visual QA</title><style>body{margin:0;font:14px/1.45 system-ui;background:#eef3f8;color:#10233d}.bar{position:sticky;top:0;z-index:9;padding:14px 20px;background:#10233d;color:#fff}.wrap{padding:18px;display:grid;gap:18px}.card{background:#fff;border:1px solid #dfe8f3;border-radius:14px;padding:14px;overflow:auto}.status{font-weight:700}.framewrap{overflow:auto;border:1px solid #dfe8f3;background:#ddd;padding:8px}.framewrap iframe{display:block;border:0;background:#fff;height:900px;max-width:none}.ok{color:#137a49}.wait{color:#a06d00}</style></head><body><div class="bar"><b>WP AI Admin Visual QA</b> · <?php echo esc_html($meta['title']); ?> · draft-safe browser probe</div><div class="wrap"><div class="card"><div id="state" class="status wait">Запуск трёх viewport-проверок…</div><div id="summary"></div></div>
<?php foreach(self::viewports() as $name=>$width): ?><div class="card"><b><?php echo esc_html(ucfirst($name).' · '.$width.'px'); ?></b><div class="framewrap"><iframe data-vp="<?php echo esc_attr($name); ?>" src="<?php echo esc_url($frames[$name]); ?>" style="width:<?php echo (int)$width; ?>px"></iframe></div></div><?php endforeach; ?></div>
<script>
(function(){
 const expected=['desktop','tablet','mobile'],reports={};const state=document.getElementById('state'),summary=document.getElementById('summary');
 addEventListener('message',async e=>{
  if(e.origin!==location.origin||!e.data||e.data.type!=='oai-visual-qa'||e.data.probe!==<?php echo wp_json_encode($probe); ?>)return;
  reports[e.data.report.viewport]=e.data.report;
  const done=expected.filter(x=>reports[x]).length;state.textContent='Получено '+done+' / 3 отчётов';
  if(done===3){
   const payload={schema_version:1,probe_id:<?php echo wp_json_encode($probe); ?>,post_id:<?php echo (int)$meta['post_id']; ?>,viewports:reports,completed_at:new Date().toISOString()};
   try{
    const r=await fetch(<?php echo wp_json_encode($report_url); ?>,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload),credentials:'same-origin'});
    const j=await r.json();if(!r.ok)throw new Error(j.message||'HTTP '+r.status);
    state.className='status ok';state.textContent='Visual QA завершён и сохранён.';
    summary.textContent='Теперь отчёт доступен AI через wp.visual_qa_result.';
   }catch(err){state.textContent='Не удалось сохранить отчёт: '+err.message;}
  }
 });
})();
</script></body></html><?php exit;
 }

 public static function report($req){
  $probe=sanitize_text_field((string)$req->get_param('probe'));$meta=get_transient(self::key($probe));if(!is_array($meta))return new WP_Error('oai_visual_probe','Probe expired',array('status'=>404));
  $data=$req->get_json_params();if(!is_array($data))return new WP_Error('oai_visual_report','JSON report required',array('status'=>400));
  $encoded=wp_json_encode($data);if(strlen((string)$encoded)>200000)return new WP_Error('oai_visual_report_large','Visual QA report exceeds 200 KB',array('status'=>413));
  if(($data['probe_id']??'')!==$probe||absint($data['post_id']??0)!==absint($meta['post_id']))return new WP_Error('oai_visual_report_binding','Report probe/post binding mismatch',array('status'=>409));
  $viewports=$data['viewports']??array();foreach(array_keys(self::viewports()) as $name)if(empty($viewports[$name])||!is_array($viewports[$name]))return new WP_Error('oai_visual_report_viewports','All three viewport reports are required',array('status'=>422,'missing'=>$name));
  $summary=array('horizontal_overflow'=>0,'clipped'=>0,'small_targets'=>0,'small_text'=>0,'overlaps'=>0,'offscreen'=>0);
  foreach(array_keys(self::viewports()) as $name){$r=$viewports[$name];if(!empty($r['horizontal_overflow']))$summary['horizontal_overflow']++;foreach(array('clipped','small_targets','small_text','overlaps','offscreen') as $k)$summary[$k]+=min(100,absint($r['counts'][$k]??count((array)($r[$k]??array()))));}
  $critical=$summary['horizontal_overflow']+$summary['clipped']+$summary['overlaps'];$status=$critical>0?'WARN':(($summary['small_targets']+$summary['small_text']+$summary['offscreen'])>0?'WARN':'PASS');
  $report=array('schema_version'=>1,'probe_id'=>$probe,'post_id'=>absint($meta['post_id']),'post_status'=>$meta['post_status'],'status'=>$status,'summary'=>$summary,'viewports'=>$viewports,'completed_at'=>sanitize_text_field((string)($data['completed_at']??gmdate('c'))),'meta'=>$meta,'javascript_executed'=>true,'browser_measurements'=>true);
  set_transient(self::report_key($probe),$report,self::REPORT_TTL);
  return array('ok'=>true,'probe_id'=>$probe,'status'=>'saved','qa_status'=>$status,'summary'=>$summary);
 }
}
