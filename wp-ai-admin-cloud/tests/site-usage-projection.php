<?php
declare(strict_types=1);
/**
 * #135 server usage projection: the paired site reads only its own owner's
 * signed usage, and the projection matches the authoritative ledger exactly.
 * Synthetic sites/credentials only; no WordPress or network I/O.
 */
require __DIR__.'/bootstrap.php';
use WPAICloudGateway\{Core,Vault,Signer,Fault};

$checks=0;
function usageCheck(bool $ok,string $message): void { global $checks; ++$checks; if(!$ok)throw new RuntimeException($message); }
function usageReject(callable $fn,string $reason): void {
    try{$fn();}catch(Fault $e){usageCheck($e->reason===$reason,'Expected '.$reason.', got '.$e->reason);return;}
    throw new RuntimeException('Missing fault: '.$reason);
}
function usageB64d(string $value): string {
    $padding=(4-strlen($value)%4)%4;
    $bytes=base64_decode(strtr($value,'-_','+/').str_repeat('=',$padding),true);
    if($bytes===false)throw new RuntimeException('Invalid projection encoding');
    return $bytes;
}
function usageClaims(array $projection): array {
    usageCheck(($projection['contract']??null)==='wpai-site-usage-v1','Projection contract changed');
    $envelope=$projection['envelope']??null;
    usageCheck(is_array($envelope)&&($envelope['alg']??null)==='Ed25519','Projection envelope changed');
    return json_decode(usageB64d((string)$envelope['payload']),true,32,JSON_THROW_ON_ERROR);
}
function usageSignatureValid(array $projection,Core $core): bool {
    $payload=(string)$projection['envelope']['payload'];
    return sodium_crypto_sign_verify_detached(
        usageB64d((string)$projection['envelope']['signature']),
        "OAI-SITE-USAGE-V1\n".$payload,
        usageB64d((string)$core->signingPublicKey())
    );
}

$now=strtotime('2026-11-06T12:00:00Z');
$database=tempnam(sys_get_temp_dir(),'wpai-site-usage-');
if($database===false)throw new RuntimeException('No disposable database');
$pdo=new PDO(getenv('TEST_MYSQL_DSN')?:'sqlite:'.$database,getenv('TEST_MYSQL_USER')?:null,getenv('TEST_MYSQL_PASSWORD')?:null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$store=new WPAICloudGateway\Store($pdo,'qa_usage_'.bin2hex(random_bytes(5)).'_');
$store->migrate();
try {
$policy=json_decode((string)file_get_contents(dirname(__DIR__).'/config/plans.json'),true,32,JSON_THROW_ON_ERROR);
$pair=sodium_crypto_sign_keypair();
$signer=new Signer(sodium_crypto_sign_secretkey($pair));
$core=new Core($store,new Vault(str_repeat('k',32)),new FakeTransport(),$policy,$signer);
$core->ensureAccount('1');$core->ensureAccount('2');

$homeA='https://usage-a.example.com';
$keyA=hash('sha256','site-a-status-seed');
$tokenA='fixture-usage-credential-owner-a';
$siteA=$core->connect('1',$homeA,$tokenA,$now,false,hash('sha256',$keyA));

$homeB='https://usage-b.example.com';
$keyB=hash('sha256','site-b-status-seed');
$siteB=$core->connect('2',$homeB,'fixture-usage-credential-owner-b',$now,false,hash('sha256',$keyB));

// Signing identity is published for pair-time pinning.
usageCheck(is_string($core->signingPublicKey())&&strlen(usageB64d($core->signingPublicKey()))===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,'Signing public key missing');
usageCheck($core->signingKeyId()===$signer->keyId()&&preg_match('/^[a-f0-9]{16}$/D',(string)$core->signingKeyId())===1,'Signing key id mismatch');

// The sealed site credential never stores the read key in cleartext.
$sealed=(string)$store->row('SELECT token_sealed FROM '.$store->table('sites').' WHERE id=?',[$siteA['id']])['token_sealed'];
usageCheck($sealed!==''&&!str_contains($sealed,$keyA)&&!str_contains($sealed,$tokenA),'Site read key stored in cleartext');

$projection=$core->siteUsage($homeA,$keyA,$now);
$claims=usageClaims($projection);
usageCheck(usageSignatureValid($projection,$core),'Projection signature invalid');
usageCheck(($projection['envelope']['key_id']??null)===$core->signingKeyId(),'Projection signed by an unexpected key');
usageCheck(($projection['site_url']??null)===$homeA&&($projection['site_id']??null)===$siteA['id'],'Projection site binding changed');
usageCheck($claims['contract_version']===1&&$claims['issuer']==='okean-cloud-gateway'&&$claims['audience']==='wp-ai-admin-native','Projection claims contract changed');
usageCheck($claims['site_url']===$homeA&&$claims['site_id']===$siteA['id']&&$claims['account_id']==='1','Projection claim binding changed');
usageCheck($claims['plan']==='free'&&$claims['quota_scheme']==='tariffs_v2'&&$claims['period_kind']==='calendar_day_utc','Projection tariff claims changed');
usageCheck($claims['timezone']==='UTC'&&$claims['period']===gmdate('Y-m-d',$now),'Projection period is not the UTC calendar day');
usageCheck($claims['issued_at']===$now&&$claims['expires_at']===$now+300,'Projection freshness window changed');
usageCheck($claims['policy_version']===$policy['version'],'Projection policy version mismatch');

// The projection reconciles with the authoritative ledger after real usage.
usageCheck($claims['limit']===(int)$core->usage('1',$now)['limit'],'Projection limit differs from ledger');
$actor=$core->authenticate($core->mint('1',[$siteA['id']],['mcp:read','mcp:write'],$now)['access_token'],$now);
$core->call($actor,$siteA['id'],'wp_get_site',[],'usage-projection-nonce-1',$now);
$ledger=$core->usage('1',$now);
usageCheck($ledger['used']===1,'Fixture usage was not recorded');
$after=$core->siteUsage($homeA,$keyA,$now+1);
$afterClaims=usageClaims($after);
foreach(['limit','used','reserved','remaining','period','period_kind','quota_scheme','policy_version'] as $field)
    usageCheck($afterClaims[$field]===$ledger[$field],'Projection '.$field.' differs from the ledger');
usageCheck($afterClaims['remaining']===$afterClaims['limit']-$afterClaims['used']-$afterClaims['reserved'],'Projection remaining is not derived by the server');

// Nothing credential-shaped leaks through the read-only projection.
$wire=(string)json_encode($projection);
usageCheck(!str_contains($wire,$tokenA)&&!str_contains($wire,$keyA),'Projection leaked the site credential');

// Tampering with the signed payload invalidates the signature.
$tampered=$projection;
$claims['remaining']=999999;
$tampered['envelope']['payload']=rtrim(strtr(base64_encode(json_encode($claims)),'+/','-_'),'=');
usageCheck(!usageSignatureValid($tampered,$core),'Tampered projection still verifies');

// Site/owner fencing: one site can never read another site's or account's usage.
usageReject(fn()=>$core->siteUsage($homeA,$keyB,$now),'site_usage_denied');
usageReject(fn()=>$core->siteUsage($homeB,$keyA,$now),'site_usage_denied');
usageReject(fn()=>$core->siteUsage($homeA,hash('sha256','wrong-status-key'),$now),'site_usage_denied');
usageReject(fn()=>$core->siteUsage('https://unregistered.example.com',$keyA,$now),'site_usage_denied');
usageReject(fn()=>$core->siteUsage($homeA,'not-a-hash',$now),'site_usage_denied');
usageReject(fn()=>$core->connect('1','https://usage-c.example.com','fixture-usage-credential-owner-c',$now,false,'not-a-hash'),'invalid_site_status_hash');

// A legacy registration without a negotiated read key cannot read usage.
$homeC='https://usage-legacy.example.com';
$core->connect('1',$homeC,'fixture-usage-legacy-credential',$now);
usageReject(fn()=>$core->siteUsage($homeC,$keyA,$now),'site_usage_denied');

// Revocation and credential rotation both fence the old read key.
$homeD='https://usage-d.example.com';
$oldKey=hash('sha256','site-d-status-old');
$siteD=$core->connect('1',$homeD,'fixture-usage-credential-owner-d-old',$now,false,hash('sha256',$oldKey));
usageCheck(is_array($core->siteUsage($homeD,$oldKey,$now)),'Freshly paired site cannot read usage');
$newKey=hash('sha256','site-d-status-new');
$core->connect('1',$homeD,'fixture-usage-credential-owner-d-new',$now+1,true,hash('sha256',$newKey));
usageReject(fn()=>$core->siteUsage($homeD,$oldKey,$now+2),'site_usage_denied');
usageCheck(is_array($core->siteUsage($homeD,$newKey,$now+2)),'Rotated read key rejected');
$core->disconnect('1',$siteD['id'],$now+3);
usageReject(fn()=>$core->siteUsage($homeD,$newKey,$now+4),'site_usage_denied');
usageCheck(is_array($core->siteUsage($homeA,$keyA,$now)),'Revoking one site broke another site');
usageCheck(is_array($core->siteUsage($homeB,$keyB,$now)),'Revoking one site broke another account');

/** @var Core $core */
echo 'PASS '.$checks.' site usage projection assertions'.PHP_EOL;
} finally {
    if(!getenv('TEST_MYSQL_DSN')) @unlink($database);
}
