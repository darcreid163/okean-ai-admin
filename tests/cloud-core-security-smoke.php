<?php
declare(strict_types=1);
require __DIR__.'/../wp-ai-admin-cloud/src/Security.php';
require __DIR__.'/../wp-ai-admin-cloud/src/Core.php';
use WPAICloudGateway\{Security,Fault,Vault,Core};
$checks=0;$fails=[];
function ok(bool $p,string $n):void{global $checks,$fails;$checks++;if(!$p)$fails[]=$n;}
function denied(callable $c,string $label):void{try{$c();ok(false,$label);}catch(Fault $e){ok(true,$label);}}
ok(Security::home('https://Example.COM/')==='https://example.com','canonical_https');
foreach(['http://example.com','https://127.0.0.1','https://user:pass@example.com','https://example.com?secret=x','https://example.com:8080','https://example.com/#fragment'] as $u)denied(fn()=>Security::home($u),'reject_site_'.$u);
foreach(['127.0.0.1','10.0.0.1','192.168.1.1','169.254.1.1','100.64.0.1','192.0.2.1','198.51.100.1','203.0.113.1','224.0.0.1','::1','2001:db8::1'] as $ip)ok(!Security::publicIp($ip),'reject_ip_'.$ip);
ok(Security::publicIp('8.8.8.8'),'allow_public_ip');
$key=random_bytes(32);$v=new Vault($key);$sealed=$v->seal('credential','site:ownerA:siteA');ok($v->open($sealed,'site:ownerA:siteA')==='credential','vault_roundtrip');denied(fn()=>$v->open($sealed,'site:ownerB:siteA'),'vault_owner_bound');denied(fn()=>$v->open($sealed,'site:ownerA:siteB'),'vault_site_bound');
ok(Core::units('operation.verify')===0,'verify_free');ok(Core::units('operation.rollback')===0,'rollback_free');ok(Core::units('wp.get_site')===1,'site_read_metered');ok(Core::writes('wp.get_site',[])===false,'site_read_safe');ok(Core::writes('woocommerce.patch',[])===true,'woo_patch_write');ok(Core::writes('wordpress.execute',['mode'=>'read'])===false,'universal_read');ok(Core::writes('wordpress.execute',['mode'=>'write'])===true,'universal_write');
echo json_encode(['pass'=>$checks-count($fails),'fail'=>count($fails),'checks'=>$checks,'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";exit($fails?1:0);
