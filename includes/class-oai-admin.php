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
 public static function dashboard_url(){return admin_url('admin.php?page=okean-ai-admin');}
 public static function menu(){
  add_menu_page('WP AI Admin','WP AI Admin','manage_options','okean-ai-admin',array(__CLASS__,'page'),'dashicons-admin-generic',58);
  // Register the parent and explicit first child before any connection submenu.
  // WordPress uses the first child as the top-level link and resolves its page hook from the parent.
  add_submenu_page('okean-ai-admin','WP AI Admin — Рабочий стол','Рабочий стол','manage_options','okean-ai-admin',array(__CLASS__,'page'),0);
  if(class_exists('OAI_Gateway_Pairing'))OAI_Gateway_Pairing::menu();
 }
 public static function handle_mcp_toggle(){
  if(!isset($_POST['oai_mcp_state']))return null;
  if(!current_user_can('manage_options'))return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')return new WP_Error('oai_mcp_method','MCP settings require POST',array('status'=>405));
  $nonce=$_POST['_wpnonce']??'';
  if(!is_string($nonce)||!wp_verify_nonce(wp_unslash($nonce),'oai_mcp_toggle'))return new WP_Error('oai_mcp_nonce','Invalid MCP settings confirmation',array('status'=>403));
  $state=$_POST['oai_mcp_state'];
  if(!is_string($state)||!in_array($state,array('enabled','disabled'),true))return new WP_Error('oai_mcp_state','Invalid MCP state',array('status'=>400));
  $enabled=$state==='enabled';
  // update_option(false) does not create a missing option because WordPress
  // treats the missing/default false value as unchanged. Persist the explicit
  // disabled state so a later read with a different default cannot re-enable MCP.
  if(get_option('oai_mcp_enabled',null)===null)add_option('oai_mcp_enabled',$enabled,'',false);
  else update_option('oai_mcp_enabled',$enabled,false);
  if(!$enabled){
   if(class_exists('OAI_AI_Lifecycle_Control'))OAI_AI_Lifecycle_Control::disable(get_current_user_id());
   if(class_exists('OAI_Test_Automation'))OAI_Test_Automation::disable('mcp_disabled',get_current_user_id());
  }
  return array('enabled'=>$enabled);
 }
 /** Dashboard "update server quota" button handler: render the confirmation or
  *  error notice for the server-projection state. Extracted so tests execute the
  *  exact button path; a missing "$" here is invisible to php -l and green CI. */
 public static function cloud_usage_refresh_notice($fresh_usage){
  $confirmed=in_array($fresh_usage['state'],array('verified','stale'),true);
  $message=$confirmed?($fresh_usage['state']==='verified'?'Серверные данные квоты обновлены и подтверждены подписью.':'Серверные данные получены, но подтверждение уже устарело.'):('Нет подтверждённых серверных данных о квоте ('.$fresh_usage['reason'].').');
  return '<div class="notice '.($confirmed?'notice-success':'notice-error').'"><p>'.esc_html($message).'</p></div>';
 }
 private static function sandbox_admin_action($id,$action){
  if(!current_user_can('manage_options'))return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));
  if(!in_array($action,array('approve','reject','apply','rollback'),true))return new WP_Error('oai_action','Unknown sandbox action',array('status'=>400));
  $req=new WP_REST_Request('POST','/okean-ai/v1/sandbox/'.absint($id).'/'.$action);
  $req->set_url_params(array('id'=>absint($id),'action'=>$action));
  if(!defined('OAI_ADMIN_HUMAN_APPROVAL')) define('OAI_ADMIN_HUMAN_APPROVAL',true);
  return OAI_REST::sandbox_action($req);
 }
 private static function operation_status_label($status){
  $map=array(
   'pending'=>'Ожидает подтверждения',
   'approved'=>'Подтверждена',
   'applying'=>'Применяется',
   'applied'=>'Применена',
   'proved'=>'Применена и проверена',
   'rejected'=>'Отклонена',
   'expired'=>'Истекла',
   'apply_failed'=>'Ошибка применения',
   'verification_failed'=>'Не прошла проверку',
   'rollback_pending'=>'Ожидает отката',
   'rolling_back'=>'Откатывается',
   'rolled_back'=>'Откатана',
   'rollback_failed'=>'Ошибка отката',
  );
  return isset($map[$status])?$map[$status]:$status;
 }
 private static function operation_status_class($status){
  if(in_array($status,array('applied','proved','rolled_back'),true))return 'oai-ok';
  if(in_array($status,array('pending','approved','applying','rollback_pending','rolling_back'),true))return 'oai-warn';
  if(in_array($status,array('rejected','expired'),true))return 'oai-muted-badge';
  return 'oai-bad';
 }
 private static function operation_type_label($type){
  $map=array(
   'post_title'=>'Заголовок записи',
   'post_excerpt'=>'Краткое описание',
   'post_content'=>'Содержимое записи',
   'post_create'=>'Создание записи',
   'post_update'=>'Обновление записи',
   'content_patch'=>'Изменение содержимого',
   'workspace_write'=>'Запись в рабочую область',
   'elementor_widget_html'=>'HTML виджета Elementor',
   'elementor_widget_settings'=>'Настройки виджета Elementor',
   'elementor_page_create'=>'Создание страницы Elementor',
   'elementor_page_layout'=>'Структура страницы Elementor',
   'woo_product_price'=>'Цена товара',
   'woo_product_stock'=>'Остаток товара',
   'woocommerce_patch'=>'Изменение WooCommerce',
   'woocommerce_product'=>'Товар WooCommerce',
   'woocommerce_product_create'=>'Создание товара',
   'woocommerce_order'=>'Заказ WooCommerce',
   'woocommerce_coupon'=>'Купон WooCommerce',
   'media_alt'=>'Alt-текст медиафайла',
   'media_title'=>'Название медиафайла',
   'media_featured'=>'Изображение записи',
   'media_upload'=>'Загрузка медиафайла',
   'menu_create'=>'Создание меню',
   'menu_add_item'=>'Добавление пункта меню',
   'menu_remove_item'=>'Удаление пункта меню',
   'menu_move_item'=>'Перемещение пункта меню',
   'menu_assign_location'=>'Расположение меню',
  );
  return isset($map[$type])?$map[$type]:$type;
 }
 private static function operation_risk_label($risk){
  $map=array('low'=>'Низкий','medium'=>'Средний','high'=>'Высокий');
  return isset($map[$risk])?$map[$risk]:$risk;
 }
 private static function operation_risk_class($risk){
  return in_array($risk,array('low','medium','high'),true)?'oai-risk-'.$risk:'oai-risk-high';
 }
 private static function license_status_label($status){
  $map=array(
   'unused'=>'Не активирована',
   'active'=>'Активна',
   'expired'=>'Истекла',
   'suspended'=>'Приостановлена',
   'revoked'=>'Отозвана',
   'exhausted'=>'Исчерпала активации',
   'replaced'=>'Заменена',
  );
  return isset($map[$status])?$map[$status]:($status!==''?$status:'—');
 }
 private static function render_operation_rows($rows,$creators,$empty_text='Операций пока нет.'){
  if(!$rows){echo '<tr><td colspan="5" class="oai-empty">'.esc_html($empty_text).'</td></tr>';return;}
  foreach($rows as $r){
   $creator_id=$creators[(int)$r['id']]??0;$creator=$creator_id?get_userdata($creator_id):false;$who=$creator?$creator->display_name.' (ID '.$creator_id.')':($creator_id?'Пользователь #'.$creator_id:'Не установлен');
   $before=json_decode((string)$r['before_data'],true);$after=json_decode((string)$r['after_data'],true);
   $pretty=function($v){return is_string($v)?$v:wp_json_encode($v,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);};
   echo '<tr>';
   echo '<td class="oai-op-main"><strong>#'.(int)$r['id'].'</strong><br><small class="oai-muted">'.esc_html((string)$r['created_at']).' UTC</small><br><span class="oai-op-type">'.esc_html(self::operation_type_label((string)$r['type'])).'</span><br><small><code>'.esc_html((string)$r['operation_id']).'</code></small></td>';
   echo '<td>'.esc_html((string)$r['target']).'</td>';
   echo '<td><span class="oai-status '.self::operation_status_class((string)$r['status']).'">'.esc_html(self::operation_status_label((string)$r['status'])).'</span></td>';
   echo '<td><span class="oai-risk '.self::operation_risk_class((string)$r['risk']).'">'.esc_html(self::operation_risk_label((string)$r['risk'])).'</span></td>';
   echo '<td><div class="oai-actions"><details><summary>Просмотр изменений</summary><div class="oai-detail"><strong>Операция #'.(int)$r['id'].': '.esc_html(self::operation_type_label((string)$r['type'])).' → '.esc_html((string)$r['target']).'</strong><small>Создана: '.esc_html((string)$r['created_at']).' UTC · Автор: '.esc_html($who).' · Статус: '.esc_html(self::operation_status_label((string)$r['status'])).'</small><strong>Было</strong><pre>'.esc_html($pretty($before)).'</pre><strong>Станет после применения</strong><pre>'.esc_html($pretty($after)).'</pre><small>До применения показан план. Просмотр ничего не меняет на сайте.</small>';
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
  echo '<div class="oai-scroll"><table class="widefat striped oai-ops-table"><thead><tr><th>Операция</th><th>Объект</th><th>Статус</th><th>Риск</th><th>Изменения и действие</th></tr></thead><tbody>';
  self::render_operation_rows($rows,$creators,$empty_text);
  echo '</tbody></table></div>';
 }
 public static function page(){
  if(!current_user_can('manage_options')) wp_die(esc_html__('Недостаточно прав.'));
  $mcp_toggle=self::handle_mcp_toggle();
  if($mcp_toggle!==null){
   echo '<div class="notice '.(is_wp_error($mcp_toggle)?'notice-error':'notice-success').'"><p>'.esc_html(is_wp_error($mcp_toggle)?$mcp_toggle->get_error_message():($mcp_toggle['enabled']?'Доступ ИИ включён. Подключите этот сайт через OAuth.':'Доступ ИИ выключен. Автоматические режимы остановлены; OAuth-подключения сохранены.')).'</p></div>';
  }
  if(isset($_POST['oai_cloud_usage_refresh']) && check_admin_referer('oai_cloud_usage_refresh')){
   echo self::cloud_usage_refresh_notice(OAI_Cloud_Usage::status(true));
  }
  if(isset($_POST['oai_cloud_license_activate']) && check_admin_referer('oai_cloud_license_activate')){
   $license_key=isset($_POST['oai_license_key'])&&is_string($_POST['oai_license_key'])?trim(wp_unslash($_POST['oai_license_key'])):'';
   $activated=OAI_Cloud_Usage::activate_license($license_key);
   if(is_wp_error($activated)){
    echo '<div class="notice notice-error"><p>'.esc_html($activated->get_error_message()).'</p></div>';
   }else{
    $message='Ключ активирован на сервере WP AI Admin.';
    if(!empty($activated['plan_label']))$message.=' Тариф: '.$activated['plan_label'].'.';
    if(!empty($activated['quota_pending']))$message.=' Квота тарифа ожидает утверждения владельцем; платные вызовы пока не выполняются.';
    elseif(empty($activated['usage_valid']))$message.=' Подтверждённые данные квоты пока не получены; нажмите «Обновить данные с сервера».';
    echo '<div class="notice notice-success"><p>'.esc_html($message).'</p></div>';
   }
  }
  if(isset($_POST['oai_ai_lifecycle_enable']) && check_admin_referer('oai_ai_lifecycle_toggle')){
   if(defined('OAI_BUILD_CHANGED_THIS_REQUEST')&&OAI_BUILD_CHANGED_THIS_REQUEST){
    echo '<div class="notice notice-warning"><p>WP AI Admin только что обновлён. Автономные режимы сброшены; обновите эту страницу и включите автоматическое подтверждение ИИ отдельным действием.</p></div>';
   }elseif(empty($_POST['oai_ai_lifecycle_ack'])){
    echo '<div class="notice notice-error"><p>Подтвердите, что разрешаете ИИ управлять подтверждением, применением и откатом sandbox-операций.</p></div>';
   }else{
    $result=OAI_AI_Lifecycle_Control::enable(get_current_user_id());
    echo '<div class="notice '.(is_wp_error($result)?'notice-error':'notice-warning').'"><p>'.esc_html(is_wp_error($result)?$result->get_error_message():'Автоматическое подтверждение ИИ включено. Подключённый ИИ-клиент администратора может подтверждать, применять и откатывать операции; ручные кнопки остаются доступны.').'</p></div>';
   }
  }
  if(isset($_POST['oai_ai_lifecycle_disable']) && check_admin_referer('oai_ai_lifecycle_toggle')){
   OAI_AI_Lifecycle_Control::disable(get_current_user_id());
   echo '<div class="notice notice-success"><p>Автоматическое подтверждение ИИ выключено. Ручное управление операциями сохранено.</p></div>';
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
   echo '<div class="notice notice-success"><p>'.esc_html(sprintf('Доступ ИИ отключён. Активных OAuth-токенов отозвано: %d.',max(0,$count))).'</p></div>';
  }
  $active_clients=(int)$wpdb->get_var('SELECT COUNT(DISTINCT client_id) FROM '.OAI_MCP_OAuth::tokens_table().' WHERE revoked=0 AND (expires_at > UTC_TIMESTAMP() OR refresh_expires_at > UTC_TIMESTAMP())');
  $ai_lifecycle=OAI_AI_Lifecycle_Control::status();
  $cloud_usage=OAI_Cloud_Usage::status();
  $projection=is_array($cloud_usage['projection']??null)?$cloud_usage['projection']:null;
  $server_ready=$projection!==null&&!empty($cloud_usage['available']);
  $quota_pending=$server_ready&&(($projection['quota_status']??'approved')!=='approved');
  $local_quota=OAI_Quota::status();
  $mcp_enabled=(bool)get_option('oai_mcp_enabled',false);
  $connected=class_exists('OAI_Gateway_Pairing')&&OAI_Gateway_Pairing::is_connected();
  $account_label=trim((string)($cloud_usage['account_label']??''));
  $plan_label=$server_ready?trim((string)($projection['plan_label']??'')):'';
  if($plan_label==='')$plan_label=$server_ready?ucfirst((string)($projection['plan']??'')):(string)($local_quota['label']??'Free');
  $license_prefix=$server_ready?trim((string)($projection['license_key_prefix']??'')):'';
  $license_status=$server_ready?(string)($projection['license_status']??''):'';
  $license_limit=$server_ready?max(0,(int)($projection['license_activation_limit']??0)):0;
  $license_activations=$server_ready?max(0,(int)($projection['license_activations']??0)):0;
  $license_issued=$server_ready?max(0,(int)($projection['license_issued_at']??0)):0;
  $license_expires=$server_ready?max(0,(int)($projection['license_expires_at']??0)):0;
  $action_statuses=array('pending','approved');$pending=OAI_DB::count_by_status($action_statuses);$action_rows=OAI_DB::by_status($action_statuses,self::ACTION_TABLE_LIMIT,0);
  $ops_per_page=self::OPERATIONS_PER_PAGE;$ops_total=OAI_DB::count_all();$ops_pages=max(1,(int)ceil($ops_total/$ops_per_page));$ops_page=max(1,absint($_REQUEST['oai_ops_page']??1));if($ops_page>$ops_pages)$ops_page=$ops_pages;
  $rows=OAI_DB::page($ops_per_page,($ops_page-1)*$ops_per_page);
  $creator_source=array();foreach(array_merge($action_rows,$rows) as $row)$creator_source[(int)$row['id']]=$row;
  $creators=array(); if($creator_source){ $ids=array_keys($creator_source); $placeholders=implode(',',array_fill(0,count($ids),'%d')); $audit_rows=$wpdb->get_results($wpdb->prepare('SELECT operation_db_id, actor FROM '.OAI_DB::audit_table().' WHERE event=%s AND operation_db_id IN ('.$placeholders.') ORDER BY id ASC',array_merge(array('created'),$ids)),ARRAY_A); foreach($audit_rows as $entry){$key=(int)$entry['operation_db_id'];if(!array_key_exists($key,$creators))$creators[$key]=absint($entry['actor']);} }
  $used_pct=0;
  if($server_ready&&!$quota_pending){
   $server_limit=max(1,(int)$projection['limit']);
   $used_pct=min(100,(int)round((((int)$projection['used']+(int)$projection['reserved'])/$server_limit)*100));
  }
  ?>
  <style>
  .oai-dash{max-width:1180px;margin:24px auto 56px;padding:0 18px;color:#16253c}.oai-dash *{box-sizing:border-box}
  .oai-head{display:flex;align-items:center;gap:14px;margin:6px 0 22px}.oai-mark{display:grid;place-items:center;background:#07366f;color:#fff;border-radius:14px;width:48px;height:48px;font-size:22px}.oai-head h1{margin:0;font-size:26px;line-height:1.2}
  .oai-muted{color:#64748b}.oai-eyebrow{display:inline-block;font-size:11px;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:#a8cbf5}
  .oai-hero{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(250px,.9fr);gap:28px;background:linear-gradient(135deg,#07366f,#0b4f97);color:#fff;border-radius:18px;padding:30px;box-shadow:0 12px 30px rgba(7,54,111,.18)}
  .oai-hero h2{margin:6px 0 10px;font-size:25px;color:#fff}.oai-hero p{color:#dbe9fb;margin:0 0 12px;font-size:14px;line-height:1.65}.oai-hero .button-primary{background:#fff;color:#07366f;border-color:#fff;font-weight:700}.oai-hero .button-primary:hover{background:#eaf2ff;color:#07366f}
  .oai-hero-secondary{color:#dbe9fb!important;border-color:#7ea6d8!important;background:transparent!important}.oai-hero-secondary:hover{background:rgba(255,255,255,.12)!important;color:#fff!important}
  .oai-points{margin:0 0 18px;padding-left:20px;color:#e5efff;font-size:13px;line-height:1.75}
  .oai-hero-status{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.25);border-radius:14px;padding:18px;align-self:start}.oai-hero-status h3{margin:0 0 10px;color:#fff;font-size:15px}
  .oai-hero-status dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 10px;font-size:13px}.oai-hero-status dt{color:#a8cbf5}.oai-hero-status dd{margin:0;color:#fff;overflow-wrap:anywhere}
  .oai-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:18px 0}.oai-card{background:#fff;border:1px solid #dbe4ef;border-radius:16px;padding:20px;box-shadow:0 2px 10px rgba(15,23,42,.04)}
  .oai-card h2{margin:0 0 8px;font-size:17px;color:#16253c}.oai-card p{font-size:13px;line-height:1.6}.oai-kpi{font-size:30px;font-weight:700;color:#07366f;line-height:1.15;margin:6px 0}.oai-kpi small{font-size:14px;font-weight:600;color:#64748b}.oai-kpi-note{font-size:13px!important;color:#64748b;line-height:1.6}
  .oai-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;background:#e8f7ed;color:#126436;font-weight:600;font-size:12px}.oai-badge.oai-warn{background:#fff4e5;color:#8a4b00}.oai-badge.oai-bad{background:#fdecec;color:#a01b1b}.oai-badge.oai-info{background:#e8f1fd;color:#0b4f97}
  .oai-section{margin-top:20px}.oai-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px}.oai-section-head h2{margin:0;font-size:19px}
  .oai-licence{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:18px}.oai-licence-block{background:#f8fafd;border:1px solid #e4ebf4;border-radius:12px;padding:16px}.oai-licence-block h3{margin:0 0 10px;font-size:12px;color:#475569;text-transform:uppercase;letter-spacing:.06em}.oai-licence-activate{margin-top:18px}
  .oai-licence dl{display:grid;grid-template-columns:auto 1fr;gap:7px 14px;margin:0;font-size:13px}.oai-licence dt{color:#64748b}.oai-licence dd{margin:0;color:#16253c;overflow-wrap:anywhere}
  .oai-quota-line{display:flex;align-items:baseline;gap:8px;margin:4px 0 10px}.oai-quota-line strong{font-size:28px;color:#07366f}.oai-progress{height:8px;background:#e3ebf5;border-radius:999px;overflow:hidden;margin:10px 0}.oai-progress span{display:block;height:100%;background:#0b6bcb;border-radius:999px}
  .oai-note{border-left:3px solid #0b6bcb;background:#f4f8ff;padding:12px 14px;border-radius:8px;font-size:13px;color:#334155;line-height:1.6}.oai-note.oai-note-warn{border-color:#e77a18;background:#fff7ec}
  .oai-scroll{overflow:auto;background:#fff;border:1px solid #dbe4ef;border-radius:14px}.oai-dash table{border:0;box-shadow:none;margin:0}.oai-dash th,.oai-dash td{vertical-align:top;padding:12px 14px}.oai-ops-table th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}
  .oai-op-main{white-space:nowrap}.oai-op-type{font-weight:600}.oai-status{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;white-space:nowrap}.oai-status.oai-ok{background:#e8f7ed;color:#126436}.oai-status.oai-warn{background:#fff4e5;color:#8a4b00}.oai-status.oai-bad{background:#fdecec;color:#a01b1b}.oai-status.oai-muted-badge{background:#eef2f7;color:#475569}
  .oai-risk{font-size:12px;font-weight:600;padding:4px 9px;border-radius:6px;background:#eef2f7;color:#475569;white-space:nowrap}.oai-risk-low{background:#e8f7ed;color:#126436}.oai-risk-medium{background:#fff4e5;color:#8a4b00}.oai-risk-high{background:#fdecec;color:#a01b1b}
  .oai-actions{display:flex;flex-direction:column;gap:8px;min-width:200px}.oai-actions form{display:flex;gap:6px;flex-wrap:wrap;margin:0}.oai-dash details summary{cursor:pointer;color:#0b4f97;font-weight:600}.oai-detail{width:min(560px,70vw);padding:14px;margin-top:8px;border:1px solid #dbe4ef;border-radius:10px;background:#f8fafd;white-space:normal;font-weight:400}.oai-detail pre{max-height:260px;max-width:100%;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;border:1px solid #e2e7ec;padding:8px}.oai-detail small{display:block;color:#64748b;margin:8px 0}
  .oai-preview-page-button{margin-top:8px!important;display:inline-block!important;color:#07366f!important;border-color:#9fb9da!important;font-weight:600!important}.oai-danger{color:#b32d2e!important;border-color:#b32d2e!important}.oai-dash a:focus,.oai-dash button:focus{outline:2px solid #e77a18;outline-offset:2px}.oai-empty{color:#64748b;text-align:center;padding:24px!important}
  @media(max-width:900px){.oai-hero,.oai-licence,.oai-kpis{grid-template-columns:1fr}}
  </style>
  <div class="wrap oai-dash">
  <div class="oai-head"><div class="oai-mark" aria-hidden="true">✦</div><div><h1>WP AI Admin</h1><div class="oai-muted">Подключение, тариф и контроль изменений сайта</div></div></div>

  <section class="oai-hero">
   <div>
    <span class="oai-eyebrow">Рабочий стол</span>
    <h2>Подключите сайт к WP AI Admin</h2>
    <p>Подключение открывает ИИ-помощнику доступ к этому сайту: он читает разрешённые данные и готовит изменения в песочнице. Каждое изменение вы проверяете, подтверждаете и применяете сами — а при необходимости откатываете.</p>
    <ul class="oai-points">
     <li>Один аккаунт и один тариф работают для всех подключённых сайтов.</li>
     <li>Без подключения ИИ не получает доступ к сайту.</li>
     <li>Доступ можно отозвать в любой момент.</li>
    </ul>
    <p><a class="button button-primary" href="<?php echo esc_url(OAI_Gateway_Pairing::portal_url());?>">Подключить сайт</a> <a class="button oai-hero-secondary" href="<?php echo esc_url(OAI_Gateway_Pairing::connections_url());?>">Управление подключениями</a></p>
   </div>
   <aside class="oai-hero-status">
    <h3>Состояние</h3>
    <dl>
     <dt>Сайт</dt><dd><?php echo $connected?'Подключён к WP AI Admin':($active_clients?'Подключён напрямую':'Не подключён');?></dd>
     <dt>Аккаунт</dt><dd><?php echo $account_label!==''?esc_html($account_label):'—';?></dd>
     <dt>Доступ ИИ</dt><dd><?php echo $mcp_enabled?'Включён':'Выключен';?></dd>
    </dl>
   </aside>
  </section>

  <div class="oai-kpis">
   <section class="oai-card">
    <h2>Подключение</h2>
    <span class="oai-badge <?php echo $connected?'':'oai-warn';?>"><?php echo $connected?'Сайт подключён':($active_clients?'Прямое подключение':'Нет подключения');?></span>
    <p class="oai-kpi-note"><?php echo $account_label!==''?'Аккаунт: '.esc_html($account_label).'. ':'';?>Доступ ИИ <?php echo $mcp_enabled?'включён':'выключен';?>. <?php echo $active_clients?'Активных AI-клиентов: '.(int)$active_clients.'.':'AI-клиенты пока не подключены.';?></p>
    <?php if($active_clients):?><form method="post"><?php wp_nonce_field('oai_disconnect_all');?><button class="button oai-danger" name="oai_disconnect_all" value="1" onclick="return confirm('Отключить всех AI-клиентов от этого сайта? Для повторного подключения потребуется авторизация.')">Отключить доступ</button></form><?php endif;?>
   </section>
   <section class="oai-card">
    <h2>Ожидают решения</h2>
    <div class="oai-kpi"><?php echo (int)$pending;?></div>
    <p class="oai-kpi-note"><?php echo $pending?'Операций ждут подтверждения или применения.':'Сейчас нет операций, требующих вашего решения.';?></p>
    <a href="#oai-operations">Перейти к операциям ↓</a>
   </section>
   <section class="oai-card">
    <h2>Вызовы</h2>
    <?php if($server_ready&&!$quota_pending):?>
     <div class="oai-kpi"><?php echo (int)$projection['remaining'];?><small> / <?php echo (int)$projection['limit'];?></small></div>
     <div class="oai-progress"><span style="width:<?php echo esc_attr($used_pct);?>%"></span></div>
     <p class="oai-kpi-note">Осталось вызовов в текущем периоде. Использовано <?php echo (int)$projection['used'];?>, зарезервировано <?php echo (int)$projection['reserved'];?>. Период: <?php echo esc_html((string)$projection['period_start_utc']);?> — <?php echo esc_html((string)$projection['period_end_utc']);?> UTC.</p>
    <?php elseif($quota_pending):?>
     <div class="oai-kpi">—</div>
     <p class="oai-kpi-note">Квота тарифа <?php echo esc_html($plan_label);?> ожидает утверждения владельцем. Использовано <?php echo (int)$projection['used'];?>. Платные вызовы приостановлены; чтение, проверки, квитанции и откат доступны.</p>
    <?php else:?>
     <div class="oai-kpi"><?php echo (int)$local_quota['remaining'];?><small> / <?php echo (int)$local_quota['limit'];?></small></div>
     <p class="oai-kpi-note">Локальный лимит до подключения сайта. После подключения здесь появится серверная квота аккаунта.</p>
    <?php endif;?>
    <?php if($cloud_usage['state']==='stale'):?><p class="oai-note oai-note-warn">Показаны последние подтверждённые серверные данные; связь с сервером временно недоступна.</p><?php endif;?>
   </section>
  </div>

  <section class="oai-card oai-section">
   <div class="oai-section-head"><h2>Тариф и лицензия</h2><?php if($server_ready):?><span class="oai-badge oai-info"><?php echo esc_html($plan_label);?></span><?php endif;?></div>
   <?php if($server_ready):?>
    <div class="oai-licence">
     <div class="oai-licence-block">
      <h3>Квота</h3>
      <?php if($quota_pending):?>
       <p class="oai-note oai-note-warn">Квота платного тарифа ожидает утверждения владельцем. До утверждения платные вызовы не выполняются и не списываются; чтение, проверки, квитанции и откат остаются доступными.</p>
      <?php else:?>
       <div class="oai-quota-line"><strong><?php echo (int)$projection['remaining'];?></strong><span class="oai-muted">из <?php echo (int)$projection['limit'];?> вызовов осталось</span></div>
       <div class="oai-progress"><span style="width:<?php echo esc_attr($used_pct);?>%"></span></div>
       <p class="oai-kpi-note">Использовано <?php echo (int)$projection['used'];?>, зарезервировано <?php echo (int)$projection['reserved'];?>. Период: <?php echo esc_html((string)$projection['period_start_utc']);?> — <?php echo esc_html((string)$projection['period_end_utc']);?> UTC.</p>
      <?php endif;?>
      <p class="oai-kpi-note">Схема: <code><?php echo esc_html((string)$projection['quota_scheme']);?></code> · версия политики: <code><?php echo esc_html((string)$projection['policy_version']);?></code></p>
     </div>
     <div class="oai-licence-block">
      <h3>Лицензия</h3>
      <?php if($license_prefix!==''):?>
       <dl>
        <dt>Ключ</dt><dd><code><?php echo esc_html($license_prefix);?>…</code></dd>
        <dt>Статус</dt><dd><?php echo esc_html(self::license_status_label($license_status));?></dd>
        <dt>Активации</dt><dd><?php echo (int)$license_activations;?> из <?php echo (int)$license_limit;?></dd>
        <dt>Выдана</dt><dd><?php echo $license_issued?esc_html(gmdate('d.m.Y H:i',$license_issued).' UTC'):'—';?></dd>
        <dt>Действует до</dt><dd><?php echo $license_expires?esc_html(gmdate('d.m.Y H:i',$license_expires).' UTC'):'бессрочно';?></dd>
       </dl>
       <p class="oai-kpi-note">Полный ключ показывается один раз при выдаче и не хранится на сервере; здесь отображается только безопасный префикс.</p>
      <?php else:?>
       <p class="oai-kpi-note">К аккаунту не привязана отдельная лицензия: действует тариф аккаунта.</p>
      <?php endif;?>
     </div>
    </div>
    <p class="oai-kpi-note">Тариф, лимит и остаток определяет сервер WP AI Admin. Рабочий стол показывает только подтверждённые подписью серверные данные и не считает баланс самостоятельно.</p>
    <?php if(!empty($cloud_usage['hash'])):?><form method="post"><?php wp_nonce_field('oai_cloud_usage_refresh');?><input type="hidden" name="oai_cloud_usage_refresh" value="1"><button class="button" type="submit">Обновить данные с сервера</button></form><?php endif;?>
   <?php else:?>
    <p>Тариф и лицензия появятся после подключения сайта.<?php if($cloud_usage['state']==='unknown'):?> Серверные данные сейчас недоступны; локальный счётчик не заменяет серверную квоту.<?php endif;?></p>
    <p class="oai-kpi-note">До подключения действует локальный режим этого сайта: <?php echo esc_html((string)$local_quota['label']);?>, <?php echo (int)$local_quota['limit'];?> вызовов, использовано <?php echo (int)$local_quota['used'];?>.</p>
   <?php endif;?>
   <?php if(!empty($cloud_usage['hash'])):?>
    <div class="oai-licence-block oai-licence-activate">
     <h3>Активировать ключ тарифа</h3>
     <p class="oai-kpi-note">Введите ключ, полученный после покупки тарифа. Проверка и привязка выполняются на сервере WP AI Admin; ключ не сохраняется на этом сайте.</p>
     <form method="post">
      <?php wp_nonce_field('oai_cloud_license_activate');?>
      <p><input type="password" name="oai_license_key" class="regular-text" autocomplete="new-password" spellcheck="false" required placeholder="wpai_lic_…" aria-label="Ключ лицензии"></p>
      <p><button class="button button-primary" type="submit" name="oai_cloud_license_activate" value="1">Активировать ключ</button></p>
     </form>
    </div>
   <?php endif;?>
  </section>

  <section class="oai-card oai-section">
   <div class="oai-section-head"><h2>Автоматическое подтверждение ИИ</h2><span class="oai-badge <?php echo !empty($ai_lifecycle['active'])?'oai-warn':'';?>"><?php echo !empty($ai_lifecycle['active'])?'Включено':'Выключено';?></span></div>
   <p>Опциональный режим для обычной работы: подключённый ИИ-клиент администратора может самостоятельно выполнять <strong>Подтвердить / Отклонить / Применить / Откатить</strong>. Все проверки песочницы, конфликтов, срока действия, Verify и отката сохраняются. Ручные кнопки администратора продолжают работать параллельно.</p>
   <?php if(!empty($ai_lifecycle['active'])):?>
    <p><strong>Владелец:</strong> пользователь ID <?php echo (int)$ai_lifecycle['owner_user_id'];?> · <strong>Права:</strong> администратор сайта · <strong>ИИ-клиент:</strong> только аудит</p>
    <form method="post"><?php wp_nonce_field('oai_ai_lifecycle_toggle');?><button class="button oai-danger" name="oai_ai_lifecycle_disable" value="1" onclick="return confirm('Выключить автоматическое подтверждение ИИ? Ручные кнопки останутся доступны.')">Выключить автоматическое подтверждение</button></form>
   <?php else:?>
    <form method="post"><?php wp_nonce_field('oai_ai_lifecycle_toggle');?><p><label><input type="checkbox" name="oai_ai_lifecycle_ack" value="1" required> Разрешаю подключённому ИИ-клиенту администратора управлять подтверждением, применением и откатом операций. Понимаю, что применение меняет сайт, а откат возвращает поддерживаемое изменение.</label></p><button class="button button-primary" name="oai_ai_lifecycle_enable" value="1" onclick="return confirm('Разрешить ИИ самостоятельно подтверждать, применять и откатывать операции?')">Включить автоматическое подтверждение</button></form>
   <?php endif;?>
   <p class="oai-kpi-note">Право подтверждать, применять и откатывать операцию привязано к администратору, контексту сайта и одобренному снапшоту операции. Смена ИИ-клиента или токена не переносит владение.</p>
  </section>

  <section class="oai-card oai-section">
   <div class="oai-section-head"><h2>Подключение MCP</h2><span class="oai-badge <?php echo $mcp_enabled?'':'oai-warn';?>"><?php echo $mcp_enabled?'Включено':'Выключено';?></span></div>
   <p>Этот переключатель разрешает или останавливает доступ ИИ к этому сайту. Подключите сайт автоматически в кабинете WP AI Admin, затем разрешите доступ к нужным сайтам при авторизации.</p>
   <p><strong>Единый адрес для ChatGPT:</strong> <code>https://wpaiadmin.ru/mcp</code></p>
   <p>Один аккаунт и один тариф могут использоваться для нескольких сайтов. Отдельное OAuth-подключение для каждого сайта не требуется.</p>
   <p><a class="button button-primary" href="<?php echo esc_url(OAI_Gateway_Pairing::portal_url());?>">Подключить сайт</a></p>
   <p class="oai-kpi-note">Включение доступа ИИ не включает автоматическое подтверждение операций. Идентификаторы сайта и OAuth-подключения сохраняются при обновлении плагина.</p>
   <?php if(parse_url((string)get_option('siteurl',''),PHP_URL_SCHEME)!=='https'||parse_url((string)get_option('home',''),PHP_URL_SCHEME)!=='https'):?><p class="oai-warn">Для удалённого подключения укажите HTTPS в обоих адресах WordPress. <a href="<?php echo esc_url(admin_url('options-general.php'));?>">Открыть общие настройки</a></p><?php endif;?>
   <form method="post"><?php wp_nonce_field('oai_mcp_toggle');?><button class="button <?php echo $mcp_enabled?'':'button-primary';?>" name="oai_mcp_state" value="<?php echo $mcp_enabled?'disabled':'enabled';?>"><?php echo $mcp_enabled?'Выключить доступ ИИ':'Включить доступ ИИ';?></button></form>
   <p><a href="https://wpaiadmin.ru/.well-known/oauth-protected-resource/mcp" target="_blank" rel="noopener noreferrer">Проверить ресурс gateway</a> · <a href="https://wpaiadmin.ru/.well-known/oauth-authorization-server/wpai-cloud" target="_blank" rel="noopener noreferrer">Проверить авторизацию gateway</a></p>
   <details><summary>Прямое подключение и диагностика этого сайта</summary><p>Для отдельного подключения к этому сайту используется локальный адрес. Он отличается от общего gateway.</p><p><strong>Локальный MCP:</strong> <code><?php echo esc_html(rest_url('okean-ai/mcp'));?></code></p><p><a href="<?php echo esc_url(home_url('/.well-known/oauth-protected-resource'));?>" target="_blank" rel="noopener noreferrer">Локальный ресурс OAuth</a> · <a href="<?php echo esc_url(home_url('/.well-known/oauth-authorization-server'));?>" target="_blank" rel="noopener noreferrer">Локальная авторизация OAuth</a></p></details>
  </section>

  <section class="oai-card oai-section">
   <div class="oai-section-head"><h2>Выборочное чтение больших данных</h2><span class="oai-badge">Включено</span></div>
   <p>Режим включён по умолчанию и не требует настройки. Он помогает ИИ читать большие файлы, страницы и структуру Elementor/HTML частями — обзор, диапазоны строк, краткий вид, — не загружая объект целиком. Это снижает нагрузку на сайт и риск таймаутов.</p>
   <p class="oai-kpi-note">Режим работает только на чтение и не разрешает запись. Отключение доступно только на уровне кода константой <code>OAI_DISABLE_CONTENT_NAVIGATION</code> и предназначено для экстренной диагностики.</p>
  </section>

  <section class="oai-section" id="oai-operations">
   <div class="oai-section-head"><h2>Требует действия</h2><span class="oai-muted">Ожидают решения: <?php echo (int)$pending;?> · показано до 50</span></div>
   <p class="oai-kpi-note">Здесь показаны последние операции со статусом «ожидает подтверждения» или «подтверждена», которым нужно ваше решение. Более старые операции доступны в журнале ниже.</p>
   <?php self::render_operation_table($action_rows,$creators,'Нет операций, ожидающих подтверждения или применения.');?>
  </section>

  <section class="oai-section" id="oai-operation-history">
   <div class="oai-section-head"><h2>Все операции</h2><span class="oai-muted">Всего: <?php echo (int)$ops_total;?> · страница <?php echo (int)$ops_page;?> / <?php echo (int)$ops_pages;?></span></div>
   <p class="oai-kpi-note">Полный журнал разбит по 25 операций на страницу. Здесь можно найти, просмотреть и выполнить доступное действие с любой сохранённой операцией.</p>
   <?php self::render_operation_table($rows,$creators,'Операций пока нет.'); if($ops_pages>1){$base=add_query_arg(array('page'=>'okean-ai-admin','oai_ops_page'=>'%#%'),admin_url('admin.php')).'#oai-operation-history';echo '<div class="tablenav"><div class="tablenav-pages">'.wp_kses_post(paginate_links(array('base'=>$base,'format'=>'','current'=>$ops_page,'total'=>$ops_pages,'prev_text'=>'‹ Назад','next_text'=>'Вперёд ›','type'=>'plain'))).'</div></div>';}?>
  </section>
  </div>
  <?php
 }
}
