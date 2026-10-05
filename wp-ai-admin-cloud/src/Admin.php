<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Admin {
    private static function submitted(string $name): string {
        $value=$_POST[$name]??'';
        if (!is_string($value) || strlen($value)>2048) throw new Fault('invalid_form_input');
        return wp_unslash($value);
    }
    private static function providers(): array {
        return [
            'chatgpt'=>['label'=>'ChatGPT','endpoint'=>Settings::endpoint()],
            'qwen'=>['label'=>'Qwen 3.8 Max','endpoint'=>Settings::sseEndpoint()],
            'claude'=>['label'=>'Claude','endpoint'=>Settings::endpoint()],
            'deepseek'=>['label'=>'DeepSeek','endpoint'=>Settings::endpoint()],
        ];
    }
    private static function form(string $action,string $label,string $fields=''): void {
        echo '<form method="post" style="margin:16px 0">';
        wp_nonce_field('wpai_gateway_'.$action,'wpai_gateway_nonce');
        echo '<input type="hidden" name="wpai_gateway_action" value="'.esc_attr($action).'">'.$fields;
        echo '<p><button class="button button-primary" type="submit">'.esc_html($label).'</button></p></form>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        nocache_headers();
        $output=null; $secret=null;
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && isset($_POST['wpai_gateway_action'])) {
            try {
                if (!is_ssl()) throw new Fault('https_required',403);
                $action=self::submitted('wpai_gateway_action');
                check_admin_referer('wpai_gateway_'.$action,'wpai_gateway_nonce');
                if ($action==='enable') { WordPress::enable(); $output=['gateway'=>'enabled']; }
                elseif ($action==='disable') {
                    if (defined('WP_AI_CLOUD_ENABLED')) throw new Fault('gateway_mode_managed_in_wp_config',409);
                    update_option('wpai_gateway_enabled',false,false); $output=['gateway'=>'disabled'];
                } elseif ($action==='beta_users') {
                    $text=self::submitted('beta_users');
                    if(!preg_match('/^[0-9, ]*$/D',$text))throw new Fault('invalid_beta_user_ids');
                    $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/[, ]+/',$text)))));
                    if(count($ids)>100)throw new Fault('beta_user_limit');
                    update_option('wpai_gateway_beta_users',$ids,false);$output=['beta_users'=>$ids];
                } else {
                    $core=WordPress::operatorCore(); $owner=WordPress::operatorOwner();
                    if ($action==='connect') $output=$core->connect($owner,home_url('/'),self::submitted('site_token'),time());
                    elseif ($action==='provider_site') {
                        $provider=self::submitted('provider');$site=self::submitted('site_id');$providers=self::providers();
                        if(!isset($providers[$provider]))throw new Fault('invalid_provider');
                        $owned=array_column($core->sites($owner),'id');if(!in_array($site,$owned,true))throw new Fault('invalid_site_selection',403);
                        $map=get_user_meta((int)$owner,'wpai_cloud_provider_sites',true);if(!is_array($map))$map=[];
                        $map[$provider]=$site;update_user_meta((int)$owner,'wpai_cloud_provider_sites',$map);
                        $output=['provider'=>$provider,'site_id'=>$site,'endpoint'=>$providers[$provider]['endpoint'],'selected'=>true];
                    }
                    elseif ($action==='issue_provider') {
                        $provider=self::submitted('provider');$site=self::submitted('site_id');$providers=self::providers();
                        if(!isset($providers[$provider]))throw new Fault('invalid_provider');
                        $owned=array_column($core->sites($owner),'id');if(!in_array($site,$owned,true))throw new Fault('invalid_site_selection',403);
                        $value=$core->mint($owner,[$site],['mcp:read','mcp:write'],time());
                        $secret=$value['access_token'];unset($value['access_token']);
                        $output=$value+['provider'=>$provider,'site_id'=>$site,'endpoint'=>$providers[$provider]['endpoint'],'transport'=>$provider==='qwen'?'sse':'streamable_http'];
                    }
                    elseif ($action==='issue') {
                        $value=$core->mint($owner,[self::submitted('site_id')],['mcp:read','mcp:write'],time());
                        $secret=$value['access_token']; unset($value['access_token']); $output=$value;
                    } elseif ($action==='revoke') {
                        $core->revokeToken($owner,self::submitted('token_hash'),time()); $output=['revoked'=>true];
                    } elseif ($action==='test') $output=self::smoke(self::submitted('gateway_token'),self::submitted('site_id'));
                    else throw new Fault('unknown_form_action');
                }
            } catch (Fault $e) { $output=['error'=>$e->reason]; }
            catch (\Throwable $e) { $output=['error'=>'gateway_service_unavailable']; }
        }
        echo '<hr><h2>Gateway — тестовый режим</h2><p>Сервер '.(WordPress::configured()?'настроен':'выключен или требует настройки').'. MCP: <code>'.esc_html(Settings::endpoint()).'</code></p>';
        echo '<p>Доступ к бете — администраторам и допущенным пользователям. OAuth с PKCE и выбором сайтов добавлен; платежи и коммерческие разрешения клиента ещё не включены. Тарифы читаются из Cloud Core; журнал gateway хранится отдельно.</p>';
        if ($output!==null) echo '<pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre>';
        if ($secret!==null) {
            echo '<p><strong>Токен gateway показывается один раз. Срок — 1 час.</strong></p><textarea readonly autocomplete="off" rows="2" cols="90">'.esc_textarea($secret).'</textarea>';
            echo '<p>Для отзыва сохраните SHA-256 токена: <code>'.esc_html(hash('sha256',$secret)).'</code></p>';
        }
        self::form('beta_users','Сохранить пользователей беты','<p><label>ID пользователей WordPress через запятую<br><input name="beta_users" value="'.esc_attr(implode(',',get_option('wpai_gateway_beta_users',[]))).'" size="70"></label></p>');
        echo '<p>Кабинет подключения сайтов: <a href="'.esc_url(home_url('/wpai-cloud/connect')).'">Открыть</a></p>';
        if (!WordPress::configured()) {
            self::form('enable','Включить тестовый gateway');
            echo '<p>Ключи создаются автоматически и шифруются ключами AUTH_KEY / AUTH_SALT из wp-config.php. Сохраните резервную копию wp-config.php и базы вместе; изменение этих ключей требует предварительного переноса ключей gateway.</p>';
            return;
        }
        try {
            $core=WordPress::operatorCore(); $owner=WordPress::operatorOwner(); $sites=$core->sites($owner);
            echo '<p>Лимит: <code>'.esc_html(wp_json_encode($core->usage($owner,time()))).'</code></p>';
            $providerSites=get_user_meta((int)$owner,'wpai_cloud_provider_sites',true);if(!is_array($providerSites))$providerSites=[];
            if($sites){
                echo '<h3>Подключения AI → сайт</h3><p>Выбор здесь — удобный профиль подключения. Безопасность всё равно ограничивается site_id внутри токена и каждого вызова.</p>';
                foreach(self::providers() as $provider=>$profile){
                    $selected=$providerSites[$provider]??$sites[0]['id'];
                    $options='';
                    foreach($sites as $site)$options.='<option value="'.esc_attr($site['id']).'"'.selected($selected,$site['id'],false).'>'.esc_html($site['home']).'</option>';
                    $fields='<input type="hidden" name="provider" value="'.esc_attr($provider).'"><p><strong>'.esc_html($profile['label']).'</strong> · <code>'.esc_html($profile['endpoint']).'</code><br><select name="site_id">'.$options.'</select></p>';
                    self::form('provider_site','Сохранить выбранный сайт',$fields);
                    $issueFields='<input type="hidden" name="provider" value="'.esc_attr($provider).'"><input type="hidden" name="site_id" value="'.esc_attr($selected).'">';
                    self::form('issue_provider','Получить токен на 1 час для '.$profile['label'],$issueFields);
                }
            }
            if (!$sites) self::form('connect','Подключить этот сайт','<p><label>Администраторский MCP-токен WP AI Admin для этого сайта<br><input type="password" name="site_token" autocomplete="new-password" size="70" required></label></p>');
            foreach ($sites as $site) {
                $id='<input type="hidden" name="site_id" value="'.esc_attr($site['id']).'">';
                echo '<h3>'.esc_html($site['home']).'</h3><p>site_id: <code>'.esc_html($site['id']).'</code></p>';
                self::form('issue','Получить токен gateway на 1 час',$id);
                self::form('test','Проверить gateway через HTTPS',$id.'<p><label>Токен gateway<br><input type="password" name="gateway_token" autocomplete="new-password" size="70" required></label></p>');
            }
            self::form('revoke','Отозвать токен','<p><label>SHA-256 токена<br><input name="token_hash" size="70" pattern="[a-f0-9]{64}" required></label></p>');
            self::form('disable','Выключить тестовый gateway');
        } catch (Fault $e) { echo '<p>'.esc_html($e->reason).'</p>'; }
        catch (\Throwable $e) { echo '<p>gateway_service_unavailable</p>'; }
    }
    /** Actual HTTPS REST round trip, not a direct in-process Gateway invocation. No quota-consuming calls. */
    public static function smoke(string $token,string $site): array {
        if (!preg_match('/^wpai_cloud_[A-Za-z0-9_-]{43}$/D',$token)) throw new Fault('invalid_gateway_token');
        Security::identifier($site);
        $endpoint=Settings::endpoint();
        if (parse_url($endpoint,PHP_URL_SCHEME)!=='https') throw new Fault('https_required',403);
        $requests=[
            ['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>['protocolVersion'=>'2025-11-25','capabilities'=>(object)[],'clientInfo'=>['name'=>'wpai-admin-smoke','version'=>'0.3.0']]],
            ['jsonrpc'=>'2.0','method'=>'notifications/initialized'],
            ['jsonrpc'=>'2.0','id'=>2,'method'=>'tools/list'],
            ['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'cloud_sites_list','arguments'=>(object)[]]],
            ['jsonrpc'=>'2.0','id'=>4,'method'=>'tools/call','params'=>['name'=>'cloud_site_tools_list','arguments'=>['site_id'=>$site]]],
            ['jsonrpc'=>'2.0','id'=>5,'method'=>'tools/call','params'=>['name'=>'cloud_usage','arguments'=>(object)[]]],
        ];
        $steps=[];
        foreach ($requests as $message) {
            $response=wp_remote_post($endpoint,['timeout'=>30,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>1048576,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json, text/event-stream','MCP-Protocol-Version'=>'2025-11-25'],'body'=>wp_json_encode($message)]);
            if (is_wp_error($response)) throw new Fault('https_loopback_failed',502);
            $status=wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response);
            $notification=!isset($message['id']);
            if ($notification) {
                if ($status!==202 || $body!=='') throw new Fault('notification_transport_failed',502);
            } else {
                $reply=json_decode($body,true);
                if ($status!==200 || !is_array($reply) || ($reply['jsonrpc']??'')!=='2.0' || ($reply['id']??null)!==$message['id'] || !isset($reply['result']) || isset($reply['error']) || !empty($reply['result']['isError'])) throw new Fault('gateway_smoke_step_failed',502);
                if ($message['method']==='initialize' && ($reply['result']['protocolVersion']??'')!=='2025-11-25') throw new Fault('protocol_negotiation_failed',502);
                if ($message['method']==='tools/list' && array_column($reply['result']['tools']??[],'name')!==array_column(Gateway::tools(),'name')) throw new Fault('gateway_catalog_failed',502);
                if ($message['method']==='tools/call') {
                    $data=json_decode($reply['result']['content'][0]['text']??'',true);
                    if (!is_array($data)) throw new Fault('gateway_tool_result_failed',502);
                    $name=$message['params']['name'];
                    if ($name==='cloud_sites_list' && !in_array($site,array_column($data,'id'),true)) throw new Fault('gateway_site_filter_failed',502);
                    if ($name==='cloud_site_tools_list' && !in_array('system_manifest',array_column($data,'name'),true)) throw new Fault('upstream_catalog_failed',502);
                    if ($name==='cloud_usage' && (!isset($data['limit'],$data['used'],$data['reserved']) || ($data['timezone']??'')!=='UTC')) throw new Fault('gateway_usage_failed',502);
                }
            }
            $steps[]=['step'=>$message['params']['name']??$message['method'],'http'=>$status,'passed'=>true];
        }
        return ['passed'=>true,'endpoint'=>$endpoint,'steps'=>$steps,'billable_actions'=>0,'writes_tested'=>false];
    }
}
