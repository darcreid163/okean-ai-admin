<?php
if ($argc<3) exit(2);
$root=rtrim($argv[1],'/\\');
$_SERVER['REMOTE_ADDR']='198.51.100.77';
require $root.'/wp-load.php';
$result=OAI_Rate_Limit::check($argv[2],10,60);
if($result===true){echo "ALLOW\n";exit(0);}
if(is_wp_error($result)&&$result->get_error_code()==='oai_rate_limited'){echo "BLOCK\n";exit(0);}
echo "ERROR ".(is_wp_error($result)?$result->get_error_code():'unexpected result')."\n";
exit(1);
