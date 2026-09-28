<?php
/** Авторские права Андриянов Н.Г. */
if (!defined('ABSPATH')) exit;
class OAI_Execution_Dispatcher {
 public static function dispatch($command,$mode='read'){ $mode=sanitize_key((string)$mode); if(!in_array($mode,array('read','plan'),true)) return new WP_Error('oai_execution_mode','Execution mode must be read or plan',array('status'=>400,'mode'=>$mode)); $r=OAI_Command_Registry::resolve($command);if(is_wp_error($r))return $r;$perm=OAI_Permission_Resolver::resolve($r);if(!$perm['allowed'])return new WP_Error('oai_forbidden','Administrator capability required',array('status'=>403));if($mode==='plan'||empty($r['read']))return OAI_WP_CLI_Execution::plan($r);return OAI_WP_CLI_Execution::execute($r);}
}
