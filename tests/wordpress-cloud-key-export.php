<?php
if (!defined('WP_CLI') || !WP_CLI || !defined('OAI_DIR') || !class_exists('WPAICloudGateway\\Settings')) {
    throw new RuntimeException('Run through WP-CLI with the Native/Cloud plugin active');
}
function cloudKeyExportAssert(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
if (get_option('wpai_gateway_wrapped_keys_v1','')==='' && !defined('WP_AI_CLOUD_VAULT_KEY') && !defined('WP_AI_CLOUD_SIGNING_KEY')) {
    \\WPAICloudGateway\\Settings::prepareKeys();
}
$keys=\\WPAICloudGateway\\Settings::keys();
$vault=base64_encode($keys['vault']);
$signing=base64_encode($keys['signing']);
$script=OAI_DIR.'wp-ai-admin-cloud/tools/export-keys.php';
$root=rtrim(ABSPATH,'/\\\\');
$output=sys_get_temp_dir().'/wpai-cloud-keys-'.bin2hex(random_bytes(8)).'.php';
$run=static function(string $target) use($script,$root): array {
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($root).' '.escapeshellarg($target);
    $proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc)) throw new RuntimeException('Could not start key export process');
    fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($proc),$stdout,$stderr];
};
try {
    [$exit,$stdout,$stderr]=$run($output);
    cloudKeyExportAssert($exit===0 && is_file($output),'CLI key export did not create a private snippet: '.$stderr);
    cloudKeyExportAssert((fileperms($output)&0777)===0600,'Key export file permissions are not 0600');
    $snippet=(string)file_get_contents($output);
    cloudKeyExportAssert(str_contains($snippet,"define('WP_AI_CLOUD_VAULT_KEY', '$vault');"),'Exported vault key does not match the active key');
    cloudKeyExportAssert(str_contains($snippet,"define('WP_AI_CLOUD_SIGNING_KEY', '$signing');"),'Exported signing key does not match the active key');
    cloudKeyExportAssert(!str_contains($stdout,$vault) && !str_contains($stdout,$signing) && !str_contains($stderr,$vault) && !str_contains($stderr,$signing),'Key material leaked to process output');
    [$againExit]=$run($output);
    cloudKeyExportAssert($againExit!==0 && (string)file_get_contents($output)===$snippet,'Existing export file was overwritten');
    $inside=$root.'/wpai-key-export-rejected-'.bin2hex(random_bytes(6)).'.php';
    [$insideExit]=$run($inside);
    cloudKeyExportAssert($insideExit!==0 && !file_exists($inside),'Key export accepted a path inside the WordPress root');
    echo "PASS: Cloud key export preserves exact keys, enforces 0600, refuses overwrite/web-root paths, and redacts output\\n";
} finally {
    if(is_file($output)) @unlink($output);
}
