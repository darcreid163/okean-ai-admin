<?php
/**
 * AI Admin static safety audit.
 * Runs without WordPress and checks source-level invariants before release.
 */
$root=dirname(__DIR__);
$errors=array(); $warnings=array(); $checked=0;
function fail_audit($m){global $errors;$errors[]=$m;}
function warn_audit($m){global $warnings;$warnings[]=$m;}
function read_audit($p){$x=@file_get_contents($p);if($x===false)fail_audit("Cannot read: ".$p);return (string)$x;}
$php_files=array();
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $file){
 $p=$file->getPathname();
 if($file->isFile() && substr($p,-4)==='.php' && strpos($p.DIRECTORY_SEPARATOR,'.git'.DIRECTORY_SEPARATOR)===false)$php_files[]=$p;
}
sort($php_files);
foreach($php_files as $p){
 $checked++;
 $cmd='php -l '.escapeshellarg($p).' 2>&1';
 exec($cmd,$out,$code);
 if($code!==0)fail_audit("PHP syntax error: ".str_replace($root.'/','',$p)." :: ".implode("\n",$out));
}
$plugin=read_audit($root.'/okean-ai-admin.php');
$sig=read_audit($root.'/BUILD_SIGNATURE.txt');
if(!preg_match('/Version:\s*([^\r\n]+)/',$plugin,$m))fail_audit('Plugin header Version is missing');
$header_version=$m[1]??'';
if(!preg_match("/define\('OAI_VERSION','([^']+)'\)/",$plugin,$m))fail_audit('OAI_VERSION is missing');
$oai_version=$m[1]??'';
if($header_version!==$oai_version)fail_audit("Version mismatch: header={$header_version}, OAI_VERSION={$oai_version}");
if(!preg_match("/define\('OAI_BUILD_ID','([^']+)'\)/",$plugin,$m))fail_audit('OAI_BUILD_ID is missing');
$build_id=$m[1]??'';
if(strpos($sig,$header_version)===false)fail_audit('BUILD_SIGNATURE.txt does not contain plugin version');
if($build_id!=='' && strpos($sig,$build_id)===false)fail_audit('BUILD_SIGNATURE.txt does not contain OAI_BUILD_ID');
$rest=read_audit($root.'/includes/class-oai-rest.php');
$dispatcher=read_audit($root.'/includes/class-oai-execution-dispatcher.php');
$cli=read_audit($root.'/includes/class-oai-wp-cli-execution.php');
$registry=read_audit($root.'/includes/class-oai-command-registry.php');
if(strpos(read_audit($root.'/includes/class-oai-workspace.php'),'public static function restore($id,$path,$exists,$content=')!==false)fail_audit('Workspace restore method contains a literal \\n escape instead of PHP newlines');
if(strpos(read_audit($root.'/includes/class-oai-workspace.php'),'strpos($real,$root_real)!==0')!==false)fail_audit('Workspace file boundary uses unsafe prefix-only realpath check');
if(strpos(read_audit($root.'/includes/class-oai-files.php'),"expected_sha256=''")===false)fail_audit('File writer is missing expected SHA-256 conflict guard');
if(strpos(read_audit($root.'/includes/class-oai-files.php'),'public static function delete($scope,$path,$expected_sha256=')===false)fail_audit('File delete path is missing SHA-256 conflict guard');
if(strpos(read_audit($root.'/includes/class-oai-mcp.php'),"permission_callback'=>array(__CLASS__,'permission')")===false)fail_audit('MCP route does not use unified authentication permission callback');
if(strpos($rest,"permission_callback'=>function(){return current_user_can('manage_options');}")!==false)fail_audit('REST route bypasses unified authentication with a direct current_user_can callback');
$mcp=read_audit($root.'/includes/class-oai-mcp.php');
if(strpos($mcp,"'operation_approve':")!==false && strpos($mcp,"Human approval is required in wp-admin")===false)fail_audit('MCP approve tool is not restricted to human wp-admin approval');
if(strpos(read_audit($root.'/includes/class-oai-agent.php'),"oai_agent_human_approval")===false)fail_audit('Agent lifecycle actions are not restricted to human wp-admin approval');
if(strpos($mcp,"methods'=>'GET','callback'=>array(__CLASS__,'info'),'permission_callback'=>'__return_true'")!==false)fail_audit('MCP GET info route is publicly exposed');
$required=array(
 'REST sandbox route'=>'register_rest_route($n,\'/sandbox\',',
 'Sandbox apply lifecycle'=>'if($action===\'apply\')',
 'Sandbox rollback lifecycle'=>'if($action===\'rollback\')',
 'Rollback conflict guard'=>'rollback_conflict_check',
 'Rollback double verification'=>'OAI_Verify::operation($id,$before,\'rollback\')',
 'Execution mode allowlist'=>"array('read','plan')",
 'WP-CLI direct write guard'=>'oai_cli_write_requires_lifecycle',
 'CLI planned writes'=>'planned_write_commands',
 'Registry execution modes'=>'execution_mode',
 'Gutenberg freeform mode'=>'freeform_html',
 'Woo composite resolver'=>"case 'woocommerce_product'"
);
foreach($required as $label=>$needle)if(strpos($rest.$dispatcher.$cli.$registry,$needle)===false)fail_audit("Missing safety invariant: ".$label);
if(preg_match('/mode\s*=\s*[\'\"]apply[\'\"]/', $dispatcher))warn_audit('Dispatcher contains an apply-mode literal; verify it is not an execution bypass.');
$direct_patterns=array(
 '/->save\s*\(/'=>'direct object save call',
 '/\bupdate_option\s*\(/'=>'direct option update',
 '/\bwp_update_post\s*\(/'=>'direct post update',
 '/\bfile_put_contents\s*\(/'=>'direct file write'
);
foreach($direct_patterns as $rx=>$label){
 $hits=0;
 foreach($php_files as $p){$c=read_audit($p);if(preg_match($rx,$c))$hits++;}
 if($hits)warn_audit("Found {$hits} PHP files containing {$label}; these are not automatically unsafe, but each write path must be sandbox-gated.");
}
echo "AI Admin static audit\n";
echo "PHP files checked: {$checked}\n";
echo "Errors: ".count($errors)."\n";
foreach($errors as $e)echo "ERROR: {$e}\n";
echo "Warnings: ".count($warnings)."\n";
foreach($warnings as $w)echo "WARN: {$w}\n";
if($errors)exit(1);
echo "RESULT: PASS\n";
