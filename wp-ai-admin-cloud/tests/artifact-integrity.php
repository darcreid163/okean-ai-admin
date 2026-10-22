<?php
declare(strict_types=1);
/** Adversarial packaging regression; all payloads are disposable copies. */
$source=dirname(__DIR__);
$root=sys_get_temp_dir().'/wpai-artifact-'.bin2hex(random_bytes(8));
mkdir($root,0700);
$remove=static function(string $path)use(&$remove):void {
    if(is_link($path)||!is_dir($path)){unlink($path);return;}
    foreach(array_diff(scandir($path),['.','..']) as $name)$remove($path.'/'.$name);
    rmdir($path);
};
$copy=static function(string $from,string $to)use(&$copy):void {
    if(is_dir($from)){mkdir($to);foreach(array_diff(scandir($from),['.','..']) as $name)$copy($from.'/'.$name,$to.'/'.$name);}
    else copy($from,$to);
};
$checks=0;
$validate=static function(string $path,bool $pass,string $reason='')use($source,&$checks):void {
    $process=proc_open([PHP_BINARY,$source.'/tests/artifact-allowlist.php',$path],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
    if(($status===0)!==$pass || (!$pass && !str_contains($out.$err,$reason)))throw new RuntimeException('Unexpected artifact validation: '.$out.$err);
    ++$checks;
};
try{
    $stage=$root.'/stage';mkdir($stage);
    foreach(['wp-ai-admin-cloud.php','src','includes','config'] as $name)$copy($source.'/'.$name,$stage.'/'.$name);
    $validate($stage,true);$validate($stage.'/',true);
    $core=$stage.'/src/Core.php';$original=file_get_contents($core);
    file_put_contents($core,$original."\n// post-checkout tamper\n");
    $validate($stage,false,'source SHA-256 mismatch');file_put_contents($core,$original);
    $plans=$stage.'/config/plans.json';$original=file_get_contents($plans);
    file_put_contents($plans,json_encode(['version'=>'changed','plans'=>['free'=>999999]]));
    $validate($stage,false,'source SHA-256 mismatch');file_put_contents($plans,$original);
    unlink($core);$validate($stage,false,'allowlist mismatch');copy($source.'/src/Core.php',$core);
    file_put_contents($stage.'/src/unexpected.php','<?php');$validate($stage,false,'allowlist mismatch');unlink($stage.'/src/unexpected.php');
    mkdir($stage.'/src/empty');$validate($stage,false,'allowlist mismatch');rmdir($stage.'/src/empty');
    symlink($source.'/src/Core.php',$stage.'/src/linked.php');$validate($stage,false,'Linked runtime entry');unlink($stage.'/src/linked.php');
    symlink($root,$stage.'/src/linked-directory');$validate($stage,false,'Linked runtime entry');unlink($stage.'/src/linked-directory');
    symlink($root.'/absent',$stage.'/src/broken');$validate($stage,false,'Linked runtime entry');unlink($stage.'/src/broken');
    symlink($stage,$root.'/linked-stage');$validate($root.'/linked-stage',false,'linked staged');unlink($root.'/linked-stage');
    mkdir($stage.'/docs');$validate($stage,false,'Forbidden production payload');rmdir($stage.'/docs');
    $validate($source,false,'separate from source');
    $validate($stage,true);
    echo "PASS $checks Cloud artifact parity/tampering/symlink/directory boundaries\n";
}finally{$remove($root);}
