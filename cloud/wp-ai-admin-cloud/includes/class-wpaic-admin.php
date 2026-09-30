<?php
if (!defined('ABSPATH')) exit;

class WPAIC_Admin {
 public static function menu(){
  add_menu_page(
   'WP AI Admin Cloud',
   'WP AI Admin Cloud',
   'read',
   'wp-ai-admin-cloud',
   array(__CLASS__,'page'),
   'dashicons-cloud',
   58
  );
 }

 public static function page(){
  if(!is_user_logged_in())wp_die(esc_html__('Login required.'));

  $notice='';
  $notice_class='notice-success';

  if(isset($_POST['wpaic_claim_pairing']) && check_admin_referer('wpaic_claim_pairing')){
   $request=new WP_REST_Request('POST','/'.WPAIC_REST::NS.'/pairings/claim');
   $request->set_header('content-type','application/json');
   $request->set_body(wp_json_encode(array(
    'pairing_id'=>sanitize_text_field(wp_unslash((string)($_POST['pairing_id']??''))),
    'claim_code'=>sanitize_text_field(wp_unslash((string)($_POST['claim_code']??''))),
    'workspace_id'=>sanitize_text_field(wp_unslash((string)($_POST['workspace_id']??''))),
   )));
   $result=WPAIC_REST::pairing_claim($request);
   if($result instanceof WP_REST_Response){
    $data=$result->get_data();
    $notice=(string)($data['message']??'Could not claim site.');
    $notice_class='notice-error';
   }elseif(is_wp_error($result)){
    $notice=$result->get_error_message();
    $notice_class='notice-error';
   }else{
    $notice='Site pairing confirmed. Return to WordPress; the plugin can now complete the secure exchange.';
   }
  }

  if(isset($_POST['wpaic_revoke_site']) && check_admin_referer('wpaic_revoke_site')){
   $request=new WP_REST_Request('POST','/'.WPAIC_REST::NS.'/sites/'.sanitize_text_field(wp_unslash((string)($_POST['site_id']??''))).'/revoke');
   $request->set_url_params(array('site_id'=>sanitize_text_field(wp_unslash((string)($_POST['site_id']??'')))));
   $result=WPAIC_REST::site_revoke($request);
   if($result instanceof WP_REST_Response){
    $data=$result->get_data();
    $notice=(string)($data['message']??'Could not revoke site.');
    $notice_class='notice-error';
   }elseif(is_wp_error($result)){
    $notice=$result->get_error_message();
    $notice_class='notice-error';
   }else{
    $notice='Site access revoked.';
   }
  }

  $pairing_id=sanitize_text_field(wp_unslash((string)($_GET['pairing_id']??'')));
  $workspace_rows=self::workspaces();
  $sites=WPAIC_REST::sites_list();
  $site_rows=is_array($sites)?($sites['sites']??array()):array();

  echo '<div class="wrap"><h1>WP AI Admin Cloud</h1>';
  if($notice!=='')echo '<div class="notice '.esc_attr($notice_class).'"><p>'.esc_html($notice).'</p></div>';

  if($pairing_id!==''){
   echo '<div class="card" style="max-width:760px"><h2>Connect WordPress site</h2>';
   echo '<p>Confirm the one-time code shown in the WP AI Admin plugin. Pairing does not enable direct live writes.</p>';
   echo '<form method="post">';wp_nonce_field('wpaic_claim_pairing');
   echo '<input type="hidden" name="pairing_id" value="'.esc_attr($pairing_id).'">';
   echo '<p><label><strong>Pairing code</strong><br><input class="regular-text" required name="claim_code" autocomplete="one-time-code"></label></p>';
   echo '<p><label><strong>Workspace</strong><br><select name="workspace_id"><option value="">My Sites (default)</option>';
   foreach($workspace_rows as $ws)echo '<option value="'.esc_attr($ws['workspace_id']).'">'.esc_html($ws['name']).'</option>';
   echo '</select></label></p>';
   echo '<p><button class="button button-primary" name="wpaic_claim_pairing" value="1">Connect site</button></p></form></div>';
  }

  echo '<h2 style="margin-top:28px">My sites</h2>';
  if(!$site_rows){
   echo '<p>No sites connected yet.</p>';
  }else{
   echo '<table class="widefat striped"><thead><tr><th>Site</th><th>Workspace</th><th>Plugin</th><th>Status</th><th>Last seen</th><th></th></tr></thead><tbody>';
   foreach($site_rows as $site){
    echo '<tr><td><strong>'.esc_html($site['display_name']?:$site['domain']).'</strong><br><code>'.esc_html($site['domain']).'</code></td>';
    echo '<td>'.esc_html($site['workspace_name']??'').'</td>';
    echo '<td>'.esc_html($site['plugin_version']??'').'</td>';
    echo '<td>'.esc_html($site['status']??'').'</td>';
    echo '<td>'.esc_html($site['last_seen_at']??'—').'</td>';
    echo '<td>';
    if(($site['status']??'')!=='revoked'){
     echo '<form method="post">';wp_nonce_field('wpaic_revoke_site');
     echo '<input type="hidden" name="site_id" value="'.esc_attr($site['site_id']).'">';
     echo '<button class="button" name="wpaic_revoke_site" value="1" onclick="return confirm('Revoke this site connection?')">Revoke</button></form>';
    }
    echo '</td></tr>';
   }
   echo '</tbody></table>';
  }
  echo '</div>';
 }

 private static function workspaces(){
  global $wpdb;
  return $wpdb->get_results($wpdb->prepare(
   'SELECT workspace_id,name,status FROM '.WPAIC_DB::workspaces_table().' WHERE owner_user_id=%d AND status=%s ORDER BY id ASC',
   get_current_user_id(),'active'
  ),ARRAY_A);
 }
}
