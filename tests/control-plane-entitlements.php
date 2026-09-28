<?php
/** Cryptographic contract tests; no WordPress bootstrap or network needed. */
define('ABSPATH', __DIR__.'/');
require dirname(__DIR__).'/includes/class-oai-control-plane.php';
if(!function_exists('sodium_crypto_sign_keypair'))throw new RuntimeException('Sodium is required for this test');
$pair=sodium_crypto_sign_keypair();
$public=sodium_crypto_sign_publickey($pair);
$private=sodium_crypto_sign_secretkey($pair);
$enc=function($bytes){return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');};
$now=1700000000;
$claims=array('contract_version'=>1,'issuer'=>'okean-control-plane','audience'=>'wp-ai-admin','site_id'=>'site-a','installation_id'=>'inst-a','registration_id'=>'reg-a','issued_at'=>$now-10,'not_before'=>$now-10,'expires_at'=>$now+300,'policy_sequence'=>7,'plan'=>'pro','active'=>true,'write_frozen'=>false,'features'=>array('read','mcp','sandbox'),'quota'=>array('limit'=>2000,'period'=>'day'));
$sign=function($value,$key)use($enc,$private){$payload=$enc(json_encode($value,JSON_UNESCAPED_SLASHES));return array('alg'=>'Ed25519','key_id'=>substr(hash('sha256',$key),0,16),'payload'=>$payload,'signature'=>$enc(sodium_crypto_sign_detached(OAI_Control_Plane::DOMAIN.$payload,$private)));};
$check=function($envelope,$key=null,$site='site-a',$install='inst-a',$reg='reg-a',$sequence=7,$time=null)use($public,$now,$enc){return OAI_Control_Plane::verify($envelope,$enc($key??$public),$site,$install,$reg,$sequence,$time??$now);};
$assert=function($condition,$message){if(!$condition)throw new RuntimeException($message);};
$good=$sign($claims,$public);
$assert($check($good)['valid']===true,'Valid entitlement rejected');
$modified=$good;$modified['payload']=$enc(json_encode(array_merge($claims,array('plan'=>'business'))));
$assert($check($modified)['reason']==='signature','Tampered plan accepted');
$assert($check($good,$public,'site-b')['reason']==='binding','Cross-site replay accepted');
$assert($check($good,$public,'site-a','inst-b')['reason']==='binding','Cross-installation replay accepted');
$assert($check($good,$public,'site-a','inst-a','reg-b')['reason']==='binding','Cross-registration replay accepted');
$assert($check($good,$public,'site-a','inst-a','reg-a',8)['reason']==='sequence','Policy downgrade accepted');
$assert($check($good,$public,'site-a','inst-a','reg-a',7,$now+301)['reason']==='time_invalid','Expired token accepted');
$wrong=sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
$assert($check($good,$wrong)['valid']===false,'Wrong key accepted');
foreach(array(
 'long_ttl'=>array('expires_at'=>$now+7200),
 'future'=>array('issued_at'=>$now+120,'not_before'=>$now+120,'expires_at'=>$now+300),
 'bad_feature'=>array('features'=>array('mcp','arbitrary_php')),
 'bad_quota'=>array('quota'=>array('limit'=>-1,'period'=>'day')),
 'bad_version'=>array('contract_version'=>2),
 'bad_sequence'=>array('policy_sequence'=>true),
) as $label=>$changes)$assert($check($sign(array_merge($claims,$changes),$public))['valid']===false,$label.' accepted');
echo "PASS: signed entitlement, tamper, binding, expiry, sequence, key, contract and policy negatives\n";
