<?php
/**
 * Public monorepo security boundary: one Native candidate + one current Cloud.
 * This is a SOURCE gate, not the Native-only release export gate.
 */
declare(strict_types=1);
$root=dirname(__DIR__);$issues=[];$php=static fn(string $f)=>is_file($root.'/'.$f)?file_get_contents($root.'/'.$f):'';
$n=$php('okean-ai-admin.php');$c=$php('wp-ai-admin-cloud/wp-ai-admin-cloud.php');
if(!str_contains($n,"define('OAI_VERSION','2.1.17')"))$issues[]='Native must be 2.1.17';
if(!str_contains($c,"0.3.11"))$issues[]='Cloud must be 0.3.11';
if(!is_file($root.'/wp-ai-admin-cloud/config/plans.json'))$issues[]='Cloud quota policy missing';
if(is_dir($root.'/wp-ai-cloud'))$issues[]='duplicate legacy gateway directory';
if(is_dir($root.'/releases'))$issues[]='committed release binaries directory';
$required=['wp-ai-admin-cloud/src/QuotaPolicy.php','wp-ai-admin-cloud/src/BatchAccounting.php','wp-ai-admin-cloud/src/OAuth.php','wp-ai-admin-cloud/src/Core.php','wp-ai-admin-cloud/tests/quota-migration-v4.php','wp-ai-admin-cloud/tests/batch-accounting-v2.php','tests/repository-boundary.php'];
foreach($required as $f)if(!is_file($root.'/'.$f))$issues[]='required path absent: '.$f;
$extensions=['.zip','.sql','.sqlite','.pem','.p12','.pfx','.bak','.key','.env'];
$patterns=[
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{35,}/',
    '/github_pat_[A-Za-z0-9_]{20,}/',
    '/(?:sk_live|rk_live)_[A-Za-z0-9]{12,}/',
];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){
    if(!$file->isFile())continue;
    $path=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    if(str_starts_with($path,'.git/'))continue;
    foreach($extensions as $ext)if(str_ends_with(strtolower($path),$ext))$issues[]='forbidden public file type: '.$path;
    if($file->getSize()>3000000)continue;
    $content=file_get_contents($file->getPathname());if($content===false)continue;
    foreach($patterns as $i=>$pattern)if(preg_match($pattern,$content))$issues[]='credential-like pattern '.$i.' in '.$path;
}
if($issues){fwrite(STDERR,"PUBLIC SOURCE GATE FAILED\n".implode("\n",$issues)."\n");exit(1);}
echo "PASS: single Native 2.1.17 / Cloud 0.3.11 candidate, no old gateway, no archive or secret signatures\n";
