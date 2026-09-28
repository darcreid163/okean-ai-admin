<?php
/**
 * Integration probe for the real MySQL named lock used by Batch Executor.
 * Run with DB_HOST, DB_USER, DB_PASSWORD and DB_NAME in a disposable CI database.
 */
define('ABSPATH', __DIR__ . '/');
class WP_Error {
 private $code;
 public function __construct($code, $message = '', $data = array()) { $this->code = $code; }
 public function get_error_code() { return $this->code; }
}
require_once dirname(__DIR__) . '/includes/class-oai-batch-executor.php';

class OAI_Mutex_Test_DB {
 private $db;
 public $dbname;
 public $prefix = 'sushi_';
 public function __construct() {
  $this->dbname = getenv('DB_NAME') ?: 'wp_test';
  $this->db = new mysqli(getenv('DB_HOST') ?: '127.0.0.1', getenv('DB_USER') ?: 'root', getenv('DB_PASSWORD') ?: '', $this->dbname);
  if ($this->db->connect_errno) throw new RuntimeException($this->db->connect_error);
 }
 public function prepare($query, $name) { return sprintf($query, "'" . $this->db->real_escape_string($name) . "'"); }
 public function get_var($query) {
  $result = $this->db->query($query);
  if (!$result) throw new RuntimeException($this->db->error);
  $row = $result->fetch_row();
  return $row[0] ?? null;
 }
}

function attempt_lock($marker = '', $hold = false) {
 global $wpdb;
 $wpdb = new OAI_Mutex_Test_DB();
 $method = new ReflectionMethod('OAI_Batch_Executor', 'with_mutex');
 $result = $method->invoke(null, function() use ($marker, $hold) {
  if ($marker !== '') file_put_contents($marker, 'locked');
  if ($hold) sleep(3);
  return 'acquired';
 });
 return $result instanceof WP_Error ? $result->get_error_code() : $result;
}

if (($argv[1] ?? '') === 'worker') {
 echo attempt_lock($argv[2] ?? '', ($argv[3] ?? '') === 'hold');
 exit;
}

$marker = sys_get_temp_dir() . '/oai-batch-lock-' . bin2hex(random_bytes(8));
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker ' . escapeshellarg($marker) . ' hold';
$holder = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
if (!is_resource($holder)) throw new RuntimeException('Cannot start lock holder');
fclose($pipes[0]);
try {
 $ready = false;
 for ($i = 0; $i < 100; $i++) { if (is_file($marker)) { $ready = true; break; } usleep(50000); }
 if (!$ready) throw new RuntimeException('Holder did not acquire MySQL lock');
 if (attempt_lock() !== 'oai_batch_busy') throw new RuntimeException('Concurrent contender entered critical section');
 $holder_output = stream_get_contents($pipes[1]);
 $holder_error = stream_get_contents($pipes[2]);
 fclose($pipes[1]); fclose($pipes[2]);
 if (proc_close($holder) !== 0 || $holder_output !== 'acquired') throw new RuntimeException('Holder failed: ' . $holder_error);
 if (attempt_lock() !== 'acquired') throw new RuntimeException('Lock was not released');
 echo "PASS: one contender rejected; lock released for next request\n";
} finally {
 if (is_file($marker)) unlink($marker);
}
