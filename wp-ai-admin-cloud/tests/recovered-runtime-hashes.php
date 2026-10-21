<?php
declare(strict_types=1);
/**
 * Immutable recovery evidence: verifies the exact 20 executable/configuration files
 * from the original 0.3.11 ZIP. README is intentionally omitted (Git audit text).
 * A later approved code change must create a NEW documented release baseline.
 */
$root=dirname(__DIR__);
$manifest=file($root.'/docs/RECOVERED-CLOUD-RUNTIME.sha256',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($manifest)||count($manifest)!==20)throw new RuntimeException('Unexpected recovered source manifest');
$seen=[];
foreach($manifest as $line){
    if(!preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9_\/.-]+)$/D',$line,$match))throw new RuntimeException('Invalid manifest entry');
    [$_,$sha,$path]=$match;
    if(str_contains($path,'..')||str_starts_with($path,'/')||isset($seen[$path]))throw new RuntimeException('Unsafe/duplicate manifest entry');
    $seen[$path]=true;
    $file=$root.'/'.$path;
    if(!is_file($file)||!hash_equals($sha,hash_file('sha256',$file)))throw new RuntimeException('Source archive mismatch: '.$path);
}
if(!isset($seen['src/Core.php'],$seen['src/Store.php'],$seen['src/OAuth.php'],$seen['src/QuotaPolicy.php'],$seen['src/BatchAccounting.php'],$seen['config/plans.json']))throw new RuntimeException('Critical Cloud source missing');
echo 'PASS 20/20 recovered Cloud 0.3.11 runtime SHA-256 assertions; README excluded by design'."\n";
