<?php
/** OAuth schema migration on an actual MySQL database, with a small WordPress fixture. */
define('ABSPATH', __DIR__.'/fixtures/');
define('OAI_DB_VERSION', 23);
define('DAY_IN_SECONDS', 86400);
class WP_Error {
 public $code;
 public function __construct($code) { $this->code=$code; }
}
$oai_schema_options=array('oai_db_version'=>OAI_DB_VERSION,'oai_quota_events_pruned_at'=>time());
$oai_schema_dbdelta_calls=0;
$oai_schema_skip_table='';
function get_option($key,$default=false) { global $oai_schema_options; return $oai_schema_options[$key]??$default; }
function update_option($key,$value,$autoload=null) { global $oai_schema_options; $oai_schema_options[$key]=$value; return true; }
function current_time($type,$gmt=false) { return gmdate('Y-m-d H:i:s'); }
class OAI_Schema_Test_DB {
 public $prefix='oai_schema_test_';
 public $last_error='';
 private $conn;
 public function __construct() {
  $this->conn=new mysqli(getenv('DB_HOST'),getenv('DB_USER'),getenv('DB_PASSWORD'),getenv('DB_NAME'));
  if ($this->conn->connect_error) throw new RuntimeException($this->conn->connect_error);
 }
 public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
 public function esc_like($value) { return addcslashes($value, '_%\\'); }
 public function prepare($sql,...$values) { foreach($values as $value) $sql=preg_replace('/%s/', "'".$this->conn->real_escape_string($value)."'", $sql, 1); return $sql; }
 public function query($sql) {
  $result=$this->conn->query($sql);
  $this->last_error=$this->conn->error;
  return $result;
 }
 public function get_var($sql) {
  $result=$this->query($sql);
  if (!$result) throw new RuntimeException($this->last_error);
  $row=$result->fetch_row();
  return $row[0]??null;
 }
}
$wpdb=new OAI_Schema_Test_DB();
require dirname(__DIR__).'/includes/class-oai-db.php';
require dirname(__DIR__).'/includes/class-oai-mcp-oauth.php';
function assert_schema($condition,$message) { if (!$condition) throw new RuntimeException($message); }
foreach(array(OAI_MCP_OAuth::clients_table(),OAI_MCP_OAuth::codes_table(),OAI_MCP_OAuth::tokens_table(),OAI_MCP_OAuth::registry_table()) as $table) $wpdb->query('DROP TABLE IF EXISTS '.$table);
try {
 // An incomplete migration must not mark the schema current.
 $oai_schema_skip_table=OAI_MCP_OAuth::tokens_table();
 OAI_DB::maybe_upgrade();
 assert_schema((int)get_option('oai_oauth_schema_version',0)===0,'Incomplete schema was marked current');
 $oai_schema_skip_table='';
 OAI_DB::maybe_upgrade();
 assert_schema((int)get_option('oai_oauth_schema_version',0)===OAI_MCP_OAuth::SCHEMA_VERSION,'OAuth schema version not updated to the code-defined current version');
 assert_schema((string)$wpdb->get_var($wpdb->prepare('SELECT registry_key FROM '.OAI_MCP_OAuth::registry_table().' WHERE registry_key=%s','client_registration'))==='client_registration','OAuth registry lock row was not created');
 assert_schema(strtoupper((string)$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',OAI_MCP_OAuth::registry_table())))==='INNODB','OAuth registry mutex table must be InnoDB');
 assert_schema((int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND INDEX_NAME=%s',OAI_MCP_OAuth::tokens_table(),'refresh_expires_at'))>0,'Refresh expiry cleanup index is missing');
 assert_schema($oai_schema_dbdelta_calls===8,'Unexpected number of migration calls');
 $wpdb->query("INSERT INTO ".OAI_MCP_OAuth::clients_table()." (client_id,client_name,redirect_uris,created_at) VALUES ('retained-client','Retained','[]',UTC_TIMESTAMP())");
 assert_schema(!$wpdb->last_error,'Could not insert OAuth client');
 // Simulate an installation that still advertises the retired schema v1.
 // The normal upgrade path must migrate it to the code-defined current schema
 // without dropping registered OAuth clients or rerunning on later requests.
 update_option('oai_oauth_schema_version',OAI_MCP_OAuth::SCHEMA_VERSION-1,false);
 OAI_DB::maybe_upgrade();
 assert_schema((int)get_option('oai_oauth_schema_version',0)===OAI_MCP_OAuth::SCHEMA_VERSION,'Legacy OAuth schema was not upgraded to the current version');
 assert_schema((int)$wpdb->get_var("SELECT COUNT(*) FROM ".OAI_MCP_OAuth::clients_table()." WHERE client_id='retained-client'")===1,'Legacy OAuth upgrade lost a registered client');
 $migration_calls=$oai_schema_dbdelta_calls;
 OAI_DB::maybe_upgrade();
 assert_schema($oai_schema_dbdelta_calls===$migration_calls,'OAuth migration reran on a current-schema request');
 assert_schema((int)$wpdb->get_var("SELECT COUNT(*) FROM ".OAI_MCP_OAuth::clients_table()." WHERE client_id='retained-client'")===1,'Registered OAuth client was lost');
 echo "PASS: incomplete migration retries; successful upgrade runs once and retains OAuth client\n";
} finally {
 foreach(array(OAI_MCP_OAuth::clients_table(),OAI_MCP_OAuth::codes_table(),OAI_MCP_OAuth::tokens_table(),OAI_MCP_OAuth::registry_table()) as $table) $wpdb->query('DROP TABLE IF EXISTS '.$table);
}

