<?php
declare(strict_types=1);
/**
 * #135 Native verification and dashboard state: a projection is accepted only
 * with a valid Ed25519 signature bound to this site/account, fresh claims and a
 * consistent server-calculated balance. No WordPress or network I/O.
 */
define('ABSPATH',__DIR__.'/');
require dirname(__DIR__).'/includes/class-oai-cloud-usage.php';

function usageAssert($ok,$message){if(!$ok)throw new RuntimeException($message);}
function usageCanonical($value){
    $sort=function($item)use(&$sort){
        if(!is_array($item))return $item;
        if(!array_is_list($item))ksort($item,SORT_STRING);
        foreach($item as $key=>$child)$item[$key]=$sort($child);
        return $item;
    };
    return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function usageEncode($value){return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function usageDecode($value){return base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true);}
function usageSigned(array $claims,string $secret,int $now,string $site,string $id,int $ttl=300): array {
    $claims=['contract_version'=>1,'issuer'=>'okean-cloud-gateway','audience'=>'wp-ai-admin-native',
        'issued_at'=>$now,'expires_at'=>$now+$ttl]+$claims;
    $payload=usageEncode(usageCanonical($claims));
    $public=sodium_crypto_sign_publickey_from_secretkey($secret);
    return ['contract'=>'wpai-site-usage-v1','site_url'=>$site,'site_id'=>$id,'envelope'=>[
        'alg'=>'Ed25519','key_id'=>substr(hash('sha256',$public),0,16),'payload'=>$payload,
        'signature'=>usageEncode(sodium_crypto_sign_detached("OAI-SITE-USAGE-V1\n".$payload,$secret)),
    ]];
}
function usageContext(string $public,string $keyId,string $site,string $account): array {
    return ['public_key'=>$public,'key_id'=>$keyId,'site_url'=>$site,'account_id'=>$account];
}

$now=strtotime('2026-11-06T12:00:00Z');
$keys=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($keys);
$public=usageEncode(sodium_crypto_sign_publickey($keys));
$keyId=substr(hash('sha256',sodium_crypto_sign_publickey($keys)),0,16);
$site='https://usage.example.com';
$siteId='reg_'.str_repeat('a',32);
$claims=[
    'site_url'=>$site,'site_id'=>$siteId,'account_id'=>'42','plan'=>'pro',
    'limit'=>2000,'used'=>7,'reserved'=>3,'remaining'=>1990,
    'period'=>'2026-11-06','period_kind'=>'calendar_day_utc','quota_scheme'=>'tariffs_v2',
    'period_start_utc'=>'2026-11-06T00:00:00Z','period_end_utc'=>'2026-11-07T00:00:00Z',
    'policy_version'=>'tariffs-v3-1','timezone'=>'UTC',
];
$context=usageContext($public,$keyId,$site,'42');
$response=usageSigned($claims,$secret,$now,$site,$siteId);

$valid=OAI_Cloud_Usage::verify($response,$context,$now);
usageAssert($valid['valid']===true&&$valid['reason']==='','Fresh signed projection rejected: '.$valid['reason']);
usageAssert($valid['claims']['remaining']===1990,'Projection claims not returned intact');

// Byte-level tampering of the signed payload.
$tampered=$response;$tampered['envelope']['payload']=usageEncode(str_replace('1990','9999',usageDecode($response['envelope']['payload'])));
usageAssert(OAI_Cloud_Usage::verify($tampered,$context,$now)['reason']==='signature','Tampered payload accepted');

// Envelope/key binding.
$wrongAlg=$response;$wrongAlg['envelope']['alg']='RS256';
usageAssert(OAI_Cloud_Usage::verify($wrongAlg,$context,$now)['reason']==='envelope','Non-Ed25519 envelope accepted');
$wrongKeyId=$response;$wrongKeyId['envelope']['key_id']=str_repeat('0',16);
usageAssert(OAI_Cloud_Usage::verify($wrongKeyId,$context,$now)['reason']==='key_id','Unexpected key id accepted');
$otherKeys=sodium_crypto_sign_keypair();
$wrongPublic=usageContext(usageEncode(sodium_crypto_sign_publickey($otherKeys)),$keyId,$site,'42');
usageAssert(OAI_Cloud_Usage::verify($response,$wrongPublic,$now)['reason']==='signature','Foreign public key accepted');

// Site and account isolation.
$otherSite=$response;$otherSite['site_url']='https://other.example.com';
usageAssert(OAI_Cloud_Usage::verify($otherSite,$context,$now)['reason']==='site_url','Foreign site_url accepted');
$otherSiteId=$response;$otherSiteId['site_id']='reg_'.str_repeat('b',32);
usageAssert(OAI_Cloud_Usage::verify($otherSiteId,$context,$now)['reason']==='site_id','Foreign site_id accepted');
usageAssert(OAI_Cloud_Usage::verify($response,usageContext($public,$keyId,$site,'43'),$now)['reason']==='account_id','Foreign account accepted');

// Freshness windows.
usageAssert(OAI_Cloud_Usage::verify($response,$context,$now+361)['reason']==='expired','Expired projection accepted');
usageAssert(OAI_Cloud_Usage::verify($response,$context,$now-61)['reason']==='not_yet_valid','Future-dated projection accepted');
$longClaims=$claims;$longResponse=usageSigned($longClaims,$secret,$now,$site,$siteId);
usageAssert(OAI_Cloud_Usage::verify($longResponse,$context,$now+301)['valid']===true,'Projection rejected inside its signed lifetime');
$slow=usageSigned($claims,$secret,$now-400,$site,$siteId,600);
usageAssert(OAI_Cloud_Usage::verify($slow,$context,$now)['reason']==='stale','Aged projection accepted as fresh');

// Server-calculated balance must be internally consistent.
$badRemaining=$claims;$badRemaining['remaining']=1;
usageAssert(OAI_Cloud_Usage::verify(usageSigned($badRemaining,$secret,$now,$site,$siteId),$context,$now)['reason']==='remaining','Inconsistent remaining accepted');
$badPeriod=$claims;$badPeriod['period_kind']='rolling_30d';
usageAssert(OAI_Cloud_Usage::verify(usageSigned($badPeriod,$secret,$now,$site,$siteId),$context,$now)['reason']==='claims','Unknown period kind accepted');
$badScheme=$claims;$badScheme['quota_scheme']='v1';
usageAssert(OAI_Cloud_Usage::verify(usageSigned($badScheme,$secret,$now,$site,$siteId),$context,$now)['reason']==='claims','Unknown quota scheme accepted');
$badLimit=$claims;$badLimit['limit']=0;$badLimit['remaining']=0;
usageAssert(OAI_Cloud_Usage::verify(usageSigned($badLimit,$secret,$now,$site,$siteId),$context,$now)['reason']==='claims','Zero-limit projection accepted');

// Garbage and legacy (unsigned) responses fail closed.
usageAssert(OAI_Cloud_Usage::verify(['contract'=>'wpai-site-usage-v1'],$context,$now)['reason']==='site','Malformed projection accepted');
usageAssert(OAI_Cloud_Usage::verify(['contract'=>'other'],$context,$now)['reason']==='contract','Unknown contract accepted');
usageAssert(OAI_Cloud_Usage::verify('not-an-array',$context,$now)['reason']==='contract','Non-array response accepted');
$garbage=$response;$garbage['envelope']['payload']='!!!';
usageAssert(OAI_Cloud_Usage::verify($garbage,$context,$now)['reason']==='signature','Invalid base64 payload accepted');

// Dashboard state: only verified server data is "available"; no local fallback.
$GLOBALS['wpai_test_options']=[];
$GLOBALS['wpai_credentials']=[];
class OAI_Gateway_Pairing { const CREDENTIAL_PREFIX='oai_pair_credential_'; }
class OAI_Quota { public static function status(){return ['plan'=>'free','limit'=>100,'used'=>3,'remaining'=>97,'label'=>'Free']; } }
class WPAI_Usage_DB { public $options='wp_options'; public function prepare($sql,...$args){return $sql;} public function esc_like($value){return addcslashes((string)$value,'_%\\');} public function get_col($sql){return $GLOBALS['wpai_credentials']; } }
$GLOBALS['wpdb']=new WPAI_Usage_DB();
function is_user_logged_in(){return true;}
function current_user_can($cap){return true;}
function get_current_user_id(){return 42;}
function get_option($key,$default=false){return $GLOBALS['wpai_test_options'][$key]??$default;}
function home_url($path=''){global $usage_home;return $usage_home.$path;}
$usage_home=$site;

$empty=OAI_Cloud_Usage::status();
usageAssert($empty['state']==='unpaired'&&$empty['available']===false&&$empty['projection']===null,'Unpaired dashboard fabricated server usage');
usageAssert(($empty['local']['used']??null)===3,'Local meter diagnostic removed');

$hash=str_repeat('c',64);$GLOBALS['wpai_credentials']=['oai_pair_credential_'.$hash];
$GLOBALS['wpai_test_options']['oai_pair_credential_'.$hash]=['user_id'=>42,'account_id'=>'42','account_label'=>'Owner','created_at'=>$now,
    'usage_projection'=>'wpai-site-usage-v1','status_key'=>str_repeat('d',64),
    'signing_public_key'=>$public,'signing_key_id'=>$keyId];

$projection=$valid['claims'];
$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]=['fetched_at'=>time(),'verified_at'=>time(),'valid'=>true,'reason'=>'',
    'projection'=>$projection,'site_url'=>$site,'site_id'=>$siteId,'account_id'=>'42','retry_at'=>0];
$verified=OAI_Cloud_Usage::status();
usageAssert($verified['state']==='verified'&&$verified['available']===true,'Verified server projection not displayed');
usageAssert($verified['projection']['remaining']===1990&&$verified['projection']['plan']==='pro','Dashboard used a non-server balance');

$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]['valid']=false;
$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]['reason']='signature';
$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]['retry_at']=time()+60;
$invalid=OAI_Cloud_Usage::status();
usageAssert($invalid['state']==='unknown'&&$invalid['available']===false&&$invalid['projection']===null,'Invalid signature fell back to a number');

$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]['valid']=true;
$GLOBALS['wpai_test_options']['oai_cloud_usage_'.substr($hash,0,32)]['verified_at']=time()-3600;
$stale=OAI_Cloud_Usage::status();
usageAssert($stale['state']==='stale'&&$stale['available']===true,'Old verified projection not labelled stale');

echo "PASS 38 cloud usage projection assertions\n";
