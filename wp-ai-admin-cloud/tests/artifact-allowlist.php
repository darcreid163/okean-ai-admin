<?php
declare(strict_types=1);
/** Validates staged Cloud ZIP contents against strict production runtime allowlist. */
$root=$argv[1]??'';
if(!is_dir($root))throw new RuntimeException('Missing staged Cloud directory');
$expected=['wp-ai-admin-cloud.php'];
foreach(['src','includes','config'] as $dir) {
    $source=dirname(__DIR__).'/'.$dir;
    if(!is_dir($source))throw new RuntimeException('Missing source directory '.$dir);
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
    foreach($it as $file) {
        if(!$file->isFile() || $file->isLink())throw new RuntimeException('Non-regular source file');
        $relative=$dir.'/'.substr($file->getPathname(),strlen($source)+1);
        if(!preg_match('/^[A-Za-z0-9_\/-]+\.php$/D',$relative))throw new RuntimeException('Non-PHP runtime file '.$relative);
        $expected[]=$relative;
    }
}
sort($expected);
$found=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file) {
    if(!$file->isFile() || $file->isLink())throw new RuntimeException('Non-regular artifact entry');
    $relative=substr($file->getPathname(),strlen($root)+1);
    $found[]=$relative;
    if(preg_match('/(?:^|\/)(?:\.git|\.env|tests|docs|vendor)(?:\/|$)/i',$relative))
        throw new RuntimeException('Forbidden production payload '.$relative);
}
sort($found);
if($found!==$expected)throw new RuntimeException('Cloud production allowlist mismatch: '.json_encode(array_values(array_diff($found,$expected))).' missing='.json_encode(array_values(array_diff($expected,$found))));
foreach($found as $file) {
    $data=file_get_contents($root.'/'.$file);
    if($file==='config/plans.json') { if(!is_array(json_decode((string)$data,true))) throw new RuntimeException('Invalid plans JSON'); } elseif($data===false || !str_starts_with($data,'<?php'))throw new RuntimeException('Invalid runtime PHP '.$file);
}
echo 'PASS Cloud runtime artifact allowlist: '.count($found)." files\n";
