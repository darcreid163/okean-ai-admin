<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Argument_Parser {
 public static function tokenize($s){
  $s=(string)$s;
  $len=strlen($s);
  $out=array();
  $buf='';
  $quote=null;
  $escaped=false;
  $tokenStarted=false;
  for($i=0;$i<$len;$i++){
   $ch=$s[$i];
   if($escaped){
    $buf.=$ch;
    $escaped=false;
    $tokenStarted=true;
    continue;
   }
   if($ch==='\\'){
    $escaped=true;
    $tokenStarted=true;
    continue;
   }
   if($quote!==null){
    if($ch===$quote){
     $quote=null;
    }else{
     $buf.=$ch;
    }
    $tokenStarted=true;
    continue;
   }
   if($ch==='"' || $ch==="'"){
    $quote=$ch;
    $tokenStarted=true;
    continue;
   }
   if(ctype_space($ch)){
    if($tokenStarted){
     $out[]=$buf;
     $buf='';
     $tokenStarted=false;
    }
    continue;
   }
   $buf.=$ch;
   $tokenStarted=true;
  }
  if($escaped) $buf.='\\';
  if($quote!==null)return new WP_Error('oai_cli_unclosed_quote','Unclosed quote in command',array('status'=>400));
  if($tokenStarted) $out[]=$buf;
  return $out;
 }
 public static function flags($tokens){
  $tokens=array_values((array)$tokens);
  $pos=array();
  $flags=array();
  $count=count($tokens);
  for($i=0;$i<$count;$i++){
   $t=(string)$tokens[$i];
   if(strpos($t,'--')===0){
    $x=substr($t,2);
    if($x==='')continue;
    if(strpos($x,'=')!==false){
     list($k,$v)=explode('=',$x,2);
     $flags[sanitize_key($k)]=$v;
     continue;
    }
    $key=sanitize_key($x);
    if($key!=='' && isset($tokens[$i+1]) && strpos((string)$tokens[$i+1],'--')!==0){
     $flags[$key]=(string)$tokens[++$i];
    }elseif($key!==''){
     $flags[$key]=true;
    }
   }else{
    $pos[]=$t;
   }
  }
  return array('args'=>$pos,'flags'=>$flags);
 }
}

