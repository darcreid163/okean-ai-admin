<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Command_Normalizer {
 public static function normalize($command){
  if(is_array($command))return self::from_array($command);
  $t=OAI_Argument_Parser::tokenize((string)$command); if(!$t)return new WP_Error('oai_cli_empty','Command is empty',array('status'=>400));
  if(strtolower($t[0])==='wp')array_shift($t);
  if(!$t)return new WP_Error('oai_cli_empty','Command is empty',array('status'=>400));
  $f=OAI_Argument_Parser::flags($t); $a=$f['args']; $flags=$f['flags']; $family=sanitize_key($a[0]??'');$verb=sanitize_key($a[1]??'');$subverb=''; if($family===''||$verb==='')return new WP_Error('oai_cli_shape','Command must contain a command family and verb',array('status'=>400,'args'=>$a));
  if($family==='cron'&&isset($a[1],$a[2])){$verb='event';$subverb=sanitize_key($a[2]);$args=array_slice($a,3);}else{$args=array_slice($a,2);}
  if($family==='db'&&$verb==='query'){$args=array_slice($a,2);}
  if($family==='cache'&&$verb==='purge'){$args=array_slice($a,2);}
  return array('family'=>$family,'verb'=>$verb,'subverb'=>$subverb,'args'=>$args,'flags'=>$flags,'raw'=>$command);
 }
 private static function from_array($x){$family=sanitize_key((string)($x['family']??$x['command_family']??''));$verb=sanitize_key((string)($x['verb']??''));$args=is_array($x['args']??null)?array_values($x['args']):array();$flags=is_array($x['flags']??null)?$x['flags']:array();return array('family'=>$family,'verb'=>$verb,'subverb'=>sanitize_key((string)($x['subverb']??'')),'args'=>$args,'flags'=>$flags,'raw'=>$x);}
}
