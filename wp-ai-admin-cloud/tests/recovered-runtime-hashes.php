<?php
declare(strict_types=1);
/**
 * Recoverable evidence for the 0.3.11 artifact, plus an explicitly versioned
 * development delta. NEVER rewrite RECOVERED-CLOUD-RUNTIME.sha256 to conceal
 * modifications to the historic 0.3.11 release.
 */
$root=dirname(__DIR__);
$manifest=file($root.'/docs/RECOVERED-CLOUD-RUNTIME.sha256',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_array($manifest)||count($manifest)!==20)throw new RuntimeException('Unexpected recovered source manifest');
$intentional=['src/Gateway.php'=>true,'wp-ai-admin-cloud.php'=>true];
$seen=[];$base=0;$changed=0;
foreach($manifest as $line){
    if(!preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9_\/.-]+)$/D',$line,$match))
        throw new RuntimeException('Invalid manifest entry');
    [$_,$sha,$path]=$match;
    if(str_contains($path,'..')||str_starts_with($path,'/')||isset($seen[$path]))
        throw new RuntimeException('Unsafe/duplicate manifest entry');
    $seen[$path]=true;
    $file=$root.'/'.$path;
    if(!is_file($file))throw new RuntimeException('Recovered source file missing: '.$path);
    $matches=hash_equals($sha,hash_file('sha256',$file));
    if(isset($intentional[$path])){
        if($matches)throw new RuntimeException('Expected explicit changed candidate file is still historic 0.3.11: '.$path);
        ++$changed;
    }else{
        if(!$matches)throw new RuntimeException('Unexpected drift from recovered 0.3.11: '.$path);
        ++$base;
    }
}
if($base!==18||$changed!==2||
   !isset($seen['src/Core.php'],$seen['src/Store.php'],$seen['src/OAuth.php'],$seen['src/QuotaPolicy.php'],$seen['src/BatchAccounting.php'],$seen['config/plans.json']))
    throw new RuntimeException('Inconsistent recovered 0.3.11 baseline or unlisted code change');
$plugin=file_get_contents($root.'/wp-ai-admin-cloud.php');
$gateway=file_get_contents($root.'/src/Gateway.php');
if(!str_contains($plugin,"define('WPAI_CLOUD_VERSION', '0.3.12-dev')")||
   !str_contains($plugin,"* Version: 0.3.12-dev")||
   !str_contains($plugin,"'Skills','Unsplash','MediaPolicy','Gateway'")||
   !str_contains($gateway,"$"."tool('cloud_skills_list'")||
   !str_contains($gateway,"$"."tool('cloud_skill_load'")||
   !str_contains($gateway,"$"."tool('cloud_media_search_images'"))
    throw new RuntimeException('Candidate is not independently marked, safely bootstrapped, or complete');
echo "PASS: 18/18 unchanged 0.3.11 baseline SHA-256 + 2 explicitly changed 0.3.12-dev files; historic manifest not rewritten\n";
