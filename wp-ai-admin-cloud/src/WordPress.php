<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class WordPress {
    private static ?Core $core=null;
    private static ?Store $store=null;
    private static function database(): Store {
        if (self::$store) return self::$store;
        if (!extension_loaded('pdo_mysql')) throw new Fault('pdo_mysql_required',503);
        global $wpdb;
        $host=DB_HOST; $port=3306; $socket=null;
        if (preg_match('/^([^:]+):(\d+)$/D',$host,$m)) { $host=$m[1]; $port=(int)$m[2]; }
        elseif (preg_match('#^[^:]+:(/.*)$#D',$host,$m)) { $socket=$m[1]; }
        elseif (str_contains($host,':')) throw new Fault('configure_explicit_database_dsn',503);
        $dsn=defined('WP_AI_CLOUD_DSN')?WP_AI_CLOUD_DSN:('mysql:'.($socket?'unix_socket='.$socket:'host='.$host.';port='.$port).';dbname='.DB_NAME.';charset=utf8mb4');
        return self::$store=new Store(new \PDO($dsn,DB_USER,DB_PASSWORD,[\PDO::ATTR_EMULATE_PREPARES=>false]),$wpdb->prefix.'wpai_gateway_');
    }
    private static function ready(): bool {
        return Settings::enabled() && !class_exists('WPAICloud\WordPress',false) && extension_loaded('curl') && extension_loaded('sodium') && extension_loaded('pdo_mysql');
    }
    private static function core(): Core {
        if (!self::ready()) throw new Fault('cloud_not_configured',503);
        if (self::$core) return self::$core;
        self::migrate();
        $keys=Settings::keys();
        return self::$core=new Core(self::database(),new Vault($keys['vault']),new HttpsTransport(),Settings::policy(),new Signer($keys['signing']));
    }
    public static function boot(string $file): void {
        add_action('rest_api_init',[self::class,'routes']);
        add_action('template_redirect',[Surface::class,'serve'],-10);
        add_filter('rest_post_dispatch',[self::class,'secureRestHeaders'],10,3);
        add_filter('rest_pre_serve_request',[self::class,'emptyNotification'],10,4);
        add_action('wpai_gateway_pair_cleanup',static function($key){if(is_string($key)&&preg_match('/^wpai_pair_request_[a-f0-9]{64}$/D',$key))delete_option($key);});
        add_action('wpai_cloud_retry_license_email',[self::class,'retryLicenseEmail'],10,1);
        add_action('woocommerce_thankyou',[self::class,'renderLicenseLink'],40,1);
        add_action('init',[self::class,'registerLicenseRewrite']);
        add_filter('query_vars',[self::class,'licenseQueryVars']);
        add_action('template_redirect',[self::class,'serveLicenseReveal'],1);
        WooCommercePayments::boot();
        ReconciliationAdmin::boot();
    }
    private static function migrate(): void {
        $store=self::database();
        if ((string)get_option('wpai_gateway_db_version','')==='4') {
            $store->assertSchema();
        } else {
            $store->migrate();
            update_option('wpai_gateway_db_version','4',false);
        }
        // Separate idempotent flag: the schema version stays 4 so historical
        // upgrade/restore snapshots keep working. Only current-scheme accounts
        // with the retired v2 plan slugs are rewritten.
        if ((string)get_option('wpai_gateway_plan_alias_v1','')!=='1') {
            Core::migratePlanAliases($store,time());
            update_option('wpai_gateway_plan_alias_v1','1',false);
        }
    }
    public static function statusPage(): void { Admin::page(); }
    public static function enable(): void {
        if (defined('WP_AI_CLOUD_ENABLED') && WP_AI_CLOUD_ENABLED!==true) throw new Fault('gateway_disabled_in_wp_config',503);
        if (class_exists('WPAICloud\WordPress',false)) throw new Fault('separate_prototype_must_be_deactivated',503);
        foreach (['sodium','curl','pdo_mysql'] as $extension) if (!extension_loaded($extension)) throw new Fault($extension.'_required',503);
        Settings::prepareKeys(); self::migrate(); Settings::policy();
        update_option('wpai_gateway_enabled',true,false);
        try { self::core(); }
        catch (\Throwable $e) { update_option('wpai_gateway_enabled',false,false); throw $e; }
    }
    public static function oauth(): OAuth { return new OAuth(self::database(),self::core(),Settings::endpoint()); }
    /**
     * Settle a verified, paid Robokassa WooCommerce order into the Cloud licence registry.
     * The customer ID is the only accepted owner identity; guests fail closed.
     */
    public static function settlePaidWooOrder(\WC_Order $order): array {
        if (!self::ready()) throw new Fault('cloud_not_configured',503);
        $owner=(string)(int)$order->get_customer_id();
        if ((int)$owner<=0) throw new Fault('registered_customer_required',409);
        Settings::account($owner);
        self::core()->ensureAccount($owner);
        $store=self::database();
        $result=WooCommercePayments::settle($store,Settings::policy(),$order,new Vault(Settings::keys()['vault']));
        $delivery=$store->row('SELECT email_status,email_attempts FROM '.$store->table('license_deliveries').' WHERE order_id=?',[(string)$order->get_id()]);
        if ($delivery && $delivery['email_status']!=='sent') {
            try {
                $sent=LicenseDelivery::sendEmail($store,new Vault(Settings::keys()['vault']),(string)$order->get_id());
            } catch (\Throwable $e) {
                error_log('WP AI Admin licence email send failed: '.get_class($e));
                $sent=false;
            }
            if (!$sent) self::queueOrExhaustLicenseEmail($order,$store);
        }
        return $result;
    }
    public static function registerLicenseRewrite(): void {
        add_rewrite_rule('^license-key/([0-9]+)/?$', 'index.php?wpai_license_order=$matches[1]', 'top');
    }
    public static function licenseQueryVars(array $vars): array { $vars[]='wpai_license_order'; return $vars; }

    public static function renderLicenseLink($orderId): void {
        if (!function_exists('wc_get_order')) return;
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \WC_Order || !$order->is_paid() || (int)$order->get_customer_id()!==get_current_user_id()) return;
        try {
            $store=self::database();
            $row=$store->row('SELECT email_status FROM '.$store->table('license_deliveries').' WHERE order_id=? AND owner=?',[(string)$order->get_id(),(string)get_current_user_id()]);
            if (!$row) return;
            $url=home_url('/license-key/'.(int)$order->get_id().'/');
            echo '<p class="woocommerce-info">Оплата подтверждена. <a href="'.esc_url($url).'">Открыть страницу получения ключа лицензии</a></p>';
        } catch (\Throwable $e) { error_log('WP AI Admin licence link unavailable: '.get_class($e)); }
    }

    private static function queueOrExhaustLicenseEmail(\WC_Order $order,Store $store): void {
        $row=$store->row('SELECT email_status,email_attempts,email_ciphertext,email_started_at FROM '.$store->table('license_deliveries').' WHERE order_id=? AND owner=?',[(string)$order->get_id(),(string)$order->get_customer_id()]);
        if (!$row || in_array($row['email_status'],['sent','revoked'],true) || $row['email_ciphertext']==='') return;
        if ($row['email_status']==='sending' && (int)$row['email_started_at']>time()-600) return;
        $attempts=(int)$row['email_attempts'];
        if ($attempts>=12) {
            if ((string)$order->get_meta('_wpai_license_email_exhausted',true)!=='yes') {
                $order->add_order_note('WP AI Admin: не удалось отправить письмо с лицензией после 12 попыток. Зашифрованная копия сохранена; администратор может повторить отправку из Cloud API.');
                $order->update_meta_data('_wpai_license_email_exhausted','yes');
                $order->save_meta_data();
            }
            return;
        }
        $args=[(int)$order->get_id()];
        if (!wp_next_scheduled('wpai_cloud_retry_license_email',$args)) {
            wp_schedule_single_event(time()+min(3600,60*max(1,$attempts)),'wpai_cloud_retry_license_email',$args);
        }
    }

    public static function retryLicenseEmail($orderId): void {
        if (!function_exists('wc_get_order')) return;
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \WC_Order || !$order->is_paid()) return;
        try {
            $store=self::database();
            $row=$store->row('SELECT email_status,email_attempts,email_ciphertext FROM '.$store->table('license_deliveries').' WHERE order_id=? AND owner=?',[(string)$order->get_id(),(string)$order->get_customer_id()]);
            if (!$row || in_array($row['email_status'],['sent','revoked'],true) || $row['email_ciphertext']==='') return;
            if ((int)$row['email_attempts']>=12) {
                self::queueOrExhaustLicenseEmail($order,$store);
                return;
            }
            try {
                $sent=LicenseDelivery::sendEmail($store,new Vault(Settings::keys()['vault']),(string)$order->get_id());
            } catch (\Throwable $e) {
                error_log('WP AI Admin licence email retry failed: '.get_class($e));
                $sent=false;
            }
            if (!$sent) self::queueOrExhaustLicenseEmail($order,$store);
        } catch (\Throwable $e) { error_log('WP AI Admin licence email retry failed: '.get_class($e)); }
    }

    public static function serveLicenseReveal(): void {
        $orderId=get_query_var('wpai_license_order');
        if (!$orderId) return;
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        if (!is_user_logged_in() || !is_ssl()) { auth_redirect(); exit; }
        if (!function_exists('wc_get_order')) { status_header(503); exit('Сервис заказов недоступен.'); }
        $order=wc_get_order((int)$orderId);
        if (!$order instanceof \WC_Order || !$order->is_paid() || (int)$order->get_customer_id()!==get_current_user_id()) { status_header(404); exit('Заказ не найден.'); }
        $store=self::database();
        $owner=(string)get_current_user_id();
        $payment=$store->row('SELECT status,owner,license_id FROM '.$store->table('commerce_payments').' WHERE gateway_order_id=?',[(string)$order->get_id()]);
        $delivery=$store->row('SELECT plan,license_id,reveal_consumed FROM '.$store->table('license_deliveries').' WHERE order_id=? AND owner=?',[(string)$order->get_id(),$owner]);
        if (!$payment || $payment['status']!=='settled' || $payment['owner']!==$owner || !$delivery || $payment['license_id']==='' || $payment['license_id']!==$delivery['license_id'] || !str_starts_with((string)$order->get_payment_method(),'robokassa')) { status_header(404); exit('Заказ не найден.'); }
        $key=null; $message='';
        if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['wpai_reveal_nonce'],$_POST['wpai_order_key']) && is_string($_POST['wpai_order_key']) && hash_equals((string)$order->get_order_key(),(string)sanitize_text_field(wp_unslash($_POST['wpai_order_key']))) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wpai_reveal_nonce'])),'wpai_reveal_'.$orderId)) {
            try { $key=LicenseDelivery::reveal($store,new Vault(Settings::keys()['vault']),(string)$orderId,$owner,time()); }
            catch (Fault $e) { $message=$e->reason==='license_reveal_already_consumed'?'Ключ уже был показан. Проверьте письмо с подтверждением покупки.':'Не удалось получить ключ.'; }
        }
        status_header(200);
        echo '<!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ключ лицензии WP AI Admin</title><body><main style="max-width:680px;margin:10vh auto;padding:24px;font:16px sans-serif"><h1>Ваш ключ лицензии</h1><p>Тариф: '.esc_html((string)$delivery['plan']).'</p>';
        if ($key!==null) echo '<p>Сохраните ключ сейчас. Повторно показать его на этой странице нельзя.</p><p><code style="overflow-wrap:anywhere">'.esc_html($key).'</code></p><button type="button" onclick="navigator.clipboard.writeText(document.querySelector(\'code\').textContent)">Копировать ключ</button>';
        elseif ($message!=='') echo '<p>'.esc_html($message).'</p>';
        elseif ((int)$delivery['reveal_consumed']===1) echo '<p>Ключ уже был показан. Проверьте письмо с подтверждением покупки.</p>';
        else echo '<p>Нажмите кнопку, чтобы показать ключ один раз.</p><form method="post">'.wp_nonce_field('wpai_reveal_'.$orderId,'wpai_reveal_nonce',true,false).'<input type="hidden" name="wpai_order_key" value="'.esc_attr((string)$order->get_order_key()).'"><button type="submit">Показать ключ один раз</button></form>';
        echo '<p>Активируйте ключ в WP AI Admin → Тариф и лицензия.</p></main></body></html>';
        exit;
    }

    /**
     * Process only a Robokassa refund whose official GetState result is persisted as finished.
     * The payment owner is derived from the immutable WooCommerce customer ID.
     */
    public static function settleConfirmedWooRefund(\WC_Order $order,string $requestId,float $amount,bool $isFull): array {
        if (!self::ready()) throw new Fault('cloud_not_configured',503);
        $owner=(string)(int)$order->get_customer_id();
        if ((int)$owner<=0) throw new Fault('registered_customer_required',409);
        Settings::account($owner);
        self::core()->ensureAccount($owner);
        return WooCommercePayments::refund(self::database(),Settings::policy(),$order,$requestId,$amount,$isFull);
    }
    /**
     * Operator-approved recovery for a paid Robokassa order missing its Cloud payment row.
     * The caller must already have enforced administrator capability, nonce and typed confirmation.
     */
    public static function repairPaidWooOrder(int $orderId): array {
        if (!self::ready()) throw new Fault('cloud_not_configured',503);
        if (!function_exists('wc_get_order')) throw new Fault('woocommerce_unavailable',503);
        $order=wc_get_order($orderId);
        if (!$order instanceof \WC_Order) throw new Fault('repair_order_not_found',404);
        if (!$order->is_paid()) throw new Fault('repair_payment_not_confirmed',409);
        if (!str_starts_with((string)$order->get_payment_method(),'robokassa')) throw new Fault('repair_gateway_mismatch',409);
        if ((int)$order->get_customer_id()<=0) throw new Fault('repair_registered_customer_required',409);
        if (trim((string)$order->get_transaction_id())==='') throw new Fault('repair_transaction_reference_required',409);
        $owner=(string)(int)$order->get_customer_id();
        Settings::account($owner);
        self::core()->ensureAccount($owner);
        return ReconciliationRepair::repairMissingPaymentLedger(self::database(),Settings::policy(),$order,new Vault(Settings::keys()['vault']));
    }
    /** Read-only operational comparison of WooCommerce orders and Cloud ledgers. */
    public static function reconciliationReport(int $limit=500): array {
        if (!self::ready()) throw new Fault('cloud_not_configured',503);
        self::core();
        return Reconciliation::report(self::database(),Settings::policy(),$limit);
    }
    public static function operatorCore(): Core { return self::core(); }
    public static function pairing(): Pairing { return new Pairing(self::core(),new Vault(Settings::keys()['vault']),new HttpsTransport()); }
    public static function operatorOwner(): string { return self::owner(); }
    public static function authenticateHeader(string $auth): array {
        if (!is_string($auth)||!preg_match('/^Bearer (wpai_cloud_[A-Za-z0-9_-]{43})$/D',$auth,$m)) throw new Fault('bearer_required',401);
        $actor=self::core()->authenticate($m[1],time());
        self::oauth()->binding($actor,time());
        if (!Settings::eligible($actor['owner'])) throw new Fault('account_unavailable',403);
        Settings::account($actor['owner']);
        return $actor;
    }
    public static function configured(): bool {
        try { if (!self::ready()) return false; self::core(); return true; } catch (\Throwable $e) { return false; }
    }
    public static function secureRestHeaders($result,$server,$request) {
        $route=is_object($request)&&method_exists($request,'get_route')?(string)$request->get_route():'';
        if(str_starts_with($route,'/wpai-cloud/v1/') && is_object($result) && method_exists($result,'header')){
            $result->header('Cache-Control','no-store');
            $result->header('X-Content-Type-Options','nosniff');
        }
        return $result;
    }
    private static function response(mixed $value,int $status=200): \WP_REST_Response {
        return new \WP_REST_Response($value,$status,['Cache-Control'=>'no-store','Content-Type'=>'application/json; charset=utf-8','X-Content-Type-Options'=>'nosniff']);
    }
    private static function safe(callable $fn): \WP_REST_Response {
        try { return $fn(); }
        catch (Fault $e) { $r=self::response(['error'=>$e->reason],$e->status); if ($e->status===401) $r->header('WWW-Authenticate',Surface::challenge()); return $r; }
        catch (\Throwable $e) { error_log('WP AI Admin Cloud: '.get_class($e).': '.$e->getMessage()); return self::response(['error'=>'cloud_service_unavailable'],503); }
    }
    public static function provisioningPermission(): bool|\WP_Error {
        // WordPress validates application passwords or cookie+REST nonce. Cloud bearer tokens never authorize provisioning.
        if (!is_user_logged_in() || !Settings::eligible((string)get_current_user_id())) return new \WP_Error('cloud_auth_required','WordPress authentication required',['status'=>401]);
        if (!is_ssl()) return new \WP_Error('cloud_https_required','HTTPS required',['status'=>403]);
        return true;
    }
    private static function requireProvisioning(): void {
        $permission=self::provisioningPermission();
        if ($permission===true) return;
        if (is_wp_error($permission)) {
            $data=$permission->get_error_data();
            $status=is_array($data)?(int)($data['status']??403):403;
            throw new Fault((string)$permission->get_error_code(),$status);
        }
        throw new Fault('cloud_auth_required',401);
    }
    private static function owner(): string { $id=(string)get_current_user_id(); Settings::account($id); self::core()->ensureAccount($id); self::core()->throttle($id,'provisioning',time(),30); return $id; }
    private static function body($req): array {
        if (strlen($req->get_body())>131072) throw new Fault('request_too_large',413);
        $p=$req->get_json_params(); if (!is_array($p) || ($p!==[] && array_is_list($p))) throw new Fault('json_object_required'); return $p;
    }
    public static function routes(): void {
        $route=function(string $path,string $method,callable $fn) {
            register_rest_route('wpai-cloud/v1',$path,['methods'=>$method,'permission_callback'=>'__return_true','callback'=>fn($req)=>self::safe(function() use($fn,$req) {
                self::requireProvisioning();
                return self::response($fn($req));
            })]);
        };
        $route('/sites','GET',fn($r)=>self::core()->sites(self::owner()));
        $route('/sites','POST',function($r) { $p=self::body($r); if (!is_string($p['home']??null)||!is_string($p['site_token']??null)) throw new Fault('home_and_site_token_required'); return self::core()->connect(self::owner(),$p['home'],$p['site_token'],time()); });
        $route('/sites/(?P<id>reg_[a-f0-9]{32})','DELETE',function($r) { self::core()->disconnect(self::owner(),$r['id'],time()); return ['revoked'=>true]; });
        $route('/tokens','POST',function($r) { $p=self::body($r); if (!is_array($p['site_ids']??null)||!is_array($p['scopes']??null)) throw new Fault('token_policy_required'); return self::core()->mint(self::owner(),$p['site_ids'],$p['scopes'],time()); });
        $route('/tokens/(?P<hash>[a-f0-9]{64})','DELETE',function($r) { self::core()->revokeToken(self::owner(),$r['hash'],time()); return ['revoked'=>true]; });
        $route('/usage','GET',fn($r)=>self::core()->usage(self::owner(),time()));
        // Licence self-service. The owner is always the authenticated
        // WordPress user; a client can never submit its own account or plan.
        $route('/licenses','GET',fn($r)=>self::core()->licenses(self::owner()));
        $route('/licenses/activate','POST',function($r) {
            $p=self::body($r);
            if(!is_string($p['key']??null)||!is_string($p['site_id']??null)) throw new Fault('license_key_and_site_required');
            return self::core()->activateLicense(self::owner(),$p['key'],$p['site_id'],time());
        });
        $route('/licenses/(?P<id>lic_[a-f0-9]{24})/deactivate','POST',function($r) {
            $p=self::body($r);
            if(!is_string($p['site_id']??null)) throw new Fault('license_site_required');
            return self::core()->deactivateLicense(self::owner(),$r['id'],$p['site_id'],time());
        });
        // Paired-site read-only usage projection. Authenticated by the site
        // read key derived at pairing, never by a WordPress cookie or a Cloud
        // bearer token. One site can only read its own owner's projection.
        register_rest_route('wpai-cloud/v1','/site/usage',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>fn($r)=>self::safe(function() use($r) {
            $p=self::body($r);
            if(!is_string($p['site_url']??null)||!is_string($p['status_key']??null)) throw new Fault('site_usage_credentials_required');
            return self::response(self::core()->siteUsage($p['site_url'],$p['status_key'],time()));
        })]);
        // Purchased-key activation from the paired site. Authenticated by the
        // same site read key as /site/usage; no WordPress cookie or bearer
        // token is accepted, and owner/site/plan are always server-derived.
        register_rest_route('wpai-cloud/v1','/site/license/activate',['methods'=>'POST','permission_callback'=>'__return_true','callback'=>fn($r)=>self::safe(function() use($r) {
            if(strlen($r->get_body())>4096) throw new Fault('request_too_large',413);
            $p=self::body($r);
            if(!is_string($p['site_url']??null)||!is_string($p['status_key']??null)||!is_string($p['license_key']??null)) throw new Fault('site_license_credentials_required');
            return self::response(self::core()->siteActivateLicense($p['site_url'],$p['status_key'],$p['license_key'],time()));
        })]);
        $route('/connection-check','GET',function($r) { $home=$r['home']; if(!is_string($home)||strlen($home)>512)throw new Fault('invalid_site_input'); return self::pairing()->checkSite(self::owner(),$home,time()); });
        $route('/ledger','GET',fn($r)=>self::core()->ledger(self::owner()));
        $route('/account/closure-preview','GET',fn($r)=>self::core()->closureInventory(self::owner()));
        $route('/account/suspend','POST',function($r) {
            $p=self::body($r);
            if (($p['confirm']??null)!=='SUSPEND') throw new Fault('suspension_confirmation_required',409);
            $owner=self::owner();
            return self::core()->suspendAccount($owner,time());
        });
        register_rest_route('wpai-cloud/v1','/pairing/request',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>fn($r)=>self::safe(fn()=>self::response(self::pairing()->descriptor(is_string($r['state'])?$r['state']:'',time())))]);
        $route('/sites/(?P<id>reg_[a-f0-9]{32})/foundation','GET',fn($r)=>self::core()->foundation(self::owner(),$r['id'],time()));
        register_rest_route('wpai-cloud/v1','/health',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>fn()=>self::response(['service'=>'wp-ai-cloud','version'=>WPAI_CLOUD_VERSION,'configured'=>self::configured(),'mode'=>'approved_beta','public_mcp_url'=>Settings::endpoint(),'public_sse_url'=>Settings::sseEndpoint(),'oauth_implementation'=>'beta_pkce_s256','providers'=>['chatgpt'=>'beta','qwen'=>'beta_sse_and_http','claude'=>'planned','deepseek'=>'planned'],'schema'=>'gateway_v1_separate_from_core_v1','oauth'=>self::configured(),'batch_accounting'=>self::configured()&&self::core()->batchAvailable(),'billing'=>false,'commercial_client_entitlements'=>false])]);
        self::oauthRoutes();
        register_rest_route('wpai-cloud/v1','/mcp',['methods'=>'POST,GET,DELETE','permission_callback'=>'__return_true','callback'=>[self::class,'mcp']]);
        $admin=function(string $path,callable $fn) {
            register_rest_route('wpai-cloud/v1',$path,['methods'=>'POST','permission_callback'=>'__return_true','callback'=>fn($r)=>self::safe(function() use($fn,$r) {
                self::requireProvisioning();
                if(!current_user_can('manage_options')) throw new Fault('cloud_admin_required',403);
                return self::response($fn($r));
            })]);
        };
        $admin('/accounts/(?P<owner>[0-9]+)/reactivate',function($r) {
            $p=self::body($r);
            if(($p['confirm']??null)!=='REACTIVATE') throw new Fault('reactivation_confirmation_required',409);
            self::owner();
            $target=(string)$r['owner'];
            if(!Settings::eligible($target)) throw new Fault('target_account_ineligible',409);
            return self::core()->reactivateAccount((string)get_current_user_id(),$target,time());
        });
        $admin('/accounts/(?P<owner>[0-9]+)/plan',function($r) { $p=self::body($r); if (!is_string($p['plan']??null)) throw new Fault('plan_required'); self::owner(); self::core()->setPlan((string)get_current_user_id(),$r['owner'],$p['plan'],time()); return ['updated'=>true]; });
        $admin('/licenses',function($r) {
            $p=self::body($r);
            if(!is_string($p['plan']??null)||$p['plan']==='') throw new Fault('plan_required');
            $scheme=null;
            if(isset($p['scheme'])) { if(!is_string($p['scheme'])||$p['scheme']==='') throw new Fault('invalid_license_scheme'); $scheme=$p['scheme']; }
            $owner=null;
            if(isset($p['owner'])&&$p['owner']!==''&&$p['owner']!==0) {
                $owner=is_int($p['owner'])?(string)$p['owner']:(is_string($p['owner'])?$p['owner']:null);
                if($owner===null||!preg_match('/^[0-9]{1,20}$/D',$owner)) throw new Fault('invalid_license_owner');
            }
            $options=[];
            foreach(['activation_limit','duration_days','expires_at'] as $name) if(isset($p[$name])) { if(!is_int($p[$name])) throw new Fault('invalid_license_option'); $options[$name]=$p[$name]; }
            if(isset($p['comment'])) { if(!is_string($p['comment'])) throw new Fault('invalid_license_comment'); $options['comment']=$p['comment']; }
            self::owner();
            return self::core()->issueLicense((string)get_current_user_id(),$owner,$p['plan'],time(),$scheme,$options);
        });
        $admin('/license-deliveries/(?P<order>[0-9]+)/resend',function($r) {
            $p=self::body($r);
            if(($p['confirm']??null)!=='RESEND') throw new Fault('license_email_resend_confirmation_required',409);
            self::owner();
            if(!function_exists('wc_get_order')) throw new Fault('woocommerce_unavailable',503);
            $order=wc_get_order((int)$r['order']);
            if(!$order instanceof \WC_Order || !$order->is_paid() || !str_starts_with((string)$order->get_payment_method(),'robokassa')) throw new Fault('license_email_order_unavailable',404);
            $store=self::database();
            $delivery=$store->row('SELECT owner,email_ciphertext,email_status FROM '.$store->table('license_deliveries').' WHERE order_id=?',[(string)$order->get_id()]);
            if(!$delivery || $delivery['owner']!==(string)$order->get_customer_id() || $delivery['email_ciphertext']==='' || $delivery['email_status']==='revoked') throw new Fault('license_email_copy_unavailable',409);
            $store->execute('UPDATE '.$store->table('license_deliveries').' SET email_status=?,email_attempts=?,email_started_at=? WHERE order_id=?',['retry',0,0,(string)$order->get_id()]);
            try {
                $sent=LicenseDelivery::sendEmail($store,new Vault(Settings::keys()['vault']),(string)$order->get_id());
            } catch (\Throwable $e) {
                error_log('WP AI Admin administrator licence email resend failed: '.get_class($e));
                $sent=false;
            }
            $order->add_order_note($sent?'WP AI Admin: администратор повторно отправил письмо с лицензией на зарегистрированный адрес аккаунта.':'WP AI Admin: повторная отправка письма с лицензией не удалась; автоматические попытки продолжаются.');
            if(!$sent) self::queueOrExhaustLicenseEmail($order,$store);
            return ['sent'=>$sent,'order_id'=>(int)$order->get_id()];
        });
        foreach(['reissue'=>'REISSUE','revoke'=>'REVOKE','suspend'=>'SUSPEND','reactivate'=>'REACTIVATE'] as $action=>$confirmation) {
            $admin('/licenses/(?P<id>lic_[a-f0-9]{24})/'.$action,function($r) use($action,$confirmation) {
                $p=self::body($r);
                if(($p['confirm']??null)!==$confirmation) throw new Fault('license_'.$action.'_confirmation_required',409);
                self::owner();
                $operator=(string)get_current_user_id(); $now=time();
                if($action==='reissue') return self::core()->reissueLicense($operator,$r['id'],$now);
                if($action==='revoke') return self::core()->revokeLicense($operator,$r['id'],$now);
                if($action==='suspend') return self::core()->suspendLicense($operator,$r['id'],$now);
                return self::core()->reactivateLicense($operator,$r['id'],$now);
            });
        }
        $admin('/accounts/(?P<owner>[0-9]+)/requests/(?P<key>[a-f0-9]{64})/reconcile',function($r) { $p=self::body($r); if(!is_string($p['outcome']??null)||!is_array($p['evidence']??null)) throw new Fault('operator_receipt_evidence_required'); self::owner(); return self::core()->reconcile((string)get_current_user_id(),$r['owner'],$r['key'],$p['outcome'],$p['evidence'],time()); });
    }
    private static function oauthRoutes(): void {
        foreach (['register','token','revoke'] as $method) register_rest_route('wpai-cloud/v1','/oauth/'.$method,['methods'=>'POST','permission_callback'=>'__return_true','callback'=>function($req) use($method) {
            try {
                if (!is_ssl()) throw new Fault('https_required',403);
                if (strlen($req->get_body())>16384) throw new Fault('request_too_large',413);
                $type=(string)$req->get_header('content-type');
                if ($method==='register') {
                    if(!str_starts_with(strtolower($type),'application/json'))throw new Fault('json_content_type_required',415);
                    $p=$req->get_json_params();
                } else {
                    if(!str_starts_with(strtolower($type),'application/x-www-form-urlencoded'))throw new Fault('form_content_type_required',415);
                    parse_str($req->get_body(),$p);
                }
                if(!is_array($p)||array_is_list($p))throw new Fault('invalid_request');
                $oauth=self::oauth();
                $bucket=hash('sha256',$method.'|'.($_SERVER['REMOTE_ADDR']??'unknown'));$oauth->rate($bucket,time());
                if($method==='register')return self::response($oauth->register($p,time()),201);
                if($method==='revoke') {
                    if(!is_string($p['token']??null)||!is_string($p['client_id']??null))throw new Fault('invalid_request');
                    $oauth->revoke($p['token'],$p['client_id']);return self::response((object)[]);
                }
                $eligible=function(string $id): bool { try { if(!Settings::eligible($id))return false;Settings::account($id);return true; } catch(\Throwable $e){return false;} };
                return self::response($oauth->exchange($p,time(),$eligible));
            } catch(Fault $e) { return self::response(['error'=>$e->reason],$e->status); }
            catch(\Throwable $e) { return self::response(['error'=>'temporarily_unavailable'],503); }
        }]);
    }
    public static function mcp($req): \WP_REST_Response {
        return self::safe(function() use($req) {
            if (!is_ssl()) throw new Fault('https_required',403);
            $origin=$req->get_header('origin'); $p=parse_url(home_url()); $own=$p['scheme'].'://'.$p['host'].(isset($p['port'])?':'.$p['port']:'');
            $u=parse_url(Settings::endpoint());$publicOrigin=$u['scheme'].'://'.$u['host'].(isset($u['port'])?':'.$u['port']:'');
            $allowed=defined('WP_AI_CLOUD_ORIGINS')?WP_AI_CLOUD_ORIGINS:[$own,$publicOrigin];
            if ($origin && !in_array($origin,$allowed,true)) throw new Fault('origin_denied',403);
            $version=$req->get_header('mcp-protocol-version');
            if ($version && !in_array($version,Gateway::VERSIONS,true)) throw new Fault('unsupported_protocol_version');
            $actor=self::authenticateHeader((string)$req->get_header('authorization'));
            if ($req->get_method()!=='POST') { $r=self::response(['error'=>'stateless_json_transport_only'],405); $r->header('Allow','POST'); return $r; }
            $type=$req->get_header('content-type'); if (!is_string($type)||!preg_match('#^application/json(?:\s*;|$)#i',$type)) throw new Fault('json_content_type_required',415);
            $accept=$req->get_header('accept'); if (!str_contains($accept,'application/json')||!str_contains($accept,'text/event-stream')) throw new Fault('mcp_accept_header_required',406);
            $reply=(new Gateway(self::core()))->handle($actor,self::body($req),time());
            return self::response($reply,$reply===null?202:200);
        });
    }
    public static function emptyNotification(bool $served,$response,$request,$server): bool {
        // REST normally serializes null as "null"; MCP notifications require a genuinely empty 202 body.
        return $request->get_route()==='/wpai-cloud/v1/mcp' && $response->get_status()===202 ? true : $served;
    }
}
