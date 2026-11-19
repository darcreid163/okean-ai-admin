<?php
declare(strict_types=1);

/** Exercise the real CLI exporter against a disposable WordPress bootstrap. */
function keyExportAssert(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function keyExportRun(string $script,string $root,string $target): array {
    $pipes=[];
    $process=proc_open([PHP_BINARY,$script,$root,$target],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start key exporter subprocess');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]); $stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return ['exit'=>proc_close($process),'stdout'=>(string)$stdout,'stderr'=>(string)$stderr];
}
function keyExportRemove(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) if ($entry!=='.' && $entry!=='..') keyExportRemove($path.DIRECTORY_SEPARATOR.$entry);
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

$base=sys_get_temp_dir().DIRECTORY_SEPARATOR.'wpai-key-export-'.bin2hex(random_bytes(8));
$root=$base.DIRECTORY_SEPARATOR.'wordpress';
$private=$base.DIRECTORY_SEPARATOR.'private';
mkdir($root,0700,true); mkdir($private,0700,true);
$vault=random_bytes(32);
$signing=random_bytes(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
$vaultHex=bin2hex($vault); $signingHex=bin2hex($signing);
$bootstrap="<?php\nnamespace WPAICloudGateway;\nfinal class Settings { public static function keys(): array { return ['vault'=>hex2bin('".$vaultHex."'),'signing'=>hex2bin('".$signingHex."')]; } }\n";
file_put_contents($root.DIRECTORY_SEPARATOR.'wp-load.php',$bootstrap);
$script=dirname(__DIR__).DIRECTORY_SEPARATOR.'export-keys.php';
$target=$private.DIRECTORY_SEPARATOR.'gateway-keys.php';
try {
    $success=keyExportRun($script,$root,$target);
    keyExportAssert($success['exit']===0,'Exporter did not complete successfully: '.$success['stderr']);
    keyExportAssert(is_file($target),'Exporter did not create the key file');
    keyExportAssert(((fileperms($target) ?: 0)&0777)===0600,'Exported key file is not mode 0600');
    $contents=file_get_contents($target);
    $vaultEncoded=base64_encode($vault); $signingEncoded=base64_encode($signing);
    keyExportAssert(is_string($contents) && str_contains($contents,$vaultEncoded) && str_contains($contents,$signingEncoded),
        'Export file does not contain the exact existing key material');
    keyExportAssert(!str_contains($success['stdout'],$vaultEncoded) && !str_contains($success['stdout'],$signingEncoded)
        && !str_contains($success['stderr'],$vaultEncoded) && !str_contains($success['stderr'],$signingEncoded),
        'Exporter leaked key material to process output');

    $before=hash_file('sha256',$target);
    $again=keyExportRun($script,$root,$target);
    keyExportAssert($again['exit']!==0,'Exporter overwrote an existing destination');
    keyExportAssert(hash_equals((string)$before,(string)hash_file('sha256',$target)),
        'Existing key file changed after overwrite was refused');

    $inside=$root.DIRECTORY_SEPARATOR.'gateway-keys.php';
    $insideResult=keyExportRun($script,$root,$inside);
    keyExportAssert($insideResult['exit']!==0 && !file_exists($inside),
        'Exporter allowed key material to be written inside the WordPress web root');

    $relative='wpai-relative-key-export-'.bin2hex(random_bytes(4)).'.php';
    $relativeResult=keyExportRun($script,$root,$relative);
    keyExportAssert($relativeResult['exit']!==0 && !file_exists($relative),
        'Exporter accepted a relative output path');

    echo "PASS key-preservation exporter: exact key bytes, private 0600 file, no stdout leakage, no overwrite, web-root and relative-path rejection\n";
} finally {
    keyExportRemove($base);
}
