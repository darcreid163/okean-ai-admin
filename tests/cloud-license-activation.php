<?php
declare(strict_types=1);
/**
 * #193 Native activation of a purchased Cloud licence key from the paired site.
 * No WordPress or network I/O: wp_remote_post is a deterministic fixture and
 * the signed usage projection is the only source of plan/quota display.
 */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-oai-cloud-usage.php';

class WP_Error {
    private string $code;
    private string $message;
    private array $data;
    public function __construct(string $code='',$message='',$data=array()){ $this->code=$code; $this->message=$message; $this->data=$data; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
    public function get_error_data(){ return $this->data; }
}
class WPAI_Activation_DB {
    public string $options='wp_options';
    public function prepare($sql,...$args){ return $sql; }
    public function esc_like($value){ return addcslashes((string)$value,'_%\\'); }
    public function get_col($sql){ return $GLOBALS['wpai_credentials']; }
}
class OAI_Gateway_Pairing { const CREDENTIAL_PREFIX='oai_pair_credential_'; }
class OAI_Quota { public static function status(){ return array('plan'=>'free','limit'=>100,'used'=>0,'remaining'=>100,'label'=>'Free'); } }

$GLOBALS['wpdb']=new WPAI_Activation_DB();
$GLOBALS['wpai_test_options']=array();
$GLOBALS['wpai_credentials']=array();
$GLOBALS['wpai_http_calls']=array();
$GLOBALS['wpai_logged_in']=true;
$GLOBALS['wpai_can']=true;

function is_user_logged_in(){ return !empty($GLOBALS['wpai_logged_in']); }
function current_user_can($cap){ return !empty($GLOBALS['wpai_can']); }
function get_current_user_id(){ return 42; }
function get_option($key,$default=false){ return array_key_exists($key,$GLOBALS['wpai_test_options'])?$GLOBALS['wpai_test_options'][$key]:$default; }
function update_option($key,$value,$autoload=null){ $GLOBALS['wpai_test_options'][$key]=$value; return true; }
function delete_option($key){ unset($GLOBALS['wpai_test_options'][$key]); return true; }
function home_url($path=''){ return 'https://activation.example.com'.$path; }
function untrailingslashit($value){ return rtrim((string)$value,'/'); }
function wp_json_encode($value){ return json_encode($value); }
function wp_remote_post($url,$args){ $GLOBALS['wpai_http_calls'][]=array('url'=>$url,'args'=>$args); return ($GLOBALS['wpai_http_handler'])($url,$args); }
function is_wp_error($value){ return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($response){ return (int)($response['response']['code']??0); }
function wp_remote_retrieve_body($response){ return (string)($response['body']??''); }

function licAssert($ok,$message){ if(!$ok)throw new RuntimeException($message); }
function licHttp($code,$body){ return array('response'=>array('code'=>$code),'body'=>is_string($body)?$body:json_encode($body)); }
function licEncode($value){ return rtrim(strtr(base64_encode($value),'+/','-_'),'='); }
function licCanonical($value){
    $sort=function($item)use(&$sort){
        if(!is_array($item))return $item;
        if(!array_is_list($item))ksort($item,SORT_STRING);
        foreach($item as $key=>$child)$item[$key]=$sort($child);
        return $item;
    };
    return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function licSignedUsage(array $claims,string $secret,int $now,string $site,string $id): array {
    $claims=array('contract_version'=>1,'issuer'=>'okean-cloud-gateway','audience'=>'wp-ai-admin-native','issued_at'=>$now,'expires_at'=>$now+300)+$claims;
    $payload=licEncode(licCanonical($claims));
    $public=sodium_crypto_sign_publickey_from_secretkey($secret);
    return array('contract'=>'wpai-site-usage-v1','site_url'=>$site,'site_id'=>$id,'envelope'=>array(
        'alg'=>'Ed25519','key_id'=>substr(hash('sha256',$public),0,16),'payload'=>$payload,
        'signature'=>licEncode(sodium_crypto_sign_detached("OAI-SITE-USAGE-V1\n".$payload,$secret)),
    ));
}

$now=time();
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$public=licEncode(sodium_crypto_sign_publickey($keys));
$keyId=substr(hash('sha256',sodium_crypto_sign_publickey($keys)),0,16);
$site='https://activation.example.com';
$siteId='reg_'.str_repeat('a',32);
$hash=str_repeat('c',64);
$licenseKey='wpai_lic_'.str_repeat('A',43);
$licenseId='lic_'.str_repeat('b',24);

$GLOBALS['wpai_credentials']=array('oai_pair_credential_'.$hash);
$GLOBALS['wpai_test_options']['oai_pair_credential_'.$hash]=array(
    'user_id'=>42,'account_id'=>'42','account_label'=>'Owner','created_at'=>$now,
    'usage_projection'=>'wpai-site-usage-v1','status_key'=>str_repeat('d',64),
    'signing_public_key'=>$public,'signing_key_id'=>$keyId,
);

$claims=array(
    'site_url'=>$site,'site_id'=>$siteId,'account_id'=>'42','plan'=>'pro','plan_label'=>'Pro',
    'limit'=>null,'used'=>0,'reserved'=>0,'remaining'=>null,
    'period'=>'2026-11','period_kind'=>'calendar_month_utc','quota_scheme'=>'tariffs_v3',
    'quota_status'=>'requires_owner_approval','period_start_utc'=>'2026-11-01T00:00:00Z','period_end_utc'=>'2026-12-01T00:00:00Z',
    'policy_version'=>'tariffs-v3-test','timezone'=>'UTC',
    'license_id'=>$licenseId,'license_key_prefix'=>'wpai_lic_abcdefg','license_status'=>'active',
    'license_activation_limit'=>1,'license_activations'=>1,'license_issued_at'=>$now-100,'license_expires_at'=>null,
);
$activation=array(
    'contract'=>'wpai-site-license-v1','activated'=>true,'idempotent'=>false,'site_id'=>$siteId,
    'license'=>array(
        'id'=>$licenseId,'key_prefix'=>'wpai_lic_abcdefg','plan'=>'pro','plan_label'=>'Pro',
        'quota_scheme'=>'tariffs_v3','status'=>'active','activation_limit'=>1,'activations'=>1,
        'issued_at'=>$now-100,'expires_at'=>null,
    ),
);
$usage=licSignedUsage($claims,$secret,$now,$site,$siteId);
$GLOBALS['wpai_http_handler']=function($url,$args)use(&$activation,&$usage){
    if($url===OAI_Cloud_Usage::LICENSE_ENDPOINT)return isset($activation['response'])?$activation:licHttp(200,$activation);
    if($url===OAI_Cloud_Usage::ENDPOINT)return licHttp(200,$usage);
    return licHttp(404,array('error'=>'not_found'));
};

/* ------------------------------------------------- valid activation --- */

$result=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_array($result),'Valid activation returned an error');
licAssert($result['usage_valid']===true&&$result['plan']==='pro'&&$result['plan_label']==='Pro','Signed usage was not used after activation');
licAssert($result['quota_pending']===true,'Pending paid quota was not surfaced');
licAssert(($result['activation']['site_id']??null)===$siteId,'Activation response lost the site binding');
licAssert(count($GLOBALS['wpai_http_calls'])===2,'Activation must perform exactly one activation and one usage request');
$activationCall=$GLOBALS['wpai_http_calls'][0];
licAssert($activationCall['url']===OAI_Cloud_Usage::LICENSE_ENDPOINT,'Activation endpoint changed');
$sent=json_decode($activationCall['args']['body'],true);
$sentKeys=array_keys($sent);sort($sentKeys);
licAssert($sentKeys===array('license_key','site_url','status_key'),'Activation request leaked or omitted fields');
licAssert($sent['site_url']===$site&&$sent['status_key']===str_repeat('d',64)&&$sent['license_key']===$licenseKey,'Activation request body changed');
licAssert(!array_key_exists('account_id',$sent)&&!array_key_exists('site_id',$sent)&&!array_key_exists('plan',$sent),'Activation request submitted server-owned identity/plan');
licAssert(strpos(json_encode($GLOBALS['wpai_test_options']),$licenseKey)===false,'Plaintext licence key was stored locally');
$status=OAI_Cloud_Usage::status();
licAssert($status['state']==='verified'&&$status['projection']['plan']==='pro','Signed usage was not persisted for display');
licAssert($status['projection']['limit']===null&&$status['projection']['remaining']===null,'Pending paid quota fabricated a balance');
licAssert($status['projection']['license_key_prefix']==='wpai_lic_abcdefg'&&$status['projection']['license_status']==='active','Licence display claims missing');

/* --------------------------------------------------- idempotent retry --- */

$activation['idempotent']=true;
$repeat=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_array($repeat)&&$repeat['usage_valid']===true,'Idempotent activation was rejected');
licAssert($repeat['activation']['idempotent']===true,'Idempotent flag was lost');

/* ----------------------------------------------------- local refusals --- */

$GLOBALS['wpai_can']=false;
$denied=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($denied)&&$denied->get_error_code()==='oai_license_admin_required','Non-administrator activation was accepted');
$GLOBALS['wpai_can']=true;

$calls=count($GLOBALS['wpai_http_calls']);
$format=OAI_Cloud_Usage::activate_license('wpai_lic_short');
licAssert(is_wp_error($format)&&$format->get_error_code()==='oai_license_format','Malformed key was sent to the server');
licAssert(count($GLOBALS['wpai_http_calls'])===$calls,'Malformed key performed a network request');

$savedCredentials=$GLOBALS['wpai_credentials'];$savedRecord=$GLOBALS['wpai_test_options']['oai_pair_credential_'.$hash];
$GLOBALS['wpai_credentials']=array();unset($GLOBALS['wpai_test_options']['oai_pair_credential_'.$hash]);
$unpaired=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($unpaired)&&$unpaired->get_error_code()==='oai_license_unpaired','Unpaired activation was attempted');
licAssert(count($GLOBALS['wpai_http_calls'])===$calls,'Unpaired activation performed a network request');
$GLOBALS['wpai_credentials']=$savedCredentials;$GLOBALS['wpai_test_options']['oai_pair_credential_'.$hash]=$savedRecord;

/* ------------------------------------------------------ Cloud refusals --- */

$activation=licHttp(403,array('error'=>'license_bound_to_other_account'));
$bound=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($bound)&&$bound->get_error_code()==='oai_license_license_bound_to_other_account','Cross-account licence error was not mapped');
licAssert(strpos($bound->get_error_message(),$licenseKey)===false,'Error message leaked the key');
licAssert(strpos($bound->get_error_message(),'другому аккаунту')!==false,'Cross-account error lost its safe explanation');

$activation=licHttp(409,array('error'=>'license_activation_limit_reached'));
$limit=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($limit)&&$limit->get_error_code()==='oai_license_license_activation_limit_reached','Activation limit error was not mapped');
licAssert(strpos($limit->get_error_message(),'лимит активаций')!==false,'Activation limit error lost its safe explanation');

$activation=licHttp(403,array('error'=>'site_license_denied'));
$siteDenied=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($siteDenied)&&$siteDenied->get_error_code()==='oai_license_site_license_denied','Site denial was not mapped');

$activation=licHttp(200,array('contract'=>'other','activated'=>true,'site_id'=>$siteId,'license'=>array()));
$malformed=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_wp_error($malformed)&&$malformed->get_error_code()==='oai_license_response','Malformed activation contract was accepted');

/* ------------------------------- activation succeeds, usage stays untrusted --- */

$activation=array(
    'contract'=>'wpai-site-license-v1','activated'=>true,'idempotent'=>false,'site_id'=>$siteId,
    'license'=>array('id'=>$licenseId,'key_prefix'=>'wpai_lic_abcdefg','plan'=>'pro','plan_label'=>'Pro','quota_scheme'=>'tariffs_v3','status'=>'active','activation_limit'=>1,'activations'=>1,'issued_at'=>$now-100,'expires_at'=>null),
);
$tampered=$usage;$tampered['envelope']['signature']=licEncode(random_bytes(64));
$usage=$tampered;
$untrusted=OAI_Cloud_Usage::activate_license($licenseKey);
licAssert(is_array($untrusted)&&$untrusted['usage_valid']===false,'Invalid usage signature was trusted after activation');
licAssert($untrusted['plan']===null&&$untrusted['quota_pending']===false,'Unsigned activation invented a plan/quota');
$after=OAI_Cloud_Usage::status();
licAssert($after['state']==='unknown'&&$after['projection']===null,'Invalid signature fell back to a local balance');

echo 'PASS 27 cloud licence activation assertions'.PHP_EOL;
