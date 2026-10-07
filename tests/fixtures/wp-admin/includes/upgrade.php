<?php
function dbDelta($sql) {
 global $wpdb, $oai_schema_dbdelta_calls, $oai_schema_skip_table;
 ++$oai_schema_dbdelta_calls;
 if ($oai_schema_skip_table && str_contains($sql, 'CREATE TABLE '.$oai_schema_skip_table.' ')) return array();
 $sql = preg_replace('/^CREATE TABLE /', 'CREATE TABLE IF NOT EXISTS ', $sql);
 if (!$wpdb->query($sql)) throw new RuntimeException('dbDelta fixture failed: '.$wpdb->last_error);
 return array();
}

