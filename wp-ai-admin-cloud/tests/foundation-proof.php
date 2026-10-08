<?php
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use WPAICloudGateway\{Fault};

$checks=0;
function fpcheck(bool $ok,string $m): void { global $checks; $checks++; if(!$ok) throw new RuntimeException($m); }
function fpreject(callable $fn,string $reason): void { try{$fn();}catch(Fault $e){fpcheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}throw new RuntimeException('Expected '.$reason); }
function fpdecode(string $v): string { $v=strtr($v,'-_','+/'); $pad=strlen($v)%4; if($pad)$v.=str_repeat('=',4-$pad); $d=base64_decode($v,true); if($d===false)throw new RuntimeException('Invalid base64url'); return $d; }

$now=1791436800;
$db=sys_get_temp_dir().'/wpai-foundation-proof-'.bin2hex(random_bytes(6)).'.sqlite';
$store=fixtureStore($db);$store->migrate();
if(getenv('TEST_MYSQL_DSN'))foreach(['audit','requests','oauth_access','oauth_refresh','oauth_codes','oauth_grants','oauth_clients','tokens','sites','balances','accounts','rate'] as $t)$store->execute('DELETE FROM '.$store->table($t));
$transport=new FakeTransport();$core=fixtureCore($store,$transport,100);
$core->ensureAccount('foundation-owner');
$site=$core->connect('foundation-owner','https://foundation.example.com','fixture-foundation-site-token-aaaaaaaa',$now);

$proof=$core->foundation('foundation-owner',$site['id'],$now);
fpcheck(($proof['purpose']??'')==='v1_compatibility_proof_only','Foundation purpose overstates commercial entitlement');
fpcheck(($proof['commercial_entitlement_enforced']??true)===false,'Foundation incorrectly claims commercial entitlement enforcement');
$env=$proof['envelope']??[];
fpcheck(($env['alg']??'')==='Ed25519','Foundation algorithm mismatch');
fpcheck(preg_match('/^[a-f0-9]{16}$/',(string)($env['key_id']??''))===1,'Foundation key id malformed');
$payload=(string)($env['payload']??'');$sig=(string)($env['signature']??'');$pub=(string)($proof['public_key']??'');
fpcheck($payload!==''&&$sig!==''&&$pub!=='','Foundation envelope incomplete');
$payloadRaw=fpdecode($payload);$signature=fpdecode($sig);$publicKey=fpdecode($pub);
fpcheck(strlen($publicKey)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,'Foundation public key malformed');
fpcheck(sodium_crypto_sign_verify_detached($signature,"OAI-ENTITLEMENT-V1\n".$payload,$publicKey),'Foundation Ed25519 signature invalid');
$claims=json_decode($payloadRaw,true,32,JSON_THROW_ON_ERROR);
fpcheck(($claims['issuer']??'')==='okean-control-plane'&&($claims['audience']??'')==='wp-ai-admin','Foundation issuer/audience mismatch');
fpcheck(($claims['site_id']??'')===$site['plugin_site_id'],'Foundation site_id mismatch');
fpcheck(($claims['installation_id']??'')===$site['installation_id'],'Foundation installation_id mismatch');
fpcheck(($claims['registration_id']??'')===$site['id'],'Foundation registration_id mismatch');
fpcheck(($claims['issued_at']??0)===$now&&($claims['not_before']??0)===$now&&($claims['expires_at']??0)===$now+300,'Foundation time window mismatch');
fpcheck(($claims['plan']??'')==='free'&&($claims['active']??false)===true&&($claims['write_frozen']??true)===false,'Foundation compatibility claims changed');
fpcheck(($claims['quota']['period']??'')==='day'&&($claims['quota']['limit']??0)===100,'Legacy foundation quota must remain compatibility-only daily allowance');
$sequence0=(int)($claims['policy_sequence']??-1);

// Operator plan change increments sequence while proof still remains compatibility-only, not a paid-entitlement assertion.
$core->setPlan('operator','foundation-owner','pro',$now+1);
$proof2=$core->foundation('foundation-owner',$site['id'],$now+2);
$claims2=json_decode(fpdecode($proof2['envelope']['payload']),true,32,JSON_THROW_ON_ERROR);
fpcheck(($claims2['policy_sequence']??-1)===$sequence0+1,'Plan change did not increment foundation policy sequence');
fpcheck(($claims2['plan']??'')==='free'&&($proof2['commercial_entitlement_enforced']??true)===false,'Compatibility foundation started asserting commercial plan');

// Disconnect invalidates foundation lookup for the old registration and increments account sequence.
$core->disconnect('foundation-owner',$site['id'],$now+3);
fpreject(fn()=>$core->foundation('foundation-owner',$site['id'],$now+4),'site_unavailable');

// Wrong owner cannot obtain another tenant's proof.
$core->ensureAccount('other-owner');
fpreject(fn()=>$core->foundation('other-owner',$site['id'],$now+4),'site_unavailable');

// A core without a signer must fail closed.
$policy=json_decode(file_get_contents(dirname(__DIR__).'/config/plans.json'),true);
$unsigned=new WPAICloudGateway\Core($store,new WPAICloudGateway\Vault(str_repeat('u',32)),$transport,$policy,null);
$unsigned->ensureAccount('unsigned-owner');
$unsignedSite=$unsigned->connect('unsigned-owner','https://unsigned.example.com','fixture-unsigned-site-token-bbbbbbbb',$now+10);
fpreject(fn()=>$unsigned->foundation('unsigned-owner',$unsignedSite['id'],$now+11),'signer_not_configured');

echo 'PASS '.$checks.' foundation proof assertions; driver='.(getenv('TEST_MYSQL_DSN')?'mysql':'sqlite').PHP_EOL;
if(!getenv('TEST_MYSQL_DSN'))foreach([$db,$db.'-wal',$db.'-shm'] as $f)if(file_exists($f))unlink($f);
