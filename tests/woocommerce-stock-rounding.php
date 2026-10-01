<?php
define('ABSPATH', __DIR__);
if(!class_exists('WP_Error')){
 class WP_Error{
  public function __construct($code='',$message='',$data=null){}
 }
}
require_once dirname(__DIR__).'/includes/class-oai-woocommerce.php';

$ref=new ReflectionClass('OAI_WooCommerce');
$m=$ref->getMethod('normalize_stock_amount');
$m->setAccessible(true);

$cases=[
 [43.26,43],
 [50.7,51],
 [0.5,1],
 [27.752,28],
 [75.88,76],
 [81.048,81],
 [203.719,204],
 [0,0],
 [12,12],
 ['1,5',2],
];

foreach($cases as [$input,$expected]){
 $actual=$m->invoke(null,$input);
 if($actual instanceof WP_Error){
  fwrite(STDERR,"Unexpected WP_Error for input ".var_export($input,true)."\n");
  exit(1);
 }
 if($actual!==$expected){
  fwrite(STDERR,"Rounding mismatch for ".var_export($input,true).": expected {$expected}, got ".var_export($actual,true)."\n");
  exit(1);
 }
}
echo "WooCommerce stock rounding contract OK\n";
