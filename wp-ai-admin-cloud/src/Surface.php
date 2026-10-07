<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Surface {
    public static function resourceMetadata(): array {
        return ['resource'=>Settings::endpoint(),'resource_name'=>'WP AI Admin','authorization_servers'=>[home_url('/wpai-cloud')],'scopes_supported'=>['mcp:read','mcp:write','offline_access'],'bearer_methods_supported'=>['header']];
    }
    public static function authorizationMetadata(): array {
        return ['issuer'=>home_url('/wpai-cloud'),'authorization_endpoint'=>home_url('/wpai-cloud/oauth/authorize'),'token_endpoint'=>rest_url('wpai-cloud/v1/oauth/token'),'registration_endpoint'=>rest_url('wpai-cloud/v1/oauth/register'),'revocation_endpoint'=>rest_url('wpai-cloud/v1/oauth/revoke'),'response_types_supported'=>['code'],'grant_types_supported'=>['authorization_code','refresh_token'],'code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none'],'scopes_supported'=>['mcp:read','mcp:write','offline_access'],'client_id_metadata_document_supported'=>false];
    }
    public static function metadataUrl(): string { return substr(Settings::endpoint(),0,-4).'/.well-known/oauth-protected-resource/mcp'; }
    public static function challenge(): string { return 'Bearer resource_metadata="'.self::metadataUrl().'", scope="mcp:read"'; }
    public static function routeKind(string $path): ?string {
        return match($path) {
            '/mcp'=>'mcp',
            '/sse'=>'sse',
            '/sse/messages'=>'sse_messages',
            '/.well-known/oauth-protected-resource/mcp'=>'resource',
            '/.well-known/oauth-authorization-server/wpai-cloud','/wpai-cloud/.well-known/oauth-authorization-server'=>'authorization',
            '/wpai-cloud/oauth/authorize'=>'consent',
            '/wpai-cloud/connect'=>'portal',
            '/wpai-cloud/pair/callback'=>'pair_callback',
            default=>null
        };
    }
    public static function serve(): void {
        $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);$kind=self::routeKind(is_string($path)?$path:'');
        if(!$kind)return;
        // Preserve the local WP AI Admin OAuth discovery on the main domain.
        if(!is_ssl()){status_header(403);exit;}
        nocache_headers();
        if($kind==='mcp') { rest_get_server()->serve_request('/wpai-cloud/v1/mcp');exit; }
        if($kind==='sse') { Sse::open();exit; }
        if($kind==='sse_messages') { Sse::messages();exit; }
        if(in_array($kind,['resource','authorization'],true)) {
            if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){status_header(405);exit;}
            status_header(200);header('Content-Type: application/json; charset=utf-8');header('X-Content-Type-Options: nosniff');
            echo wp_json_encode($kind==='resource'?self::resourceMetadata():self::authorizationMetadata());exit;
        }
        self::html($kind);exit;
    }
    public static function loginRedirect(string $kind): ?string {
        if(!in_array($kind,['portal','consent','pair_callback'],true))throw new Fault('invalid_login_surface');
        if(is_user_logged_in())return null;
        $return=home_url(match($kind){'portal'=>'/wpai-cloud/connect','pair_callback'=>'/wpai-cloud/pair/callback',default=>'/wpai-cloud/oauth/authorize'});
        if($kind==='consent'||$kind==='pair_callback') {
            $query=[];
            $names=$kind==='pair_callback'?['state','code','error']:['client_id','redirect_uri','response_type','scope','state','code_challenge','code_challenge_method','resource'];
            foreach($names as $name) {
                $value=$_GET[$name]??null;
                if(is_string($value)&&strlen($value)<=2048)$query[$name]=wp_unslash($value);
            }
            if($query)$return=add_query_arg($query,$return);
        }
        return wp_login_url($return,false);
    }
    private static function html(string $kind): void {
        // Frontend URLs receive WordPress's logged-in cookie, not the /wp-admin auth cookie.
        // WordPress validates the logged-in session before template_redirect runs.
        $login=self::loginRedirect($kind);
        if($login!==null) { wp_safe_redirect($login,302,'WP AI Admin');exit; }
        $owner=(string)get_current_user_id();
        ob_start();
        status_header(200);header('Content-Type: text/html; charset=utf-8');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>WP AI Admin — подключение</title><style>body{font:16px system-ui;max-width:700px;margin:50px auto;padding:20px;background:#f5f7fc;color:#14234a}main{background:#fff;padding:32px;border-radius:20px}input,button{font:inherit;padding:10px;margin:8px 0}button{background:#234bd9;color:white;border:0;border-radius:8px}label{display:block}code{overflow-wrap:anywhere}</style><main><h1>WP AI Admin</h1>';
        try {
            if(!Settings::eligible($owner))throw new Fault('beta_access_not_granted',403);
            Settings::account($owner);$core=WordPress::operatorCore();$core->ensureAccount($owner);
            if($kind==='portal')self::portal($owner,$core);elseif($kind==='pair_callback')self::paired($owner);else self::consent($owner,$core);
        } catch(Fault $e) {
            $message=match($e->reason) {
                'cloud_not_configured'=>'Gateway ещё не включён. Администратору нужно включить его в разделе WP AI Admin Cloud.',
                'beta_access_not_granted'=>'Доступ к тестированию для этой учётной записи пока не открыт.',
                'pairing_client_update_required'=>'Обновите WP AI Admin на подключаемом сайте до версии 2.1.11 или новее.',
                'pairing_central_host_required'=>'Начните подключение в кабинете на wpaiadmin.ru.',
                'pairing_request_expired','pairing_request_already_used','invalid_pairing_state','invalid_pairing_code'=>'Ссылка подключения истекла или уже использована. Начните подключение заново.',
                'pairing_account_mismatch'=>'Войдите в тот кабинет WP AI Admin, в котором начали подключение сайта.',
                'pairing_exchange_failed','invalid_pairing_response'=>'Не удалось завершить подключение. Начните заново и подтвердите доступ на своём сайте.',
                'site_dns_unavailable'=>'Сервер не смог определить адрес сайта. Проверьте DNS домена и повторите попытку.',
                'private_site_address_blocked'=>'Адрес сайта не является публичным. Нужен доступный в интернете HTTPS-сайт.',
                'public_https_site_required','invalid_site_host','invalid_site_path'=>'Введите полный адрес WordPress-сайта вида https://example.ru без пути входа и параметров.',
                'pilot_site_count_limit'=>'Достигнут тестовый лимит регистраций сайтов для этого аккаунта.',
                'site_already_registered'=>'Этот WordPress-сайт уже подключён к другому аккаунту WP AI Admin. Для защиты от перехвата сайта повторное подключение другим аккаунтом запрещено. Используйте другой тестовый сайт либо сначала отключите сайт в аккаунте-владельце.',
                default=>$e->reason
            };
            echo '<p>'.esc_html($message).'</p><p>Код ошибки: <code>'.esc_html($e->reason).'</code></p>';
        }
        catch(\Throwable $e) { echo '<p>Сервис подключения временно недоступен.</p>'; }
        echo '</main></html>';
        ob_end_flush();
    }
    /** Commit a same-origin document before navigation: form-action 'self' blocks POST redirects in Chromium. */
    private static function navigate(string $url): void {
        // Only call with Pairing::start's verified site URL or the registered OAuth redirect.
        // Meta refresh starts a new navigation, so the strict form-action policy remains intact.
        echo '<meta http-equiv="refresh" content="'.esc_attr('0;url='.$url).'"><p>Продолжаем подключение…</p><p><a href="'.esc_url($url).'" rel="noreferrer">Продолжить подключение</a></p>';
    }
    private static function portal(string $owner,Core $core): void {
        if(($_GET['connected']??'')==='1')echo '<p>Сайт подключён. Теперь его можно выбрать при подключении ИИ.</p>';
        if(($_GET['cancelled']??'')==='1')echo '<p>Подключение отменено.</p>';
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            check_admin_referer('wpai_beta_connect','wpai_nonce');
            $mode=$_POST['connect_mode']??'';
            if($mode==='check') {
                $home=$_POST['home']??null;if(!is_string($home)||strlen($home)>512)throw new Fault('invalid_site_input');
                $check=WordPress::pairing()->checkSite($owner,wp_unslash($home),time());
                echo '<p>Связь с сайтом подтверждена: <strong>'.esc_html($check['site_url']).'</strong>. Версия плагина: '.esc_html($check['plugin_version']).'. Теперь нажмите «Подключить автоматически» и подтвердите доступ на сайте.</p>';
            }elseif($mode==='automatic') {
                $home=$_POST['home']??null;if(!is_string($home)||strlen($home)>512)throw new Fault('invalid_site_input');
                $url=WordPress::pairing()->start($owner,wp_unslash($home),(string)wp_get_current_user()->display_name,time());
                self::navigate($url);return;
            }else{
                $home=$_POST['home']??null;$token=$_POST['site_token']??null;
                if(!is_string($home)||!is_string($token)||strlen($home)>512||strlen($token)>512)throw new Fault('invalid_site_input');
                $core->connect($owner,wp_unslash($home),wp_unslash($token),time());echo '<p>Сайт подключён.</p>';
            }
        }
        $sites=$core->sites($owner);$usage=$core->usage($owner,time());
        echo '<h2>Мои сайты</h2><p>Один аккаунт и один тариф для всех подключённых сайтов. Действия списываются из общего лимита аккаунта.</p><p>Тариф: <strong>'.esc_html(strtoupper($usage['plan'])).'</strong> · Лимит: '.(int)$usage['limit'].' · Использовано: '.(int)$usage['used'].' · В обработке: '.(int)$usage['reserved'].' · Осталось: '.(int)$usage['remaining'].'</p><p>Подключено сайтов: <strong>'.count($sites).'</strong>. В тестовом режиме доступно до '.Core::MAX_SITES.' регистраций на аккаунт; отдельная подписка для каждого сайта не требуется.</p>';
        if($sites){
            echo '<table><thead><tr><th>Сайт</th><th>ID подключения</th></tr></thead><tbody>';
            foreach($sites as $s)echo '<tr><td>'.esc_html($s['home']).'</td><td><code>'.esc_html($s['id']).'</code></td></tr>';
            echo '</tbody></table><p>После добавления нового сайта повторите авторизацию WP AI Admin в ChatGPT и отметьте все нужные сайты.</p>';
        }
        echo '<h2>Добавить сайт</h2><p>Укажите адрес, войдите на этом сайте как администратор и подтвердите подключение. Сайт появится в этом кабинете автоматически.</p>';
        echo '<form method="post">';wp_nonce_field('wpai_beta_connect','wpai_nonce');
        echo '<label>Адрес WordPress-сайта <input type="url" name="home" placeholder="https://example.ru" required></label><button type="submit" name="connect_mode" value="automatic">Подключить автоматически</button> <button type="submit" name="connect_mode" value="check">Проверить связь</button></form>';
        echo '<details><summary>Подключить существующим MCP-токеном</summary><form method="post">';wp_nonce_field('wpai_beta_connect','wpai_nonce');
        echo '<label>Адрес WordPress-сайта <input type="url" name="home" placeholder="https://example.ru" required></label><label>MCP-токен администратора <input type="password" name="site_token" autocomplete="new-password" required></label><button type="submit">Подключить сайт</button></form></details>';
    }
    private static function paired(string $owner): void {
        $state=$_GET['state']??'';$code=$_GET['code']??'';$error=$_GET['error']??'';
        if(!is_string($state)||!is_string($code)||!is_string($error))throw new Fault('invalid_pairing_state');
        if($error!==''){
            if($error!=='access_denied')throw new Fault('invalid_pairing_response');
            WordPress::pairing()->cancel($owner,wp_unslash($state),time());$return='/wpai-cloud/connect?cancelled=1';
        }else{WordPress::pairing()->complete($owner,wp_unslash($state),wp_unslash($code),time());$return='/wpai-cloud/connect?connected=1';}
        ob_end_clean();wp_safe_redirect(home_url($return),303,'WP AI Admin');exit;
    }
    private static function consent(string $owner,Core $core): void {
        $input=wp_unslash($_GET);$oauth=WordPress::oauth();$auth=$oauth->authorization($input);
        $action='wpai_consent_'.hash('sha256',Security::canonical($auth));
        if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            check_admin_referer($action,'wpai_nonce');
            if(($_POST['decision']??'')==='deny')$parameters=['error'=>'access_denied','state'=>$auth['state']];
            else {
                if(($_POST['decision']??'')!=='allow')throw new Fault('consent_required');
                $sites=wp_unslash($_POST['sites']??[]);if(!is_array($sites))throw new Fault('invalid_site_consent');
                $code=$oauth->approve($owner,$auth,$sites,time());$parameters=['code'=>$code,'state'=>$auth['state']];
            }
            // Registered callback was validated exactly; no redirect to arbitrary request input.
            $redirect=$auth['redirect_uri'].(str_contains($auth['redirect_uri'],'?')?'&':'?').http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
            self::navigate($redirect);return;
        }
        echo '<h2>Разрешить подключение?</h2><p>Клиент: <strong>'.esc_html($auth['client_name']).'</strong> (название указано клиентом).</p><p>Возврат: <code>'.esc_html($auth['redirect_uri']).'</code></p><p>Права: <code>'.esc_html(implode(' ',$auth['scopes'])).'</code>. Записи проходят sandbox и подтверждение в WordPress.</p>';
        $sites=$core->sites($owner);
        if(!$sites){echo '<p>Сначала <a href="'.esc_url(home_url('/wpai-cloud/connect')).'">подключите свой сайт</a>, затем повторите подключение ИИ.</p>';return;}
        echo '<form method="post">';wp_nonce_field($action,'wpai_nonce');
        foreach($sites as $s)echo '<label><input type="checkbox" name="sites[]" value="'.esc_attr($s['id']).'"> '.esc_html($s['home']).'</label>';
        echo '<button name="decision" value="allow">Разрешить выбранные сайты</button> <button name="decision" value="deny">Отказать</button></form>';
    }
}
