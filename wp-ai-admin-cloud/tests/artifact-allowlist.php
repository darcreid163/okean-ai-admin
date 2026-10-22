<?php
declare(strict_types=1);
/** Require exact checked-out production paths AND bytes; inspect directories too. */
$input=$argv[1]??'';
if($input==='' || is_link(rtrim($input,'/')) || !is_dir($input))throw new RuntimeException('Missing or linked staged Cloud directory');
$root=realpath($input);
$source=realpath(dirname(__DIR__));
if($root===$source)throw new RuntimeException('Stage must be separate from source');
$collect=static function(string $base,array $paths): array {
    $entries=[];
    $record=static function(string $path,string $relative)use(&$entries):void {
        if(is_link($path))throw new RuntimeException('Linked runtime entry '.$relative);
        if(preg_match('/(?:^|\/)(?:\.git|\.env|tests|docs|vendor)(?:\/|$)/i',$relative))throw new RuntimeException('Forbidden production payload '.$relative);
        if(is_dir($path)){$entries[$relative.'/']='directory';return;}
        if(!is_file($path))throw new RuntimeException('Non-regular runtime entry '.$relative);
        if(!str_ends_with($relative,'.php') && $relative!=='config/plans.json')throw new RuntimeException('Unexpected runtime file '.$relative);
        $entries[$relative]=hash_file('sha256',$path);
    };
    foreach($paths as $relative){
        $path=$base.'/'.$relative;
        $record($path,$relative);
        if(is_dir($path)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach($it as $file)$record($file->getPathname(),substr($file->getPathname(),strlen($base)+1));
        }
    }
    ksort($entries,SORT_STRING);
    return $entries;
};
$expected=$collect($source,['wp-ai-admin-cloud.php','src','includes','config']);
$found=$collect($root,array_values(array_diff(scandir($root),['.','..'])));
if(array_keys($found)!==array_keys($expected))throw new RuntimeException('Cloud production allowlist mismatch');
foreach($expected as $path=>$hash)if(!hash_equals($hash,$found[$path]))throw new RuntimeException('Cloud source SHA-256 mismatch: '.$path);
echo 'PASS Cloud runtime artifact source paths and SHA-256: '.count(array_filter($found,static fn($hash)=>$hash!=='directory'))." files\n";
