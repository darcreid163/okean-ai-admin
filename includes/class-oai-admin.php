<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_Admin {
 const ACTION_TABLE_LIMIT=50;
 const OPERATIONS_PER_PAGE=25;
 public static function ensure_token(){
  $current_build=defined('OAI_BUILD_ID')?(string)OAI_BUILD_ID:'';
  $previous_build=(string)get_option('oai_runtime_build_id','');
  if($current_build!==''&&($previous_build===''||!hash_equals($previous_build,$current_build))){
   if(class_exists('OAI_AI_Lifecycle_Control')&&method_exists('OAI_AI_Lifecycle_Control','reset_for_build_change'))OAI_AI_Lifecycle_Control::reset_for_build_change();
   if(class_exists('OAI_Test_Automation')&&method_exists('OAI_Test_Automation','reset_for_build_change'))OAI_Test_Automation::reset_for_build_change();
   update_option('oai_runtime_build_id',$current_build,false);
   if(!defined('OAI_BUILD_CHANGED_THIS_REQUEST'))define('OAI_BUILD_CHANGED_THIS_REQUEST',true);
  }
  if(!get_option('oai_bearer_token','')){
   $old=get_option('oai_api_token','');
   update_option('oai_bearer_token',$old?:wp_generate_password(48,false,false),false);
  }
  if(!get_option('oai_bearer_user_id',0)){
   $license=OAI_License::status();
   $uid=absint($license['owner_user_id']??0);
   if($uid && user_can($uid,'manage_options')) update_option('oai_bearer_user_id',$uid,false);
  }
 }
 public static function menu(){add_menu_page('AI Admin','AI Admin','manage_options','okean-ai-admin',array(__CLASS__,'page'),'dashicons-admin-generic',58);}
 public static function handle_mcp_toggle(){
  if(!isset($_POST['oai_mcp_state']))return null;
  if(!current_user_can('manage_options'))return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return new WP_Error('oai_mcp_method','MCP settings require POST',array('status'=>405));
  $nonce=$_POST['_wpnonce']??'';
  if(!is_string($nonce)||!wp_verify_nonce(wp_unslash($nonce),'oai_mcp_toggle'))return new WP_Error('oai_mcp_nonce','Invalid MCP settings confirmation',array('status'=>403));
  $state=$_POST['oai_mcp_state'];
  if(!is_string($state)||!in_array($state,array('enabled','disabled'),true))return new WP_Error('oai_mcp_state','Invalid MCP state',array('status'=>400));
  $enabled=$state==='enabled';
  update_option('oai_mcp_enabled',$enabled,false);
  if(!$enabled){
   if(class_exists('OAI_AI_Lifecycle_Control'))OAI_AI_Lifecycle_Control::disable(get_current_user_id());
   if(class_exists('OAI_Test_Automation'))OAI_Test_Automation::disable('mcp_disabled',get_current_user_id());
  }
  return array('enabled'=>$enabled);
 }
 private static function sandbox_admin_action($id,$action){
  if(!current_user_can('manage_options'))return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  if(!in_array($action,array('approve','reject','apply','rollback'),true))return new WP_Error('oai_action','Unknown sandbox action',array('status'=>400));
  $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.absint($id).'/'.$action);
  $req->set_url_params(array('id'=>absint($id),'action'=>$action));
  if(!defined('OAI_ADMIN_HUMAN_APPROVAL')) define('OAI_ADMIN_HUMAN_APPROVAL',true);
  return OAI_REST::sandbox_action($req);
 }
 private static function render_operation_rows($rows,$creators,$empty_text='Операций пока нет.'){
  if(!$rows){echo '<tr><td colspan="8">'.esc_html($empty_text).'</td></tr>';return;}
  foreach($rows as $r){
   $creator_id=$creators[(int)$r['id']]??0;$creator=$creator_id?get_userdata($creator_id):false;$who=$creator?$creator->display_name.' (ID '.$creator_id.')':($creator_id?'Пользователь #'.$creator_id:'Не установлен');
   $before=json_decode((string)$r['before_data'],true);$after=json_decode((string)$r['after_data'],true);
   $pretty=function($v){return is_string($v)?$v:wp_json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);};
   echo '<tr><td>'.(int)$r['id'].'</td><td>'.esc_html($r['operation_id']).'</td><td>'.esc_html($r['status']).'</td><td>'.esc_html($r['type']).'</td><td>'.esc_html($r['target']).'</td><td>'.esc_html($r['risk']).'</td><td>'.esc_html($r['created_at']).'</td><td><div class="oai-actions"><details><summary class="button" aria-label="'.esc_attr('Просмотр операции '.(int)$r['id']).'">Просмотр изменений</summary><div class="oai-detail"><strong>Операция #'.(int)$r['id'].': '.esc_html($r['type']).' → '.esc_html($r['target']).'</strong><small>Создана: '.esc_html($r['created_at']).' UTC · Автор: '.esc_html($who).' · Статус: '.esc_html($r['status']).'</small><strong>Было</strong><pre>'.esc_html($pretty($before)).'</pre><strong>Станет после применения</strong><pre>'.esc_html($pretty($after)).'</pre><small>До применения показан план. Никаких изменений на сайте просмотр не делает.</small>';
   if(in_array($r['type'],array('post_title','post_excerpt','post_content','elementor_widget_html','elementor_widget_settings','elementor_page_create'),true)&&in_array($r['status'],array('pending','approved'),true)){
    $exp=time()+3600;$pt=OAI_REST::preview_token((int)$r['id'],$exp);$route=$r['type']==='elementor_page_create'?'preview-page/':'preview/';$pl=add_query_arg(array('expires'=>$exp,'preview_token'=>$pt),rest_url('okean-ai/v1/'.$route.(int)$r['id']));
    echo '<a class="button oai-preview-page-button" target="_blank" rel="noopener noreferrer" href="'.esc_url($pl).'">Открыть страницу с изменением ↗</a>';
   }
   echo '</div></details><form method="post">';wp_nonce_field('oai_sandbox_action');echo '<input type="hidden" name="oai_sandbox_id" value="'.(int)$r['id'].'">';$current_page=max(1,absint($_REQUEST['oai_ops_page']??1));echo '<input type="hidden" name="oai_ops_page" value="'.$current_page.'">';
   if($r['status']==='pending') echo '<button class="button button-primary" name="oai_sandbox_action" value="approve" onclick="return confirm(\'Подтвердить операцию?\')">Подтвердить</button> <button class="button" name="oai_sandbox_action" value="reject">Отклонить</button>';
   elseif($r['status']==='approved') echo '<button class="button button-primary" name="oai_sandbox_action" value="apply" onclick="return confirm(\'Применить изменение на сайте?\')">Применить</button> <button class="button" name="oai_sandbox_action" value="reject">Отклонить</button>';
   elseif($r['status']==='applied'||($r['status']==='rollback_failed'&&!empty($r['applied_at']))) echo '<button class="button" name="oai_sandbox_action" value="rollback" onclick="return confirm(\'Откатить изменение?\')">Откатить</button>';
   echo '</form></div></td></tr>';
  }
 }
 private static function render_operation_table($rows,$creators,$empty_text='Операций пока нет.'){
  echo '<div class="oai-scroll"><table class="widefat striped"><thead><tr><th>ID</th><th>Операция</th><th>Статус</th><th>Тип</th><th>Объект</th><th>Риск</th><th>Создана (UTC)</th><th>Действие</th></tr></thead><tbody>';
  self::render_operation_rows($rows,$creators,$empty_text);
  echo '</tbody></table></div>';
 }
 public static function page(){
  if(!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.'));
  $mcp_toggle=self::handle_mcp_toggle();
  if($mcp_toggle!==null){
   echo '<div class="notice '.(is_wp_error($mcp_toggle)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($mcp_toggle)?$mcp_toggle->get_error_message():($mcp_toggle['enabled']?'MCP включён. Подключите этот сайт через OAuth.':'MCP выключен. Автоматические режимы остановлены; OAuth-подключения сохранены.')).'</p></div>';
  }
  if(isset($_POST['oai_content_navigation_state']) && check_admin_referer('oai_content_navigation_state')){
   $state=sanitize_key(wp_unslash((string)$_POST['oai_content_navigation_state']));
   if(in_array($state,array('experimental','enabled','disabled'),true)){update_option('oai_feature_content_navigation',$state,false);echo '<div class="notice notice-success"><p>Режим выборочного чтения сохранён.</p></div>';}
   else echo '<div class="notice notice-error"><p>Неверный режим выборочного чтения.</p></div>';
  }
  if(isset($_POST['oai_ai_lifecycle_enable']) && check_admin_referer('oai_ai_lifecycle_toggle')){
   if(defined('OAI_BUILD_CHANGED_THIS_REQUEST')&&OAI_BUILD_CHANGED_THIS_REQUEST){
    echo '<div class="notice notice-warning"><p>WP AI Admin только что обновлён. Автономные режимы сброшены; обновите эту страницу и включите AI Lifecycle Control отдельным действием.</p></div>';
   }elseif(empty($_POST['oai_ai_lifecycle_ack'])){
    echo '<div class="notice notice-error"><p>Подтвердите, что разрешаете AI управлять lifecycle sandbox-операций.</p></div>';
   }else{
    $result=OAI_AI_Lifecycle_Control::enable(get_current_user_id());
    echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-warning').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'AI Lifecycle Control включён. Подключённый MCP-клиент администратора может approve/reject/apply/rollback; ручные кнопки остаются доступны.').'</p></div>';
   }
  }
  if(isset($_POST['oai_ai_lifecycle_disable']) && check_admin_referer('oai_ai_lifecycle_toggle')){
   OAI_AI_Lifecycle_Control::disable(get_current_user_id());
   echo '<div class="notice notice-success"><p>AI Lifecycle Control выключен. Ручное управление операциями сохранено.</p></div>';
  }
  if(isset($_POST['oai_test_automation_enable']) && check_admin_referer('oai_test_automation_toggle')){
   if(defined('OAI_BUILD_CHANGED_THIS_REQUEST')&&OAI_BUILD_CHANGED_THIS_REQUEST){
    echo '<div class="notice notice-warning"><p>WP AI Admin только что обновлён. Test Automation оставлен выключенным; обновите страницу и включите QA-режим отдельным действием.</p></div>';
   }elseif(empty($_POST['oai_test_automation_ack'])){
    echo '<div class="notice notice-error"><p>Для включения автоматизации тестов подтвердите ограниченный тестовый режим.</p></div>';
   }else{
    $ttl=absint($_POST['oai_test_automation_ttl']??60);
    $raw_posts=wp_unslash($_POST['oai_test_automation_posts']??array());
    $posts=is_array($raw_posts)?OAI_Test_Automation::parse_ids($raw_posts):sanitize_text_field((string)$raw_posts);
    $products=sanitize_text_field(wp_unslash((string)($_POST['oai_test_automation_products']??'')));
    $result=OAI_Test_Automation::enable($ttl,$posts,$products,get_current_user_id());
    echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-warning').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Test Automation Mode включён временно. Только новые операции текущей тестовой сессии смогут проходить lifecycle через MCP.').'</p></div>';
   }
  }
  if(isset($_POST['oai_test_automation_disable']) && check_admin_referer('oai_test_automation_toggle')){
   OAI_Test_Automation::disable('manual',get_current_user_id());
   echo '<div class="notice notice-success"><p>Test Automation Mode выключен. Возвращён обычный human approval.</p></div>';
  }
  if(isset($_POST['oai_sandbox_action']) && check_admin_referer('oai_sandbox_action')){
   $sid=absint($_POST['oai_sandbox_id']??0); $action=sanitize_key((string)$_POST['oai_sandbox_action']);
   $result=self::sandbox_admin_action($sid,$action);
   echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Операция #'.$sid.': '.$action.' выполнена.').'</p></div>';
  }
  global $wpdb;
  if(isset($_POST['oai_disconnect_all']) && check_admin_referer('oai_disconnect_all')){
   $count=(int)$wpdb->query('UPDATE '.OAI_MCP_OAuth::tokens_table().' SET revoked=1 WHERE revoked=0 AND (expires_at > UTC_TIMESTAMP() OR refresh_expires_at > UTC_TIMESTAMP())');
   if(class_exists('OAI_AI_Lifecycle_Control'))OAI_AI_Lifecycle_Control::disable(get_current_user_id());
   if(class_exists('OAI_Test_Automation'))OAI_Test_Automation::disable('disconnect',get_current_user_id());
   echo '<div class="notice notice-success"><p>'.esc_html(sprintf('Подключение отключено. Активных OAuth-токенов отозвано: %d.',max(0,$count))).'</p></div>';
  }
  $active_clients=(int)$wpdb->get_var('SELECT COUNT(DISTINCT client_id) FROM '.OAI_MCP_OAuth::tokens_table().' WHERE revoked=0 AND (expires_at > UTC_TIMESTAMP() OR refresh_expires_at > UTC_TIMESTAMP())');
  $test_auto=OAI_Test_Automation::status();
  $ai_lifecycle=OAI_AI_Lifecycle_Control::status();
  $quota=OAI_Quota::status();
  $release=OAI_Platform::release_profile();
  $test_pages=get_posts(array('post_type'=>'page','post_status'=>array('draft','private'),'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC','suppress_filters'=>false));
  $action_statuses=array('pending','approved');$pending=OAI_DB::count_by_status($action_statuses);$action_rows=OAI_DB::by_status($action_statuses,self::ACTION_TABLE_LIMIT,0);
  $ops_per_page=self::OPERATIONS_PER_PAGE;$ops_total=OAI_DB::count_all();$ops_pages=max(1,(int)ceil($ops_total/$ops_per_page));$ops_page=max(1,absint($_REQUEST['oai_ops_page']??1));if($ops_page>$ops_pages)$ops_page=$ops_pages;
  $rows=OAI_DB::page($ops_per_page,($ops_page-1)*$ops_per_page);
  $creator_source=array();foreach(array_merge($action_rows,$rows) as $row)$creator_source[(int)$row['id']]=$row;
  $creators=array(); if($creator_source){ $ids=array_keys($creator_source); $placeholders=implode(',',array_fill(0,count($ids),'%d')); $audit_rows=$wpdb->get_results($wpdb->prepare('SELECT operation_db_id, actor FROM '.OAI_DB::audit_table().' WHERE event=%s AND operation_db_id IN ('.$placeholders.') ORDER BY id ASC',array_merge(array('created'),$ids)),ARRAY_A); foreach($audit_rows as $entry){$key=(int)$entry['operation_db_id'];if(!array_key_exists($key,$creators))$creators[$key]=absint($entry['actor']);} }
  ?>
  <style>
  .oai-dash{max-width:1250px;margin:22px auto 50px;padding:0 16px;color:#15243b}.oai-dash *{box-sizing:border-box}
  .oai-head{display:flex;align-items:center;gap:16px;margin-bottom:22px}.oai-mark{display:grid;place-items:center;background:#07366f;color:white;border-radius:14px;width:48px;height:48px;font-size:22px}.oai-head h1{margin:0;font-size:27px}.oai-muted{color:#62738b}.oai-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:18px 0}.oai-panel{background:#fff;border:1px solid #dce4ed;border-radius:14px;padding:20px;box-shadow:0 2px 10px #1233}.oai-panel h2{margin:0 0 12px;font-size:18px}.oai-kpi{font-size:24px;font-weight:700;color:#07366f;margin:8px 0}.oai-badge{display:inline-block;padding:5px 11px;border-radius:20px;background:#e8f7ed;color:#126436;font-weight:600}.oai-warn{background:#fff4e5;color:#8c4b00}.oai-section{margin-top:22px}.oai-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.oai-scroll{overflow:auto;background:white;border:1px solid #dce4ed;border-radius:12px}.oai-dash table{border:0;box-shadow:none}.oai-dash th,.oai-dash td{vertical-align:middle;padding:12px}.oai-actions{display:flex;gap:5px;flex-wrap:wrap}.oai-actions form{display:inline}.oai-danger{color:#b32d2e!important;border-color:#b32d2e!important}.oai-preview-page-button{margin-top:10px!important;display:inline-block!important;color:#07366f!important;border-color:#9fb9da!important;font-weight:600!important}.oai-dash details{margin-top:0;min-width:125px}.oai-dash details summary{cursor:pointer;display:inline-block}.oai-detail{width:min(560px,70vw);padding:14px;margin-top:8px;border:1px solid #dce4ed;border-radius:8px;background:#f9fbfe;white-space:normal}.oai-detail pre{max-height:260px;max-width:100%;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;border:1px solid #e2e7ec;padding:8px}.oai-detail small{display:block;color:#62738b;margin:8px 0}.oai-dash a:focus,.oai-dash button:focus{outline:2px solid #e77a18;outline-offset:2px}@media(max-width:900px){.oai-grid{grid-template-columns:1fr}}
  </style>
  <div class="wrap oai-dash"><div class="oai-head"><div class="oai-mark" aria-hidden="true">✦</div><div><h1>WP AI Admin</h1><div class="oai-muted">Подключение, лимиты и контроль изменений сайта</div></div></div>
  <section class="oai-panel oai-section"><div class="oai-section-head"><h2>Commercial Beta Candidate</h2><span class="oai-badge">WP AI Admin <?php echo esc_html(OAI_VERSION);?></span></div><p><strong>Профиль:</strong> <?php echo esc_html($release['profile']??'commercial_beta_candidate');?> · <strong>Режим:</strong> <?php echo esc_html($release['effective_state']['mode']??'human_approval_only');?></p><p class="oai-muted">Безопасность по умолчанию: direct live writes — <strong>OFF</strong>, ручное подтверждение — <strong>ON</strong>. AI Lifecycle Control и Test Automation включаются только отдельно администратором и сбрасывают активность при смене сборки. OAuth-подключение и installation identity сохраняются при обычном обновлении плагина.</p><p class="oai-muted">Control Plane: <?php echo !empty($release['control_plane']['foundation_available'])?'foundation готов':'недоступен';?>; удалённые лицензии/entitlements для локальной beta не обязательны и пока не принудительно применяются.</p></section>
  <div class="oai-grid"><section class="oai-panel"><h2>Подключение</h2><span class="oai-badge <?php echo $active_clients?'':'oai-warn';?>"><?php echo $active_clients?'Подключено':'Нет подключения';?></span><p class="oai-muted"><?php echo !empty($ai_lifecycle['active'])?'AI Lifecycle Control активен: AI и администратор могут параллельно управлять sandbox lifecycle.':(!empty($test_auto['active'])?'ВНИМАНИЕ: активен ограниченный Test Automation Mode.':'По умолчанию lifecycle управляется вручную администратором.');?></p><?php if($active_clients):?><form method="post"><?php wp_nonce_field('oai_disconnect_all');?><button class="button oai-danger" name="oai_disconnect_all" value="1" onclick="return confirm('Отключить WP AI Admin от всех подключённых AI-клиентов? Для повторного подключения потребуется авторизация.')">Отключить</button></form><?php endif;?></section>
  <section class="oai-panel"><h2>Ожидают решения</h2><div class="oai-kpi"><?php echo (int)$pending;?></div><a href="#oai-operations">Просмотреть операции ↓</a></section>
  <section class="oai-panel"><h2>Вызовы AI Admin</h2><div class="oai-kpi"><?php echo (int)$quota['remaining'];?> / <?php echo (int)$quota['limit'];?></div><div class="oai-muted">Осталось в локальном плане <?php echo esc_html($quota['label']);?>; использовано <?php echo (int)$quota['used'];?>. Лимит тарифа ChatGPT не передаётся плагину.</div></section></div>
  <section class="oai-panel oai-section"><h2>Как работает доступ</h2><p>AI-клиент читает разрешённые данные сайта и создаёт операции в песочнице. Просмотрите изменения ниже: подтвердите и примените их либо отклоните. После применения доступен откат, если операция его поддерживает.</p><p class="oai-muted">Адрес сервера: <code><?php echo esc_html(rest_url('okean-ai/mcp'));?></code>. Доступ выдаётся при подключении через OAuth. Секреты здесь не показываются.</p></section>
  <section class="oai-panel oai-section"><div class="oai-section-head"><h2>Подключение MCP</h2><span class="oai-badge <?php echo get_option('oai_mcp_enabled',false)?'':'oai-warn';?>"><?php echo get_option('oai_mcp_enabled',false)?'Включён':'Выключен';?></span></div><p>Включите MCP и добавьте отдельное OAuth-подключение для этого сайта. Авторизуйтесь под его администратором.</p><p><strong>Адрес подключения:</strong> <code><?php echo esc_html(rest_url('okean-ai/mcp'));?></code></p><p class="oai-muted">Включение MCP не включает AI Lifecycle Control или Test Automation. Существующие идентификаторы сайта и OAuth-подключения сохраняются.</p><?php if(parse_url((string)get_option('siteurl',''),PHP_URL_SCHEME)!=='https'||parse_url((string)get_option('home',''),PHP_URL_SCHEME)!=='https'):?><p class="oai-warn">Для удалённого OAuth укажите HTTPS в обоих адресах WordPress. <a href="<?php echo esc_url(admin_url('options-general.php'));?>">Открыть общие настройки</a></p><?php endif;?><form method="post"><?php wp_nonce_field('oai_mcp_toggle');?><button class="button <?php echo get_option('oai_mcp_enabled',false)?'':'button-primary';?>" name="oai_mcp_state" value="<?php echo get_option('oai_mcp_enabled',false)?'disabled':'enabled';?>"><?php echo get_option('oai_mcp_enabled',false)?'Выключить MCP':'Включить MCP';?></button></form><p><a href="<?php echo esc_url(home_url('/.well-known/oauth-protected-resource'));?>" target="_blank" rel="noopener noreferrer">Проверить ресурс OAuth</a> · <a href="<?php echo esc_url(home_url('/.well-known/oauth-authorization-server'));?>" target="_blank" rel="noopener noreferrer">Проверить сервер авторизации</a></p></section>
  <section class="oai-panel oai-section"><h2>Выборочное чтение больших данных</h2><p>Обзор файла, диапазоны строк, краткий вид Elementor и HTML, страницы каталога. Запись сайта эта настройка не разрешает.</p><form method="post"><?php wp_nonce_field('oai_content_navigation_state');?><label for="oai-content-navigation">Режим: </label><select id="oai-content-navigation" name="oai_content_navigation_state"><?php foreach(array('experimental'=>'Тестовый','enabled'=>'Включён','disabled'=>'Отключён') as $value=>$label):?><option value="<?php echo esc_attr($value);?>" <?php selected(OAI_Platform::content_navigation_state(),$value);?>><?php echo esc_html($label);?></option><?php endforeach;?></select> <button class="button button-primary" type="submit">Сохранить</button></form></section>
  <section class="oai-panel oai-section"><div class="oai-section-head"><h2>AI Lifecycle Control</h2><span class="oai-badge <?php echo !empty($ai_lifecycle['active'])?'oai-warn':'';?>"><?php echo !empty($ai_lifecycle['active'])?'АКТИВЕН':'Выключен';?></span></div><p>Опциональный режим для обычной работы: разрешает подключённому MCP-клиенту администратора самостоятельно выполнять <strong>Подтвердить / Отклонить / Применить / Откатить</strong>. Все sandbox, conflict, expiry, Verify и rollback-проверки сохраняются. Ручные кнопки администратора продолжают работать параллельно.</p><?php if(!empty($ai_lifecycle['active'])):?><p><strong>Владелец:</strong> user ID <?php echo (int)$ai_lifecycle['owner_user_id'];?> · <strong>MCP-клиент:</strong> <?php echo !empty($ai_lifecycle['client_bound'])?'привязан':'будет привязан при первом AI lifecycle-действии';?></p><form method="post"><?php wp_nonce_field('oai_ai_lifecycle_toggle');?><button class="button oai-danger" name="oai_ai_lifecycle_disable" value="1" onclick="return confirm('Выключить AI Lifecycle Control? Ручные кнопки останутся доступны.')">Выключить AI Lifecycle Control</button></form><?php else:?><form method="post"><?php wp_nonce_field('oai_ai_lifecycle_toggle');?><p><label><input type="checkbox" name="oai_ai_lifecycle_ack" value="1" required> Разрешаю подключённому MCP-клиенту администратора управлять lifecycle sandbox-операций. Понимаю, что Apply меняет сайт, а Rollback возвращает поддерживаемое изменение.</label></p><button class="button button-primary" name="oai_ai_lifecycle_enable" value="1" onclick="return confirm('Разрешить AI самостоятельно подтверждать, применять и откатывать sandbox-операции?')">Включить AI Lifecycle Control</button></form><?php endif;?><p class="oai-muted">Для безопасности режим привязывается к первому MCP-клиенту после включения. Чтобы сменить клиента, выключите и включите режим снова.</p></section>
  <section class="oai-panel oai-section"><div class="oai-section-head"><h2>Test Automation Mode · QA only</h2><span class="oai-badge <?php echo !empty($test_auto['active'])?'oai-warn':'';?>"><?php echo !empty($test_auto['active'])?'АКТИВЕН':'Выключен';?></span></div><p>Временный режим для автоматических regression-тестов. Он <strong>не включает произвольные live writes</strong>: MCP может управлять lifecycle только у новых операций, помеченных текущей тестовой сессией, от того же MCP-клиента и только в разрешённых fixture-объектах.</p><?php if(!empty($test_auto['active'])):?><p><strong>Сессия:</strong> <code><?php echo esc_html($test_auto['session_id']);?></code><br><strong>Истекает:</strong> <?php echo esc_html(gmdate('Y-m-d H:i:s',(int)$test_auto['expires_at']));?> UTC<br><strong>Разрешённые post/page ID:</strong> <?php echo esc_html(implode(', ',$test_auto['allowed_post_ids'])?:'нет');?><br><strong>Разрешённые Woo product ID:</strong> <?php echo esc_html(implode(', ',$test_auto['allowed_product_ids'])?:'нет');?><br><strong>Managed Gutenberg fixture:</strong> <?php echo (int)($test_auto['managed_gutenberg_fixture_id']??0);?><br><strong>Файлы:</strong> только <code><?php echo esc_html($test_auto['file_prefix']);?></code><br><strong>Lifecycle actions:</strong> <?php echo (int)$test_auto['action_count'];?> / <?php echo (int)$test_auto['max_actions'];?></p><form method="post"><?php wp_nonce_field('oai_test_automation_toggle');?><button class="button oai-danger" name="oai_test_automation_disable" value="1" onclick="return confirm('Выключить автоматизацию тестов и вернуть обязательное ручное подтверждение?')">Выключить Test Automation</button></form><?php else:?><form method="post"><?php wp_nonce_field('oai_test_automation_toggle');?><p><label><strong>Время:</strong> <select name="oai_test_automation_ttl"><option value="15">15 минут</option><option value="30">30 минут</option><option value="60" selected>60 минут</option><option value="120">120 минут</option></select></label></p><p><label for="oai-test-pages"><strong>Страницы для тестов:</strong></label><br><?php if($test_pages):?><select id="oai-test-pages" name="oai_test_automation_posts[]" multiple size="<?php echo esc_attr(min(10,max(4,count($test_pages))));?>" style="min-width:420px;max-width:100%"><?php foreach($test_pages as $test_page):?><option value="<?php echo (int)$test_page->ID;?>"><?php echo esc_html(($test_page->post_title?:'(без названия)').' — ID '.$test_page->ID.' — '.$test_page->post_status);?></option><?php endforeach;?></select><?php else:?><span class="oai-muted">Нет draft/private страниц. Создайте отдельную тестовую страницу — опубликованные страницы специально не предлагаются.</span><?php endif;?><br><span id="oai-test-posts-help" class="oai-muted">Можно выбрать несколько страниц. В список попадают только draft/private страницы; managed Gutenberg fixture создаётся и добавляется отдельно.</span></p><p><label><strong>Woo test product ID:</strong><br><input class="regular-text" name="oai_test_automation_products" value=""></label><br><span class="oai-muted">Не указывайте боевой товар. Для Woo regression используйте специально созданный тестовый product ID: цена/остаток могут кратковременно меняться и затем откатываться.</span></p><p><label><input type="checkbox" name="oai_test_automation_ack" value="1" required> Разрешаю временный автоматический approve/apply/reject/rollback только для указанных тестовых объектов и операций этой новой сессии.</label></p><button class="button button-primary" name="oai_test_automation_enable" value="1" onclick="return confirm('Включить ограниченную автоматизацию тестов на выбранное время?')">Включить Test Automation</button></form><?php endif;?><p class="oai-muted">Старые pending-операции, созданные до включения сессии, автоматически не принимаются. При включении используется существующая управляемая draft/private Gutenberg fixture; новая создаётся только если подходящей страницы нет. При выключении/истечении TTL страница сохраняется как draft и сбрасывается к baseline для следующей тестовой сессии. Обычный human approval возвращается сразу после выключения/истечения TTL/смены сборки.</p></section>
  <section class="oai-section" id="oai-operations"><div class="oai-section-head"><h2>Требуют действия</h2><span class="oai-muted">Ожидают решения: <?php echo (int)$pending;?> · показано до 50</span></div><p class="oai-muted">Здесь всегда показываются последние 50 операций со статусом pending/approved, которым требуется решение администратора. Если таких операций больше 50, любую более старую можно открыть и обработать в постраничном журнале ниже.</p><?php self::render_operation_table($action_rows,$creators,'Нет операций, ожидающих подтверждения или применения.');?></section>
  <section class="oai-section" id="oai-operation-history"><div class="oai-section-head"><h2>Все операции</h2><span class="oai-muted">Всего: <?php echo (int)$ops_total;?> · страница <?php echo (int)$ops_page;?> / <?php echo (int)$ops_pages;?></span></div><p class="oai-muted">Полный журнал разбит по 25 операций на страницу. Здесь можно найти, просмотреть и выполнить доступное действие с любой сохранённой операцией.</p><?php self::render_operation_table($rows,$creators,'Операций пока нет.'); if($ops_pages>1){$base=add_query_arg(array('page'=>'okean-ai-admin','oai_ops_page'=>'%#%'),admin_url('admin.php')).'#oai-operation-history';echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$ops_page,'total'=>$ops_pages,'prev_text'=>'‹ Назад','next_text'=>'Вперёд ›','type'=>'plain'))).'</div></div>';}?></section>
  </div><?php
 }
}
