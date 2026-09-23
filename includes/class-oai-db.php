<?php
/**
 * Авторские права Андриянов Н.Г.
 */
if (!defined('ABSPATH')) exit;
class OAI_DB {
 public static function table(){global $wpdb; return $wpdb->prefix.'oai_operations';}
 public static function audit_table(){global $wpdb; return $wpdb->prefix.'oai_audit_log';}
 public static function quota_table(){global $wpdb; return $wpdb->prefix.'oai_quota_usage';}
 public static function install(){global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php'; $t=self::table(); $charset=$wpdb->get_charset_collate(); $sql="CREATE TABLE $t (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 operation_id varchar(64) NOT NULL,
 status varchar(20) NOT NULL,
 type varchar(64) NOT NULL,
 target text NOT NULL,
 before_data longtext NULL,
 after_data longtext NULL,
 applied_data longtext NULL,
 meta_data longtext NULL,
 diff_data longtext NULL,
 risk varchar(20) NOT NULL DEFAULT 'low',
 receipt longtext NULL,
 proof longtext NULL,
 created_at datetime NOT NULL,
 approved_at datetime NULL,
 rejected_at datetime NULL,
 applied_at datetime NULL,
 rolled_back_at datetime NULL,
 phase varchar(32) NULL,
 workspace_id varchar(64) NULL,
 rollback_data longtext NULL,
 expires_at datetime NULL,
 PRIMARY KEY (id), UNIQUE KEY operation_id (operation_id), KEY status (status), KEY created_at (created_at)
) $charset;"; dbDelta($sql);
 $a=self::audit_table();
 $q=self::quota_table();
 $qsql="CREATE TABLE $q (
 plan varchar(32) NOT NULL,
 period_key varchar(32) NOT NULL,
 used bigint(20) unsigned NOT NULL DEFAULT 0,
 updated_at datetime NOT NULL,
 PRIMARY KEY (plan,period_key)
 ) $charset;";
 dbDelta($qsql);
 $asql="CREATE TABLE $a (
 id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
 operation_id varchar(64) NOT NULL,
 operation_db_id bigint(20) unsigned NULL,
 event varchar(32) NOT NULL,
 status varchar(20) NULL,
 actor bigint(20) unsigned NULL,
 target text NULL,
 details longtext NULL,
 created_at datetime NOT NULL,
 PRIMARY KEY (id), KEY operation_id (operation_id), KEY event (event), KEY created_at (created_at)
 ) $charset;";
 dbDelta($asql); update_option('oai_db_version',OAI_DB_VERSION); if(class_exists('OAI_MCP_OAuth')) OAI_MCP_OAuth::install(); self::migrate_token(); }
 public static function maybe_upgrade(){if((int)get_option('oai_db_version',0)<OAI_DB_VERSION) self::install(); else { if(class_exists('OAI_MCP_OAuth')) OAI_MCP_OAuth::install(); self::migrate_token(); }}
 public static function migrate_token(){ $old=get_option('oai_api_token',''); $new=get_option('oai_bearer_token',''); if(!$new && $old) update_option('oai_bearer_token',$old,false); }
 public static function insert($data){global $wpdb; $t=self::table(); $ok=$wpdb->insert($t,$data); if(!$ok) return new WP_Error('oai_db','Could not create operation',array('status'=>500,'db_error'=>$wpdb->last_error)); return (int)$wpdb->insert_id;}
 public static function get($id){global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d',$id),ARRAY_A);}
 public static function update($id,$data){global $wpdb; return $wpdb->update(self::table(),$data,array('id'=>$id));}
 public static function all($limit=200){global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::table().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);}
 public static function audit_insert($data){global $wpdb; return $wpdb->insert(self::audit_table(),$data);}
 public static function audit_all($limit=200){global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.self::audit_table().' ORDER BY id DESC LIMIT %d',$limit),ARRAY_A);}
}