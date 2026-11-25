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
    /**
     * Only render plan/scheme pairs the catalogue actually offers. This keeps
     * the form from submitting a combination that would fail with
     * unknown_plan after the operator has filled it in.
     */
    public static function licenseOffers(array $policy): array {
        $plans=[];
        foreach([QuotaPolicy::CURRENT,QuotaPolicy::TARGET,QuotaPolicy::LEGACY] as $scheme){
            $candidates=isset($policy['schemes'][$scheme]['plans'])&&is_array($policy['schemes'][$scheme]['plans'])
                ? array_keys($policy['schemes'][$scheme]['plans'])
                : ($scheme===QuotaPolicy::LEGACY?array_keys((array)($policy['legacy_plans']??[])):array_keys((array)($policy['plans']??[])));
            foreach($candidates as $plan)if(QuotaPolicy::offers($policy,$scheme,(string)$plan))$plans[(string)$plan]=true;
        }
        $offers=[];
        foreach([QuotaPolicy::CURRENT,QuotaPolicy::TARGET,QuotaPolicy::LEGACY] as $scheme){
            foreach(array_keys($plans) as $plan){
                if(!QuotaPolicy::offers($policy,$scheme,$plan))continue;
                $offers[]=[
                    'value'=>$scheme.'|'.$plan,
                    'scheme'=>$scheme,
                    'plan'=>$plan,
                    'label'=>QuotaPolicy::planLabel($policy,$plan,$scheme).' — '.$scheme,
                ];
            }
        }
        return $offers;
    }
    /** Render diagnostics in a dedicated, human-readable panel. */
    private static function diagnosticPanel(array $report): void {
        $credential=is_array($report['credential']??null)?$report['credential']:[];
        $upstream=is_array($report['upstream']??null)?$report['upstream']:[];
        $passed=($credential['vault_binding_valid']??false)===true && ($upstream['passed']??false)===true;
        echo '<section class="notice '.($passed?'notice-success':'notice-error').'" style="margin:18px 0;padding:12px 16px;border-left-width:4px">';
        echo '<h2 style="margin:4px 0 12px">Результат диагностики подключения и MCP</h2>';
        echo '<p><strong>'.($passed?'Проверка пройдена':'Обнаружена проблема').'</strong> · site_id: <code>'.esc_html((string)($report['site_id']??'')).'</code></p>';
        echo '<h3>1. Учётные данные и Vault</h3><table class="widefat striped" style="max-width:900px"><tbody>';
        $credentialLabels=['status'=>'Статус регистрации','sealed_payload_format_valid'=>'Формат зашифрованных данных','vault_key_loaded'=>'Ключ Vault загружен','vault_key_source'=>'Источник ключа Vault','decryption_attempted'=>'Расшифровка выполнялась','credential_readable'=>'Учётные данные читаются','vault_binding_valid'=>'Привязка Vault','binding_context'=>'Контекст привязки (AAD)','binding_failure_class'=>'Класс ошибки расшифровки','credential_format'=>'Формат учётных данных','site_token_format_valid'=>'Формат токена сайта','status_key_present'=>'Ключ статуса','failure'=>'Ошибка'];
        foreach($credentialLabels as $key=>$label){
            if(!array_key_exists($key,$credential))continue;
            $value=$credential[$key];
            $shown=$value===null?'Не удалось определить':(is_bool($value)?($value?'Да':'Нет'):(is_scalar($value)?(string)$value:wp_json_encode($value)));
            echo '<tr><th style="width:280px">'.esc_html($label).'</th><td><code>'.esc_html($shown).'</code></td></tr>';
        }
        echo '</tbody></table><h3>2. Связь с Native MCP и каталог инструментов</h3>';
        echo '<p>Запрос к сайту: <strong>'.(!empty($upstream['attempted'])?'выполнялся':'не выполнялся').'</strong> · Каталог получен: <strong>'.(!empty($upstream['catalog_received'])?'да':'нет').'</strong> · Инструментов: <strong>'.(int)($upstream['tool_count']??0).'</strong></p>';
        if(!empty($upstream['blocked_by']))echo '<p>Проверка остановлена: <code>'.esc_html((string)$upstream['blocked_by']).'</code></p>';
        if(!empty($upstream['failure']))echo '<p>Ошибка upstream: <code>'.esc_html((string)$upstream['failure']).'</code></p>';
        if(isset($upstream['required_tools'])&&is_array($upstream['required_tools'])){
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Обязательный инструмент</th><th>Наличие</th></tr></thead><tbody>';
            foreach($upstream['required_tools'] as $name=>$available)echo '<tr><td><code>'.esc_html((string)$name).'</code></td><td>'.(!empty($available)?'<span style="color:#16803c">Есть</span>':'<span style="color:#b32d2e">Нет</span>').'</td></tr>';
            echo '</tbody></table>';
        }
        if(!empty($upstream['missing_tools']))echo '<p>Отсутствуют: <code>'.esc_html(implode(', ',array_map('strval',$upstream['missing_tools']))).'</code></p>';
        echo '<h3>3. Безопасность проверки</h3><p>Billable actions: <strong>'.(int)($report['billable_actions']??0).'</strong> · Записывающие операции проверялись: <strong>'.(!empty($report['writes_tested'])?'да':'нет').'</strong></p>';
        echo '<details><summary>Показать полный JSON диагностики</summary><pre style="white-space:pre-wrap;max-height:420px;overflow:auto">'.esc_html(wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre></details>';
        echo '</section>';
    }
    public static function page(): void {
        if (!current_user_can('manage_options')) return;
        nocache_headers();
        $output=null; $secret=null; $secretKind='token';
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
                    } elseif ($action==='license_issue') {
                        $licenseOwner=self::submitted('license_owner');
                        if($licenseOwner!==''&&!preg_match('/^[0-9]{1,20}$/D',$licenseOwner)) throw new Fault('invalid_license_owner');
                        $offer=self::submitted('license_offer');
                        if($offer!==''){
                            $parts=explode('|',$offer,2);
                            if(count($parts)!==2||$parts[0]===''||$parts[1]==='') throw new Fault('invalid_license_offer');
                            $scheme=$parts[0]; $plan=$parts[1];
                        } else {
                            // Backward compatibility for direct POSTs that still
                            // submit the former separate plan and scheme fields.
                            $scheme=self::submitted('license_scheme'); $plan=self::submitted('license_plan');
                        }
                        $options=['activation_limit'=>max(1,min(50,(int)self::submitted('activation_limit'))),'comment'=>self::submitted('license_comment')];
                        $days=(int)self::submitted('license_days');
                        if($days>0) $options['duration_days']=$days;
                        $value=$core->issueLicense((string)get_current_user_id(),$licenseOwner===''?null:$licenseOwner,$plan,time(),$scheme===''?null:$scheme,$options);
                        $secret=$value['license_key']; unset($value['license_key']); $secretKind='license'; $output=$value;
                    } elseif ($action==='quota_reset') {
                        if(self::submitted('quota_confirm')!=='RESET DAILY') throw new Fault('quota_reset_confirmation_required',409);
                        $target=self::submitted('quota_owner');
                        $output=$core->resetDailyQuota((string)get_current_user_id(),$target,time());
                    } elseif ($action==='license_activate') {
                        $output=$core->activateLicense($owner,self::submitted('license_key'),self::submitted('license_site'),time());
                    } elseif ($action==='license_reissue') {
                        if(self::submitted('license_confirm')!=='REISSUE') throw new Fault('license_reissue_confirmation_required',409);
                        $value=$core->reissueLicense((string)get_current_user_id(),self::submitted('license_id'),time());
                        $secret=$value['license_key']; unset($value['license_key']); $secretKind='license'; $output=$value;
                    } elseif ($action==='license_revoke') {
                        if(self::submitted('license_confirm')!=='REVOKE') throw new Fault('license_revoke_confirmation_required',409);
                        $output=$core->revokeLicense((string)get_current_user_id(),self::submitted('license_id'),time());
                    } elseif ($action==='license_suspend') {
                        if(self::submitted('license_confirm')!=='SUSPEND') throw new Fault('license_suspend_confirmation_required',409);
                        $output=$core->suspendLicense((string)get_current_user_id(),self::submitted('license_id'),time());
                    } elseif ($action==='license_reactivate') {
                        if(self::submitted('license_confirm')!=='REACTIVATE') throw new Fault('license_reactivate_confirmation_required',409);
                        $output=$core->reactivateLicense((string)get_current_user_id(),self::submitted('license_id'),time());
                    } elseif ($action==='diagnose_site') {
                        $site=self::submitted('site_id');
                        $owned=array_column($core->sites($owner),'id');
                        if(!in_array($site,$owned,true)) throw new Fault('invalid_site_selection',403);
                        $output=$core->diagnoseSiteConnection($owner,$site);
                        $output['credential']['vault_key_source']=Settings::keySource();
                    } elseif ($action==='test') $output=self::smoke(self::submitted('gateway_token'),self::submitted('site_id'));
                    else throw new Fault('unknown_form_action');
                }
            } catch (Fault $e) { $output=['error'=>$e->reason]; }
            catch (\Throwable $e) { $output=['error'=>'gateway_service_unavailable']; }
        }
        echo '<hr><h2>Gateway — тестовый режим</h2><p>Сервер '.(WordPress::configured()?'настроен':'выключен или требует настройки').'. MCP: <code>'.esc_html(Settings::endpoint()).'</code></p>';
        echo '<p>Доступ к бете — администраторам и допущенным пользователям. OAuth с PKCE и выбором сайтов добавлен; платежи и автоматические списания не включены. Дневные квоты утверждённого каталога: Free — 50, Daily — 500, Pro — 2 000, PRO+ — 5 000, PRO++ — 10 000 действий в сутки UTC. Для платных планов Pro, PRO+ и PRO++ квота активируется только после отдельного утверждения владельцем. До утверждения все billable-вызовы отклоняются; чтение, проверки, получение квитанций и откат остаются доступны. Исторические аккаунты legacy_monthly_v1 сохраняют прежние месячные условия до отдельно авторизованного перевода. Cloud Core — единственный источник плана, лицензии и квоты. Ручной сброс дневной квоты доступен только администратору на стороне сервера; публичного REST/MCP-инструмента для сброса нет.</p>';
        if ($output!==null) {
            if(isset($output['credential'],$output['upstream'])) self::diagnosticPanel($output);
            else echo '<pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'</pre>';
        }
        if ($secret!==null) {
            if ($secretKind==='license') {
                echo '<p><strong>Ключ лицензии показывается один раз. Сохраните его в защищённом месте.</strong></p><textarea readonly autocomplete="off" rows="2" cols="90">'.esc_textarea($secret).'</textarea>';
                echo '<p>В базе хранится только SHA-256 ключа. Повторный показ невозможен; при утере выпустите новую лицензию (reissue), и прежняя будет отозвана.</p>';
            } else {
                echo '<p><strong>Токен gateway показывается один раз. Срок — 1 час.</strong></p><textarea readonly autocomplete="off" rows="2" cols="90">'.esc_textarea($secret).'</textarea>';
                echo '<p>Для отзыва сохраните SHA-256 токена: <code>'.esc_html(hash('sha256',$secret)).'</code></p>';
            }
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
            echo '<h3>Ручной сброс дневной квоты</h3><p>Только администратор Cloud Core. Сбрасывает использованные действия выбранного аккаунта за текущие сутки UTC. Незавершённые резервы сохраняются; тариф, лицензия и месячные исторические балансы не меняются.</p>';
            self::form('quota_reset','Сбросить дневную квоту пользователя','<p><label>ID аккаунта пользователя<br><input name="quota_owner" maxlength="64" required autocomplete="off" size="32"></label></p><p><label>Подтверждение (введите RESET DAILY)<br><input name="quota_confirm" required autocomplete="off" pattern="RESET DAILY" size="24"></label></p>');
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
                self::form('diagnose_site','Диагностика подключения и MCP',$id.'<p>Read-only: проверка Vault, связи с сайтом и семи обязательных инструментов. Не расходует квоту и не изменяет сайт.</p>');
            }
            echo '<h3>Лицензии</h3>';
            echo '<p>Cloud Core — единственный источник плана и лицензии. Ключ хранится только в виде SHA-256, показывается один раз и не может быть восстановлен. Автоматическая привязанная лицензия создаётся при регистрации аккаунта и при назначении плана; её ключ намеренно не сохраняется. Выпуск, reissue и отзыв выполняются только администратором.</p>';
            $licenseRows=$core->allLicenses(50);
            if($licenseRows){
                echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>ID</th><th>Аккаунт</th><th>Префикс</th><th>План</th><th>Схема</th><th>Статус</th><th>Активации</th><th>Действует до</th></tr></thead><tbody>';
                foreach($licenseRows as $row){
                    echo '<tr><td><code>'.esc_html($row['id']).'</code></td><td>'.esc_html($row['owner']===null?'— (не привязана)':$row['owner']).'</td><td><code>'.esc_html($row['key_prefix']).'…</code></td><td>'.esc_html($row['plan']).'</td><td>'.esc_html($row['quota_scheme']).'</td><td>'.esc_html($row['status']).'</td><td>'.(int)$row['activations'].'/'.(int)$row['activation_limit'].'</td><td>'.esc_html($row['expires_at']===null?'—':gmdate('Y-m-d H:i',$row['expires_at']).' UTC').'</td></tr>';
                }
                echo '</tbody></table><p>Показаны последние 50 лицензий, включая несвязанные. ID нужен для reissue, приостановки, возобновления и отзыва.</p>';
            } else echo '<p>Лицензий пока нет.</p>';
            $planOptions='';
            $planPolicy=Settings::catalogPolicy();
            foreach(self::licenseOffers($planPolicy) as $offer) $planOptions.='<option value="'.esc_attr($offer['value']).'">'.esc_html($offer['label']).'</option>';
            $fields='<p><label>Аккаунт WordPress (пусто = несвязанная лицензия)<br><input name="license_owner" inputmode="numeric" pattern="[0-9]*" size="12"></label></p>';
            $fields.='<p><label>Тариф и схема<br><select name="license_offer">'.$planOptions.'</select></label></p>';
            $fields.='<p><label>Лимит активаций<br><input type="number" name="activation_limit" min="1" max="50" value="1"></label> <label>Срок, дней (0 = без срока)<br><input type="number" name="license_days" min="0" max="3650" value="0"></label></p>';
            $fields.='<p><label>Комментарий<br><input name="license_comment" maxlength="255" size="60"></label></p>';
            self::form('license_issue','Выпустить лицензию вручную',$fields);
            if($sites){
                $siteOptions='';
                foreach($sites as $site)$siteOptions.='<option value="'.esc_attr($site['id']).'">'.esc_html($site['home']).'</option>';
                self::form('license_activate','Активировать ключ на сайте','<p><label>Ключ лицензии<br><input type="password" name="license_key" autocomplete="new-password" size="70" required></label></p><p><label>Сайт<br><select name="license_site">'.$siteOptions.'</select></label></p>');
            }
            self::form('license_reissue','Перевыпустить ключ (старый отзывается)','<p><label>ID лицензии<br><input name="license_id" pattern="lic_[a-f0-9]{24}" size="40" required></label> <label>Подтверждение<br><input name="license_confirm" value="REISSUE" size="10" required></label></p>');
            self::form('license_revoke','Отозвать лицензию','<p><label>ID лицензии<br><input name="license_id" pattern="lic_[a-f0-9]{24}" size="40" required></label> <label>Подтверждение<br><input name="license_confirm" value="REVOKE" size="10" required></label></p>');
            self::form('license_suspend','Приостановить лицензию','<p><label>ID лицензии<br><input name="license_id" pattern="lic_[a-f0-9]{24}" size="40" required></label> <label>Подтверждение<br><input name="license_confirm" value="SUSPEND" size="10" required></label></p>');
            self::form('license_reactivate','Возобновить лицензию','<p><label>ID лицензии<br><input name="license_id" pattern="lic_[a-f0-9]{24}" size="40" required></label> <label>Подтверждение<br><input name="license_confirm" value="REACTIVATE" size="10" required></label></p>');
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
                    if ($name==='cloud_usage') {
                        $pending=($data['quota_status']??QuotaPolicy::QUOTA_APPROVED)!==QuotaPolicy::QUOTA_APPROVED;
                        // A pending paid quota is a valid state with null limit
                        // and remaining; it must not masquerade as a balance.
                        $valid=$pending
                            ? (($data['limit']??null)===null && ($data['remaining']??null)===null && isset($data['used'],$data['reserved']))
                            : isset($data['limit'],$data['used'],$data['reserved'],$data['remaining']);
                        if (!$valid || ($data['timezone']??'')!=='UTC') throw new Fault('gateway_usage_failed',502);
                    }
                }
            }
            $steps[]=['step'=>$message['params']['name']??$message['method'],'http'=>$status,'passed'=>true];
        }
        return ['passed'=>true,'endpoint'=>$endpoint,'steps'=>$steps,'billable_actions'=>0,'writes_tested'=>false];
    }
}
