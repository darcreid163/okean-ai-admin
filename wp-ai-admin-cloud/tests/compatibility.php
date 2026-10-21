<?php
declare(strict_types=1);
// Executes the recovered Core v1 schema installer and integrated gateway migration.
// SQLite uses a DDL translation; CI runs the original MySQL DDL against MySQL 8.
use WPAICloudGateway\{Settings,Store,WordPress,Fault,Admin,Security};
define('ABSPATH',__DIR__.'/fixtures/wordpress/'); define('ARRAY_A','ARRAY_A');
define('AUTH_KEY',str_repeat('a',64)); define('AUTH_SALT',str_repeat('b',64));
$options=[]; $autoload=[]; $routes=[]; $actions=[]; $loggedIn=true; $admin=true; $ssl=true; $coldOptions=false;
function get_option($name,$default=false) {
    global $options,$coldOptions;
    if(!array_key_exists($name,$options))return $default;
    $value=$options[$name];
    // Model a fresh request reading non-serialized scalars from wp_options.
    return $coldOptions&&is_scalar($value)?(string)$value:$value;
}
function update_option($name,$value,$load=null) { global $options,$autoload; $options[$name]=$value; $autoload[$name]=$load; return true; }
function add_option($name,$value,$deprecated='',$load=true) { global $options,$autoload; if(array_key_exists($name,$options))return false; $options[$name]=$value; $autoload[$name]=$load; return true; }
function current_time($type,$gmt=false) { return gmdate('Y-m-d H:i:s'); }
function home_url($path='') { return 'https://wpaiadmin.ru'.$path; }
function rest_url($path='') { return home_url('/wp-json/'.$path); }
function plugin_dir_path($file) { return dirname($file).'/'; }
function register_activation_hook($file,$callback) {}
function add_action($name,$callback) { global $actions; $actions[$name][]=$callback; }
function add_filter($name,$callback,$priority=10,$args=1) {}
function register_rest_route($namespace,$route,$args) { global $routes; $routes[$namespace.$route][]=$args; }
function is_user_logged_in() { global $loggedIn; return $loggedIn; }
function current_user_can($capability) { global $admin; return $capability==='manage_options'?$admin:true; }
function is_ssl() { global $ssl; return $ssl; }
function get_current_user_id() { return 42; }
function get_user_by($field,$value) { return (object)['ID'=>$value]; }
function user_can($user,$capability) { return current_user_can($capability); }
function wp_get_environment_type() { return 'production'; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
class WP_Error { public function __construct(public $code,public $message,public $data) {} }
class WP_REST_Response {
    public function __construct(public $data,public $status=200,public $headers=[]) {}
    public function header($name,$value) { $this->headers[$name]=$value; }
    public function get_status() { return $this->status; }
}
class TestWpdb {
    public string $prefix='wp_'; public int $insert_id=0;
    public function __construct(public PDO $pdo) {}
    public function get_charset_collate() { return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4':''; }
    public function prepare($sql,...$args) {
        $i=0; return preg_replace_callback('/%[ds]/',function($m)use(&$i,$args){ $arg=$args[$i++]; return $m[0]==='%d'?(string)(int)$arg:$this->pdo->quote((string)$arg); },$sql);
    }
    public function query($sql) { return $this->pdo->exec($sql); }
    public function get_var($sql) { return $this->pdo->query($sql)->fetchColumn(); }
    public function get_row($sql,$format=ARRAY_A) { return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC)?:null; }
    public function get_results($sql,$format=ARRAY_A) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function insert($table,$data) {
        $sql='INSERT INTO '.$table.' ('.implode(',',array_keys($data)).') VALUES ('.implode(',',array_fill(0,count($data),'?')).')';
        $this->pdo->prepare($sql)->execute(array_values($data)); $this->insert_id=(int)$this->pdo->lastInsertId(); return 1;
    }
}
function dbDelta($sql) {
    global $wpdb;
    // The translation below is newline-anchored, so it must not depend on the
    // checkout line endings (a Windows core.autocrlf=true clone is CRLF).
    $sql=str_replace("\r\n","\n",$sql);
    $sql=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$sql);
    if ($wpdb->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
        $sql=preg_replace('/id bigint\(20\) unsigned NOT NULL AUTO_INCREMENT,/','id INTEGER PRIMARY KEY AUTOINCREMENT,',$sql);
        $sql=preg_replace('/^\s*PRIMARY KEY \(id\),?\n/m','',$sql);
        $sql=preg_replace('/^\s*KEY [^\n]+\n/m','',$sql);
        $sql=preg_replace('/UNIQUE KEY \w+ \(([^)]+)\)/','UNIQUE ($1)',$sql);
        $sql=str_replace(' unsigned','',$sql);
        $sql=preg_replace('/,\s*\)/',"\n)",$sql);
    }
    $wpdb->query($sql);
}
$checks=0;
function check(bool $ok,string $message): void { global $checks; $checks++; if(!$ok)throw new RuntimeException($message); }
function rejects(callable $fn,string $reason): void { try{$fn();}catch(Fault $e){check($e->reason===$reason,'Wrong fault: '.$e->reason);return;}throw new RuntimeException('Expected '.$reason); }
$database=tempnam(sys_get_temp_dir(),'wpai-compat-');
$dsn=getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database;
$pdo=new PDO($dsn,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$wpdb=new TestWpdb($pdo);
require dirname(__DIR__).'/wp-ai-admin-cloud.php';
$tables=['accounts','plans','plan_versions','licenses','sites','site_tokens','intents','usage_events','usage_balances','usage_reservations','audit_log'];
if (getenv('TEST_MYSQL_DSN')) foreach($tables as $name)$pdo->exec('DROP TABLE IF EXISTS '.WPAI_Cloud_DB::table($name));
WPAI_Cloud_DB::install();
$wpdb->insert(WPAI_Cloud_DB::table('accounts'),['wp_user_id'=>42,'status'=>'active','created_at'=>'2026-10-02 00:00:00','updated_at'=>'2026-10-02 00:00:00']);
$wpdb->insert(WPAI_Cloud_DB::table('usage_balances'),['account_id'=>1,'license_id'=>123,'period_start'=>'2026-10-01 00:00:00','period_end'=>'2026-11-01 00:00:00','base_limit'=>100,'addon_actions'=>50,'used_actions'=>19,'reserved_actions'=>2,'updated_at'=>'2026-10-02 00:00:00']);
$snapshot=function()use($tables,$wpdb){$values=[];foreach($tables as $name)$values[$name]=$wpdb->get_results('SELECT * FROM '.WPAI_Cloud_DB::table($name).' ORDER BY id');return Security::canonical($values);};
$before=$snapshot();
check(get_option('wpai_cloud_db_version')==='1','Core schema version changed');
check(!Settings::enabled() && !WordPress::configured(),'Pilot implicitly enabled');
Settings::prepareKeys(); $keys=Settings::keys();
check(strlen($keys['vault'])===32 && strlen($keys['signing'])===64,'Incorrect key sizes');
check(!str_contains($options['wpai_gateway_wrapped_keys_v1'],base64_encode($keys['vault'])),'Plaintext vault key in DB');
check($autoload['wpai_gateway_wrapped_keys_v1']===false,'Keys autoloaded');
Settings::prepareKeys();check(Settings::keys()===$keys,'Keys rotated by repeat initialization');
$store=new Store($pdo,'wp_wpai_gateway_');
$property=new ReflectionProperty(WordPress::class,'store');$property->setValue(null,$store);
WordPress::enable();
check(WordPress::configured() && Settings::enabled(),'Gateway failed to enable');
$coldOptions=true;
check(get_option('wpai_gateway_enabled')==='1','Cold WordPress option fixture must return a string');
check(WordPress::configured() && Settings::enabled(),'Enabled gateway was lost on the next request');
foreach([true,1,'1'] as $value) {
    $options['wpai_gateway_enabled']=$value;
    check(Settings::enabled() && WordPress::configured(),'Valid saved enabled value rejected');
}
foreach([false,0,'0','','false','true',2,[],null] as $value) {
    $options['wpai_gateway_enabled']=$value;
    check(!Settings::enabled() && !WordPress::configured(),'Gateway enabled by an off or noncanonical value');
}
WordPress::enable();
check(Settings::keys()===$keys && $snapshot()===$before,'Reload check changed existing keys or Core data');
check(get_option('wpai_gateway_db_version')==='4','Gateway migration not recorded');
check($snapshot()===$before,'Gateway migration modified legacy data');
WordPress::enable(); check($snapshot()===$before && Settings::keys()===$keys,'Repeated update changed core data or keys');
check(Settings::policy()['plans']===['free'=>100,'pro'=>500,'biznes'=>2000,'biznespro'=>5000],'Recovered v2 tariff policy differs from approved limits');
check(Settings::policy()['legacy_plans']===['agency'=>5000,'free'=>100,'power'=>2000,'pro'=>500,'scale'=>10000],'Legacy Core plan limits changed');
Settings::account('42');check($snapshot()===$before,'Existing account changed by bridge');
$wpdb->query("UPDATE ".WPAI_Cloud_DB::table('accounts')." SET status='suspended' WHERE wp_user_id=42");
rejects(fn()=>Settings::account('42'),'core_account_unavailable');
$wpdb->query("UPDATE ".WPAI_Cloud_DB::table('accounts')." SET status='active' WHERE wp_user_id=42");
$wpdb->query('UPDATE '.WPAI_Cloud_DB::table('plan_versions')." SET verify_billable=1 WHERE version_key='FREE_V1'");
rejects(fn()=>Settings::policy(),'core_plan_policy_incompatible');
$wpdb->query('UPDATE '.WPAI_Cloud_DB::table('plan_versions')." SET verify_billable=0 WHERE version_key='FREE_V1'");
foreach($actions['rest_api_init'] as $callback)$callback();
foreach(['/status','/plans','/mcp','/health','/sites','/tokens','/usage','/ledger'] as $route)check(isset($routes['wpai-cloud/v1'.$route]),'Missing route '.$route);
check(count($routes['wpai-cloud/v1/status'])===1 && count($routes['wpai-cloud/v1/plans'])===1,'Legacy public routes overwritten');
check(WPAI_Cloud_REST::status()->data['data']['version']==='0.3.11','Old status version');
check(count(WPAI_Cloud_REST::plans()->data['data']['items'])===5,'Old plans contract changed');
$loggedIn=false;check(WordPress::provisioningPermission() instanceof WP_Error,'Anonymous provisioning');
$loggedIn=true;$admin=false;check(WordPress::provisioningPermission() instanceof WP_Error,'Subscriber provisioning');
$admin=true;$ssl=false;check(WordPress::provisioningPermission() instanceof WP_Error,'HTTP provisioning');
$ssl=true;check(WordPress::provisioningPermission()===true,'HTTPS administrator rejected');
$owner=WordPress::operatorOwner(); $core=WordPress::operatorCore();
$site='reg_'.str_repeat('e',32);
$store->execute('INSERT INTO '.$store->table('sites').' (id,owner,home,url_hash,plugin_site_id,installation_id,token_sealed,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$site,$owner,'https://wpaiadmin.ru',hash('sha256','https://wpaiadmin.ru'),'fixture-site','fixture-install','fixture-unused','active',time()]);
$token=$core->mint($owner,[$site],['mcp:read'],time())['access_token'];
class TestRequest {
    public function __construct(public array $headers,public array $body,public string $method='POST') {}
    public function get_header($name) { return $this->headers[$name]??''; }
    public function get_body() { return json_encode($this->body); }
    public function get_json_params() { return $this->body; }
    public function get_method() { return $this->method; }
    public function get_route() { return '/wpai-cloud/v1/mcp'; }
}
$headers=['authorization'=>'Bearer '.$token,'content-type'=>'application/json','accept'=>'application/json, text/event-stream','mcp-protocol-version'=>'2025-11-25'];
$message=['jsonrpc'=>'2.0','id'=>99,'method'=>'initialize','params'=>['protocolVersion'=>'2025-11-25']];
$req=new TestRequest($headers,$message);$response=WordPress::mcp($req);
check($response->status===200 && $response->data['result']['serverInfo']['version']==='0.3.11','HTTP MCP initialization failed');
foreach ([['authorization','',401],['origin','https://evil.example.com',403],['mcp-protocol-version','unknown',400],['content-type','text/plain',415],['accept','application/json',406]] as [$header,$value,$status]) {
    $changed=$headers; $changed[$header]=$value;
    check(WordPress::mcp(new TestRequest($changed,$message))->status===$status,'HTTP validation bypass: '.$header);
}
check(WordPress::mcp(new TestRequest($headers,$message,'GET'))->status===405,'Unsupported GET accepted');
$notify=new TestRequest($headers,['jsonrpc'=>'2.0','method'=>'notifications/initialized']);
$notifyResponse=WordPress::mcp($notify);
check($notifyResponse->status===202 && WordPress::emptyNotification(false,$notifyResponse,$notify,null),'Notification not empty 202');
$admin=false;check(WordPress::mcp($req)->status===403,'Demoted user retained gateway access');$admin=true;
$ssl=false;check(WordPress::mcp($req)->status===403,'HTTP gateway accepted');$ssl=true;
$core->revokeToken($owner,hash('sha256',$token),time());
check(WordPress::mcp($req)->status===401,'Revoked HTTP gateway token accepted');
check($snapshot()===$before,'Gateway authentication changed legacy data');
$gatewaySnapshot=function()use($store){$value=[];foreach(['accounts','balances','sites','tokens','requests','audit','rate'] as $table)$value[$table]=$store->rows('SELECT * FROM '.$store->table($table));return Security::canonical($value);};
$oldGateway=$gatewaySnapshot();update_option('wpai_gateway_db_version','3',false);WordPress::enable();
check($gatewaySnapshot()===$oldGateway && get_option('wpai_gateway_db_version')==='4','Upgrade from gateway v3 to v4 reset existing connections or tokens');
check(WPAICloudGateway\Surface::routeKind('/.well-known/oauth-authorization-server')===null,'Native OAuth issuer discovery overwritten');
check(WPAICloudGateway\Surface::routeKind('/.well-known/oauth-protected-resource')===null,'Native protected resource discovery overwritten');
check(WPAICloudGateway\Surface::metadataUrl()==='https://wpaiadmin.ru/.well-known/oauth-protected-resource/mcp','Wrong main-domain metadata URL');
check(WPAICloudGateway\Settings::sseEndpoint()==='https://wpaiadmin.ru/sse','Wrong main-domain SSE URL');
check(WPAICloudGateway\Surface::authorizationMetadata()['issuer']==='https://wpaiadmin.ru/wpai-cloud','Central issuer conflicts with native OAuth');
check(WPAICloudGateway\Surface::resourceMetadata()['resource']==='https://wpaiadmin.ru/mcp','Wrong public audience');
check(WPAICloudGateway\Surface::routeKind('/mcp')==='mcp' && str_contains(WPAICloudGateway\Surface::challenge(),'oauth-protected-resource/mcp'),'Alias/discovery challenge missing');
$admin=false;check(!WPAICloudGateway\Settings::eligible('42'),'Unapproved subscriber access');
update_option('wpai_gateway_beta_users',[42],false);check(WPAICloudGateway\Settings::eligible('42'),'Approved beta user blocked');
check(WordPress::provisioningPermission()===true,'Beta user provisioning blocked');
update_option('wpai_gateway_beta_users',[],false);check(!WPAICloudGateway\Settings::eligible('42'),'Removed beta user still eligible');$admin=true;
$endpointRequests=[];$loopbackBad=false;
function wp_remote_post($url,$args) {
    global $endpointRequests,$loopbackBad;
    $message=json_decode($args['body'],true);$endpointRequests[]=[$url,$args];
    if($loopbackBad)return ['code'=>200,'body'=>json_encode(['jsonrpc'=>'2.0','id'=>$message['id'],'result'=>['content'=>[['type'=>'text','text'=>'{}']],'isError'=>true]])];
    if(!isset($message['id']))return ['code'=>202,'body'=>''];
    $result=match($message['method']) {
        'initialize'=>['protocolVersion'=>'2025-11-25'],
        'tools/list'=>['tools'=>WPAICloudGateway\Gateway::tools()],
        'tools/call'=>['content'=>[['type'=>'text','text'=>json_encode(match($message['params']['name']) {
            'cloud_sites_list'=>[['id'=>'reg_'.str_repeat('c',32)]],
            'cloud_site_tools_list'=>[['name'=>'system_manifest']],
            'cloud_usage'=>['limit'=>100,'used'=>0,'reserved'=>0,'timezone'=>'UTC']
        })]],'isError'=>false]
    };
    return ['code'=>200,'body'=>json_encode(['jsonrpc'=>'2.0','id'=>$message['id'],'result'=>$result])];
}
function is_wp_error($response) { return $response instanceof WP_Error; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
$smoke=Admin::smoke('wpai_cloud_'.str_repeat('d',43),'reg_'.str_repeat('c',32));
check($smoke['passed'] && count($endpointRequests)===6 && $smoke['billable_actions']===0 && !$smoke['writes_tested'],'HTTP smoke sequence');
foreach($endpointRequests as [$url,$args])check($url==='https://wpaiadmin.ru/mcp' && $args['redirection']===0 && $args['sslverify']===true,'Unsafe smoke transport');
$loopbackBad=true;rejects(fn()=>Admin::smoke('wpai_cloud_'.str_repeat('d',43),'reg_'.str_repeat('c',32)),'gateway_smoke_step_failed');
echo 'PASS '.$checks.' compatibility assertions; original 11 Core tables preserved; driver='.$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)."\n";
if(!getenv('TEST_MYSQL_DSN') && file_exists($database))unlink($database);
