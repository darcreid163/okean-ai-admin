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
        return Settings::enabled() && !class_exists('WPAICloud\\WordPress',false) && extension_loaded('curl') && extension_loaded('sodium') && extension_loaded('pdo_mysql');
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
        add_filter('rest_pre_serve_request',[self::class,'emptyNotification'],10,4);
    }
    private static function migrate(): void {
        if ((string)get_option('wpai_gateway_db_version','')==='2') return;
        self::database()->migrate();
        update_option('wpai_gateway_db_version','2',false);
    }
    public static function statusPage(): void { Admin::page(); }
    public static function enable(): void {
        if (defined('WP_AI_CLOUD_ENABLED') && WP_AI_CLOUD_ENABLED!==true) throw new Fault('gateway_disabled_in_wp_config',503);
        if (class_exists('WPAICloud\\WordPress',false)) throw new Fault('separate_prototype_must_be_deactivated',503);
        foreach (['sodium','curl','pdo_mysql'] as $extension) if (!extension_loaded($extension)) throw new Fault($extension.'_required',503);
        Settings::prepareKeys(); self::migrate(); Settings::policy();
        update_option('wpai_gateway_enabled',true,false);
        try { self::core(); }
        catch (\Throwable $e) { update_option('wpai_gateway_enabled',false,false); throw $e; }
    }
    public static function oauth(): OAuth { return new OAuth(self::database(),self::core(),Settings::endpoint()); }
    public static function operatorCore(): Core { return self::core(); }
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
    private static function response(mixed $value,int $status=200): \WP_REST_Response {
        return new \WP_REST_Response($value,$status,['Cache-Control'=>'no-store','Content-Type'=>'application/json; charset=utf-8','X-Content-Type-Options'=>'nosniff']);
    }
    private static function safe(callable $fn): \WP_REST_Response {
        try { return $fn(); }
        catch (Fault $e) { $r=self::response(['error'=>$e->reason],$e->status); if ($e->status===401) $r->header('WWW-Authenticate',Surface::challenge()); return $r; }
        catch (\Throwable $e) { return self::response(['error'=>'cloud_service_unavailable'],503); }
    }
    public static function provisioningPermission(): bool|\WP_Error {
        // WordPress validates application passwords or cookie+REST nonce. Cloud bearer tokens never authorize provisioning.
        if (!is_user_logged_in() || !Settings::eligible((string)get_current_user_id())) return new \WP_Error('cloud_auth_required','WordPress authentication required',['status'=>401]);
        if (!is_ssl()) return new \WP_Error('cloud_https_required','HTTPS required',['status'=>403]);
        return true;
    }
    private static function owner(): string { $id=(string)get_current_user_id(); Settings::account($id); self::core()->ensureAccount($id); self::core()->throttle($id,'provisioning',time(),30); return $id; }
    private static function body($req): array {
        if (strlen($req->get_body())>131072) throw new Fault('request_too_large',413);
        $p=$req->get_json_params(); if (!is_array($p) || ($p!==[] && array_is_list($p))) throw new Fault('json_object_required'); return $p;
    }
    public static function routes(): void {
        $route=function(string $path,string $method,callable $fn) {
            register_rest_route('wpai-cloud/v1',$path,['methods'=>$method,'permission_callback'=>[self::class,'provisioningPermission'],'callback'=>fn($req)=>self::safe(fn()=>self::response($fn($req)))]);
        };
        $route('/sites','GET',fn($r)=>self::core()->sites(self::owner()));
        $route('/sites','POST',function($r) { $p=self::body($r); if (!is_string($p['home']??null)||!is_string($p['site_token']??null)) throw new Fault('home_and_site_token_required'); return self::core()->connect(self::owner(),$p['home'],$p['site_token'],time()); });
        $route('/sites/(?P<id>reg_[a-f0-9]{32})','DELETE',function($r) { self::core()->disconnect(self::owner(),$r['id'],time()); return ['revoked'=>true]; });
        $route('/tokens','POST',function($r) { $p=self::body($r); if (!is_array($p['site_ids']??null)||!is_array($p['scopes']??null)) throw new Fault('token_policy_required'); return self::core()->mint(self::owner(),$p['site_ids'],$p['scopes'],time()); });
        $route('/tokens/(?P<hash>[a-f0-9]{64})','DELETE',function($r) { self::core()->revokeToken(self::owner(),$r['hash'],time()); return ['revoked'=>true]; });
        $route('/usage','GET',fn($r)=>self::core()->usage(self::owner(),time()));
        $route('/ledger','GET',fn($r)=>self::core()->ledger(self::owner()));
        $route('/sites/(?P<id>reg_[a-f0-9]{32})/foundation','GET',fn($r)=>self::core()->foundation(self::owner(),$r['id'],time()));
        register_rest_route('wpai-cloud/v1','/health',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>fn()=>self::response(['service'=>'wp-ai-cloud','version'=>'0.3.2','configured'=>self::configured(),'mode'=>'approved_beta','public_mcp_url'=>Settings::endpoint(),'public_sse_url'=>Settings::sseEndpoint(),'oauth_implementation'=>'beta_pkce_s256','providers'=>['chatgpt'=>'beta','qwen'=>'beta_sse_and_http','claude'=>'planned','deepseek'=>'planned'],'schema'=>'gateway_v1_separate_from_core_v1','oauth'=>self::configured(),'batch_accounting'=>false,'billing'=>false,'commercial_client_entitlements'=>false])]);
        self::oauthRoutes();
        register_rest_route('wpai-cloud/v1','/mcp',['methods'=>'POST,GET,DELETE','permission_callback'=>'__return_true','callback'=>[self::class,'mcp']]);
        $admin=function(string $path,callable $fn) {
            register_rest_route('wpai-cloud/v1',$path,['methods'=>'POST','permission_callback'=>function(){ $p=self::provisioningPermission(); return $p===true?(current_user_can('manage_options')?true:new \WP_Error('cloud_admin_required','Cloud operator required',['status'=>403])):$p; },'callback'=>fn($r)=>self::safe(fn()=>self::response($fn($r)))]);
        };
        $admin('/accounts/(?P<owner>[0-9]+)/plan',function($r) { $p=self::body($r); if (!is_string($p['plan']??null)) throw new Fault('plan_required'); self::owner(); self::core()->setPlan((string)get_current_user_id(),$r['owner'],$p['plan'],time()); return ['updated'=>true]; });
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
