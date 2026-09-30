<?php
if (!defined('ABSPATH')) exit;

class WPAIC_DB {
 const OPTION='wpaic_db_version';

 public static function workspaces_table(){global $wpdb;return $wpdb->prefix.'wpai_cloud_workspaces';}
 public static function sites_table(){global $wpdb;return $wpdb->prefix.'wpai_cloud_sites';}
 public static function pairings_table(){global $wpdb;return $wpdb->prefix.'wpai_cloud_pairings';}

 public static function install(){
  global $wpdb;
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  $charset=$wpdb->get_charset_collate();

  dbDelta("CREATE TABLE ".self::workspaces_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   workspace_id varchar(64) NOT NULL,
   owner_user_id bigint(20) unsigned NOT NULL,
   name varchar(190) NOT NULL,
   status varchar(20) NOT NULL DEFAULT 'active',
   created_at datetime NOT NULL,
   updated_at datetime NOT NULL,
   PRIMARY KEY (id),
   UNIQUE KEY workspace_id (workspace_id),
   KEY owner_user_id (owner_user_id),
   KEY status (status)
  ) $charset;");

  dbDelta("CREATE TABLE ".self::sites_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   site_id varchar(64) NOT NULL,
   installation_id varchar(128) NOT NULL,
   registration_id varchar(64) NOT NULL,
   workspace_id varchar(64) NULL,
   domain varchar(190) NOT NULL,
   display_name varchar(190) NULL,
   plugin_version varchar(32) NULL,
   catalog_sha256 char(64) NULL,
   status varchar(20) NOT NULL DEFAULT 'unclaimed',
   credential_hash char(64) NULL,
   created_at datetime NOT NULL,
   updated_at datetime NOT NULL,
   last_seen_at datetime NULL,
   revoked_at datetime NULL,
   PRIMARY KEY (id),
   UNIQUE KEY site_id (site_id),
   UNIQUE KEY installation_id (installation_id),
   UNIQUE KEY registration_id (registration_id),
   KEY workspace_id (workspace_id),
   KEY status (status)
  ) $charset;");

  dbDelta("CREATE TABLE ".self::pairings_table()." (
   id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
   pairing_id varchar(64) NOT NULL,
   site_id varchar(64) NOT NULL,
   installation_id varchar(128) NOT NULL,
   exchange_hash char(64) NOT NULL,
   claim_code_hash char(64) NOT NULL,
   workspace_id varchar(64) NULL,
   status varchar(20) NOT NULL DEFAULT 'pending',
   created_at datetime NOT NULL,
   expires_at datetime NOT NULL,
   claimed_at datetime NULL,
   claimed_by_user_id bigint(20) unsigned NULL,
   exchanged_at datetime NULL,
   PRIMARY KEY (id),
   UNIQUE KEY pairing_id (pairing_id),
   KEY site_id (site_id),
   KEY installation_id (installation_id),
   KEY status (status),
   KEY expires_at (expires_at)
  ) $charset;");

  update_option(self::OPTION,WPAIC_DB_VERSION,false);
  return true;
 }

 public static function maybe_upgrade(){
  if((int)get_option(self::OPTION,0)!==WPAIC_DB_VERSION)self::install();
 }
}
