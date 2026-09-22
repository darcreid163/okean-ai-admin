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
  if($tokenStarted) $out[]=$buf;
  return $out;
 }
 public static function flags($tokens){
  $pos=array();
  $flags=array();
  foreach((array)$tokens as $t){
   if(strpos($t,'--')===0){
    $x=substr($t,2);
    if(strpos($x,'=')!==false){
     list($k,$v)=explode('=',$x,2);
     $flags[sanitize_key($k)]=$v;
    }else{
     $flags[sanitize_key($x)]=true;
    }
   }else{
    $pos[]=$t;
   }
  }
  return array('args'=>$pos,'flags'=>$flags);
 }
}
