<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Store {
    private bool $mysql;
    private int $transactionDepth=0;
    private static ?\WeakMap $licenseSchemaReady=null;
    public function __construct(private readonly \PDO $db, private readonly string $prefix='wpai_cloud_') {
        if (!preg_match('/^[A-Za-z0-9_]{1,45}$/D',$prefix)) throw new Fault('invalid_table_prefix',503);
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE,\PDO::FETCH_ASSOC);
        $this->mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        if (!$this->mysql) { $db->exec('PRAGMA busy_timeout=10000'); $db->exec('PRAGMA journal_mode=WAL'); }
    }
    public function table(string $name): string {
        if (!in_array($name,['accounts','balances','sites','tokens','requests','batch_steps','audit','rate','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate','licenses','license_activations','license_events','sse_sessions','sse_queue'],true)) throw new Fault('invalid_table',503);
        return '`'.$this->prefix.$name.'`';
    }
    public function execute(string $sql, array $params=[]): \PDOStatement { $s=$this->db->prepare($sql); $s->execute($params); return $s; }
    public function row(string $sql,array $params=[]): ?array { return $this->execute($sql,$params)->fetch() ?: null; }
    public function rows(string $sql,array $params=[]): array { return $this->execute($sql,$params)->fetchAll(); }
    public function lock(): string { return $this->mysql?' FOR UPDATE':''; }
    public function ignore(): string { return $this->mysql?'INSERT IGNORE':'INSERT OR IGNORE'; }
    public function transaction(callable $fn): mixed {
        // Core/OAuth operations compose transactional primitives. Track nesting
        // ourselves: PDO::inTransaction() is not a reliable detector for the
        // SQLite BEGIN IMMEDIATE statement used by this Store.
        if ($this->transactionDepth>0) return $fn();

        if ($this->mysql) $this->db->beginTransaction(); else $this->db->exec('BEGIN IMMEDIATE');
        $this->transactionDepth=1;
        try {
            $value=$fn();
            if ($this->mysql) $this->db->commit(); else $this->db->exec('COMMIT');
            $this->transactionDepth=0;
            return $value;
        } catch (\Throwable $e) {
            try {
                if ($this->mysql) {
                    if ($this->db->inTransaction()) $this->db->rollBack();
                } else {
                    $this->db->exec('ROLLBACK');
                }
            } catch (\Throwable $rollbackError) {
                // Preserve the original failure; caller-facing safe() will
                // still fail closed rather than exposing a second DB error.
            }
            $this->transactionDepth=0;
            throw $e;
        }
    }
    public function migrate(): void {
        $tail=$this->mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
        $large=$this->mysql?'MEDIUMTEXT':'TEXT';
        $definitions=[
            'accounts'=>"owner VARCHAR(64) PRIMARY KEY, plan VARCHAR(20) NOT NULL, disabled INTEGER NOT NULL DEFAULT 0, sequence INTEGER NOT NULL DEFAULT 1, quota_scheme VARCHAR(20) NOT NULL DEFAULT 'legacy_monthly_v1'",
            'balances'=>'owner VARCHAR(64) NOT NULL, period VARCHAR(10) NOT NULL, used INTEGER NOT NULL DEFAULT 0, reserved INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(owner,period)',
            'sites'=>'id VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, home VARCHAR(512) NOT NULL, url_hash VARCHAR(64) NOT NULL UNIQUE, plugin_site_id VARCHAR(120) NOT NULL, installation_id VARCHAR(120) NOT NULL, token_sealed TEXT NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL',
            'tokens'=>'hash VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, scopes TEXT NOT NULL, site_ids TEXT NOT NULL, expires_at BIGINT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0',
            'requests'=>'request_key VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, site_id VARCHAR(64) NOT NULL, period VARCHAR(10) NOT NULL, fingerprint VARCHAR(64) NOT NULL, tool VARCHAR(120) NOT NULL, state VARCHAR(20) NOT NULL, reserved_units INTEGER NOT NULL DEFAULT 1, policy_version VARCHAR(80) NOT NULL, response_sealed '.$large.' NOT NULL, reconciliation_evidence '.$large.' NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
            'batch_steps'=>'request_key VARCHAR(64) NOT NULL, step_id VARCHAR(64) NOT NULL, owner VARCHAR(64) NOT NULL, site_id VARCHAR(64) NOT NULL, ordinal INTEGER NOT NULL, tool VARCHAR(120) NOT NULL, fingerprint VARCHAR(64) NOT NULL, state VARCHAR(20) NOT NULL, charged_units INTEGER NOT NULL DEFAULT 0, receipt_sealed '.$large.' NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, PRIMARY KEY(request_key,step_id)',
            'rate'=>'owner VARCHAR(64) NOT NULL, bucket VARCHAR(20) NOT NULL, slot BIGINT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(owner,bucket,slot)',
            'audit'=>'id VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, site_id VARCHAR(64) NOT NULL, event VARCHAR(40) NOT NULL, request_key VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL',
            'oauth_clients'=>'id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL, redirects TEXT NOT NULL, created_at BIGINT NOT NULL',
            'oauth_codes'=>'hash VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, client VARCHAR(64) NOT NULL, redirect TEXT NOT NULL, resource VARCHAR(512) NOT NULL, scopes TEXT NOT NULL, sites TEXT NOT NULL, challenge VARCHAR(43) NOT NULL, expires BIGINT NOT NULL, used INTEGER NOT NULL DEFAULT 0',
            'oauth_grants'=>'id VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, client VARCHAR(64) NOT NULL, resource VARCHAR(512) NOT NULL, scopes TEXT NOT NULL, sites TEXT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, expires BIGINT NOT NULL',
            'oauth_refresh'=>'hash VARCHAR(64) PRIMARY KEY, grant_id VARCHAR(64) NOT NULL, used INTEGER NOT NULL DEFAULT 0, expires BIGINT NOT NULL',
            'oauth_access'=>'hash VARCHAR(64) PRIMARY KEY, grant_id VARCHAR(64) NOT NULL, resource VARCHAR(512) NOT NULL',
            'oauth_rate'=>'bucket VARCHAR(64) NOT NULL, slot BIGINT NOT NULL, hits INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(bucket,slot)'
        ];
        foreach ($definitions as $name=>$def) $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table($name).' ('.$def.')'.$tail);
        $this->upgradeQuotaSchema();
        $this->ensureOperationalIndexes(array_keys($definitions));
        $this->assertSchema(array_keys($definitions));
        $this->ensureSseQueueSchema();
    }
    /** Additive durable queue schema for the legacy SSE transport. */
    public function ensureSseQueueSchema(): void {
        if ($this->transactionDepth>0) throw new Fault('sse_queue_schema_unavailable',503);
        $tail=$this->mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
        $indexName='wpai_'.substr(hash('sha256',$this->prefix),0,16).'_idx_sse_queue_session_id';
        if ($this->mysql) {
            $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table('sse_sessions').' (session_id VARCHAR(32) PRIMARY KEY, expires_at BIGINT NOT NULL)'.$tail);
            $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table('sse_queue').' (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(32) NOT NULL, payload MEDIUMTEXT NOT NULL, created_at BIGINT NOT NULL, KEY `'.$indexName.'` (session_id,id))'.$tail);
        } else {
            $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table('sse_sessions').' (session_id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL)');
            $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table('sse_queue').' (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id TEXT NOT NULL, payload TEXT NOT NULL, created_at INTEGER NOT NULL)');
            // SQLite index names are database-global. Fail closed if a same-name
            // index already belongs to another table or has a different definition.
            $named=$this->row("SELECT tbl_name FROM sqlite_master WHERE type='index' AND name=?",[$indexName]);
            $physical=$this->prefix.'sse_queue';
            if ($named && (string)$named['tbl_name']!==$physical) throw new Fault('gateway_schema_incompatible',503);
            if ($named) {
                $columns=array_map(static fn($row)=>(string)$row['name'],$this->rows("PRAGMA index_info('".$indexName."')"));
                if ($columns!==['session_id','id']) throw new Fault('gateway_schema_incompatible',503);
            }
            $this->execute('CREATE INDEX IF NOT EXISTS `'.$indexName.'` ON '.$this->table('sse_queue').'(session_id,id)');
        }
    }
    public function createSseSession(string $id,int $expiresAt): void {
        $this->transaction(function()use($id,$expiresAt) {
            $now=time();
            $this->execute('DELETE FROM '.$this->table('sse_queue').' WHERE session_id IN (SELECT session_id FROM '.$this->table('sse_sessions').' WHERE expires_at<?)',[$now]);
            $this->execute('DELETE FROM '.$this->table('sse_sessions').' WHERE expires_at<?',[$now]);
            $this->execute('INSERT INTO '.$this->table('sse_sessions').' (session_id,expires_at) VALUES (?,?)',[$id,$expiresAt]);
        });
    }
    /** Reserve bounded queue capacity before executing a potentially mutating Gateway request. */
    public function reserveSseMessage(string $id,int $now,int $expiresAt,int $limit=32): int {
        return $this->transaction(function()use($id,$now,$expiresAt,$limit) {
            $session=$this->row('SELECT expires_at FROM '.$this->table('sse_sessions').' WHERE session_id=?'.$this->lock(),[$id]);
            if (!$session || (int)$session['expires_at']<$now) throw new Fault('sse_session_expired',410);
            $count=(int)$this->row('SELECT COUNT(*) AS n FROM '.$this->table('sse_queue').' WHERE session_id=?',[$id])['n'];
            if ($count >= $limit) throw new Fault('sse_queue_full',429);
            $this->execute('INSERT INTO '.$this->table('sse_queue').' (session_id,payload,created_at) VALUES (?,?,?)',[$id,'',$now]);
            $reservation=(int)$this->db->lastInsertId();
            if ($reservation<1) throw new Fault('sse_queue_reservation_failed',503);
            $this->execute('UPDATE '.$this->table('sse_sessions').' SET expires_at=? WHERE session_id=?',[$expiresAt,$id]);
            return $reservation;
        });
    }
    /** Publish a completed reply into its previously reserved queue slot. */
    public function completeSseMessage(string $id,int $reservation,string $payload,int $now,int $expiresAt): void {
        $this->transaction(function()use($id,$reservation,$payload,$now,$expiresAt) {
            $session=$this->row('SELECT expires_at FROM '.$this->table('sse_sessions').' WHERE session_id=?'.$this->lock(),[$id]);
            if (!$session || (int)$session['expires_at']<$now) throw new Fault('sse_session_expired',410);
            $updated=$this->execute('UPDATE '.$this->table('sse_queue').' SET payload=?,created_at=? WHERE id=? AND session_id=? AND payload=?',[$payload,$now,$reservation,$id,'']);
            if ($updated->rowCount()!==1) throw new Fault('sse_queue_reservation_lost',503);
            $this->execute('UPDATE '.$this->table('sse_sessions').' SET expires_at=? WHERE session_id=?',[$expiresAt,$id]);
        });
    }
    public function cancelSseMessage(string $id,int $reservation): void {
        $this->transaction(function()use($id,$reservation) {
            $this->execute('DELETE FROM '.$this->table('sse_queue').' WHERE id=? AND session_id=? AND payload=?',[$reservation,$id,'']);
        });
    }
    /** Backwards-compatible append helper used by store-level regression fixtures. */
    public function enqueueSseMessage(string $id,string $payload,int $now,int $expiresAt,int $limit=32): void {
        $reservation=$this->reserveSseMessage($id,$now,$expiresAt,$limit);
        try { $this->completeSseMessage($id,$reservation,$payload,$now,$expiresAt); }
        catch (\Throwable $e) { $this->cancelSseMessage($id,$reservation); throw $e; }
    }
    /** Atomically read and remove only completed messages returned to this drain. */
    public function drainSseQueue(string $id,int $now): array {
        return $this->transaction(function()use($id,$now) {
            $session=$this->row('SELECT expires_at FROM '.$this->table('sse_sessions').' WHERE session_id=?'.$this->lock(),[$id]);
            if (!$session || (int)$session['expires_at']<$now) {
                $this->execute('DELETE FROM '.$this->table('sse_queue').' WHERE session_id=?',[$id]);
                $this->execute('DELETE FROM '.$this->table('sse_sessions').' WHERE session_id=?',[$id]);
                return [];
            }
            $rows=$this->rows('SELECT id,payload FROM '.$this->table('sse_queue').' WHERE session_id=? AND payload<>? ORDER BY id',[$id,'']);
            if ($rows) {
                $ids=array_map(static fn($row)=>(int)$row['id'],$rows);
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $this->execute('DELETE FROM '.$this->table('sse_queue').' WHERE id IN ('.$marks.')',$ids);
            }
            return array_map(static fn($row)=>(string)$row['payload'],$rows);
        });
    }
    /** Close a stream session without destroying an in-flight Gateway reply reservation. */
    public function deleteSseSession(string $id): bool {
        return $this->transaction(function()use($id): bool {
            // Serialize close against reserve/complete on the session row. A request
            // already executing Gateway must be allowed to publish its reply even
            // when the 45-second SSE reader window ends first.
            $session=$this->row('SELECT expires_at FROM '.$this->table('sse_sessions').' WHERE session_id=?'.$this->lock(),[$id]);
            if (!$session) return true;
            $pending=$this->row('SELECT COUNT(*) AS n FROM '.$this->table('sse_queue').' WHERE session_id=? AND payload=?',[$id,'']);
            if ((int)($pending['n']??0)>0) return false;
            $this->execute('DELETE FROM '.$this->table('sse_queue').' WHERE session_id=?',[$id]);
            $this->execute('DELETE FROM '.$this->table('sse_sessions').' WHERE session_id=?',[$id]);
            return true;
        });
    }
    private function upgradeQuotaSchema(): void {
        // Additive, restartable migration. Existing customers keep their original
        // monthly terms until an explicit operator assignment to a current offer.
        if ($this->mysql) {
            $columns=$this->rows('SELECT COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.'accounts']);
            $names=array_column($columns,'COLUMN_NAME');
        } else {
            $names=array_column($this->rows('PRAGMA table_info('.$this->table('accounts').')'),'name');
        }
        if (!in_array('quota_scheme',$names,true)) {
            try { $this->execute('ALTER TABLE '.$this->table('accounts')." ADD COLUMN quota_scheme VARCHAR(20) NOT NULL DEFAULT 'legacy_monthly_v1'"); }
            catch (\PDOException $e) {
                // Another first request can win the same idempotent migration.
                $duplicate=$this->mysql?(int)($e->errorInfo[1]??0)===1060:str_contains(strtolower($e->getMessage()),'duplicate column name');
                if (!$duplicate) throw $e;
            }
        }
        if ($this->mysql) foreach (['balances','requests'] as $name) {
            $column=$this->row('SELECT DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$this->prefix.$name,'period']);
            if ($column && $column['DATA_TYPE']==='varchar' && (int)$column['CHARACTER_MAXIMUM_LENGTH']===7) $this->execute('ALTER TABLE '.$this->table($name).' MODIFY COLUMN period VARCHAR(10) NOT NULL');
        }
    }
    /**
     * Additive, restartable indexes for the gateway's owner-scoped hot paths.
     * Index column order is verified on both supported database engines; a
     * same-name index with a different definition is treated as schema damage.
     */
    public function ensureOperationalIndexes(?array $tableNames=null): void {
        $definitions = [
            'sites' => [
                'idx_sites_owner_status_created' => ['owner','status','created_at'],
            ],
            'tokens' => [
                'idx_tokens_owner' => ['owner'],
            ],
            'requests' => [
                'idx_requests_owner_created_key' => ['owner','created_at','request_key'],
                'idx_requests_owner_site_created_key' => ['owner','site_id','created_at','request_key'],
            ],
            'batch_steps' => [
                'idx_batch_steps_request_ordinal' => ['request_key','ordinal'],
            ],
            'rate' => [
                'idx_rate_owner_slot' => ['owner','slot'],
            ],
            'oauth_codes' => [
                'idx_oauth_codes_owner_used' => ['owner','used'],
            ],
            'oauth_grants' => [
                'idx_oauth_grants_owner' => ['owner'],
            ],
            'oauth_access' => [
                'idx_oauth_access_grant_id' => ['grant_id'],
            ],
        ];
        foreach (($tableNames??array_keys($definitions)) as $table) {
            foreach ($definitions[$table] ?? [] as $name => $columns) {
                $this->ensureIndex($table, $name, $columns);
            }
        }
    }
    private function ensureLicenseIndexes(): void {
        $definitions = [
            'licenses' => [
                'idx_licenses_owner_issued_id' => ['owner','issued_at','id'],
                'idx_licenses_issued_id' => ['issued_at','id'],
                'idx_licenses_owner_status' => ['owner','status'],
            ],
            'license_activations' => [
                'idx_license_activations_license_status' => ['license_id','status'],
            ],
        ];
        foreach ($definitions as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->ensureIndex($table, $name, $columns);
            }
        }
    }
    private function ensureIndex(string $table, string $name, array $columns): void {
        $physical = $this->prefix.$table;
        // SQLite index names are database-global; include the Store prefix hash
        // so multiple isolated table namespaces can share one SQLite database.
        $name = 'wpai_'.substr(hash('sha256',$this->prefix),0,16).'_'.$name;
        $found = null;
        if ($this->mysql) {
            $rows = $this->rows(
                'SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX',
                [$physical]
            );
            $indexes = [];
            foreach ($rows as $row) $indexes[(string)$row['INDEX_NAME']][] = (string)$row['COLUMN_NAME'];
            foreach ($indexes as $indexName => $indexColumns) {
                if ($indexName === $name && $indexColumns !== $columns) throw new Fault('gateway_schema_incompatible',503);
                if ($indexColumns === $columns) { $found = $indexName; break; }
            }
        } else {
            // SQLite index names are database-global, unlike MySQL's table-local names.
            // Detect a same-name index owned by another table before attempting CREATE.
            $named = $this->row("SELECT tbl_name FROM sqlite_master WHERE type='index' AND name=?",[$name]);
            if ($named && (string)$named['tbl_name'] !== $physical) throw new Fault('gateway_schema_incompatible',503);
            $rows = $this->rows('PRAGMA index_list('.$this->table($table).')');
            foreach ($rows as $row) {
                $indexName = (string)$row['name'];
                $quoted = str_replace("'", "''", $indexName);
                $indexColumns = array_map(
                    static fn($column) => (string)$column['name'],
                    $this->rows("PRAGMA index_info('".$quoted."')")
                );
                if ($indexName === $name && $indexColumns !== $columns) throw new Fault('gateway_schema_incompatible',503);
                if ($indexColumns === $columns) { $found = $indexName; break; }
            }
        }
        if ($found !== null) return;
        $q = chr(96);
        $columnSql = implode(',',array_map(static fn($column) => $q.$column.$q,$columns));
        try {
            $this->execute('CREATE INDEX '.$q.$name.$q.' ON '.$this->table($table).' ('.$columnSql.')');
        } catch (\PDOException $e) {
            // Concurrent first requests may both observe a missing index. Only
            // suppress a duplicate-index race after re-reading and validating it.
            $duplicate = $this->mysql
                ? (int)($e->errorInfo[1] ?? 0) === 1061
                : str_contains(strtolower($e->getMessage()),'already exists');
            if (!$duplicate) throw $e;
            $this->ensureIndex($table,$name,$columns);
        }
    }
    /**
     * Additive, lazy licence schema. It is deliberately not part of the
     * gateway DB-version migration: historical 0.3.11 -> 0.3.13 upgrade and
     * restore tests assert the exact table set, so licence tables may only be
     * created when a licence operation (or account provisioning) actually
     * runs. Existing accounts without accounts.license_id keep their previous
     * behaviour until a licence is issued for them.
     */
    public function ensureLicenseSchema(): void {
        self::$licenseSchemaReady ??= new \WeakMap();
        if (isset(self::$licenseSchemaReady[$this])) return;
        if ($this->transactionDepth>0) throw new Fault('license_schema_unavailable',503);
        $tail=$this->mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin':'';
        $definitions=[
            'licenses'=>"id VARCHAR(32) PRIMARY KEY, key_hash CHAR(64) NOT NULL UNIQUE, key_prefix VARCHAR(24) NOT NULL, owner VARCHAR(64) NULL, plan VARCHAR(20) NOT NULL, quota_scheme VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, activation_limit INTEGER NOT NULL DEFAULT 1, activations INTEGER NOT NULL DEFAULT 0, issued_at BIGINT NOT NULL, starts_at BIGINT NOT NULL, expires_at BIGINT NULL, activated_at BIGINT NULL, revoked_at BIGINT NULL, replaced_by VARCHAR(32) NULL, replaces VARCHAR(32) NULL, comment VARCHAR(255) NOT NULL DEFAULT '', issued_by VARCHAR(64) NOT NULL, source VARCHAR(20) NOT NULL, sequence INTEGER NOT NULL DEFAULT 1",
            'license_activations'=>"license_id VARCHAR(32) NOT NULL, site_id VARCHAR(64) NOT NULL, owner VARCHAR(64) NOT NULL, home VARCHAR(512) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'active', activated_at BIGINT NOT NULL, deactivated_at BIGINT NULL, PRIMARY KEY(license_id,site_id)",
            'license_events'=>"id VARCHAR(64) PRIMARY KEY, license_id VARCHAR(32) NOT NULL, owner VARCHAR(64) NOT NULL, event VARCHAR(40) NOT NULL, actor VARCHAR(64) NOT NULL, detail_hash CHAR(64) NOT NULL, created_at BIGINT NOT NULL",
        ];
        foreach ($definitions as $name=>$def) $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table($name).' ('.$def.')'.$tail);
        if (!$this->hasColumn('accounts','license_id')) {
            try { $this->execute('ALTER TABLE '.$this->table('accounts').' ADD COLUMN license_id VARCHAR(32) NULL'); }
            catch (\PDOException $e) {
                // A concurrent first request may have added the same column.
                $duplicate=$this->mysql?(int)($e->errorInfo[1]??0)===1060:str_contains(strtolower($e->getMessage()),'duplicate column name');
                if (!$duplicate) throw $e;
            }
        }
        $this->ensureLicenseIndexes();
        $this->assertSchema(['licenses','license_activations','license_events']);
        if (!$this->hasColumn('accounts','license_id')) throw new Fault('license_schema_incompatible',503);
        self::$licenseSchemaReady[$this]=true;
    }
    private function hasColumn(string $table,string $column): bool {
        if ($this->mysql) {
            return (bool)$this->row('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$this->prefix.$table,$column]);
        }
        $rows=$this->rows('PRAGMA table_info('.$this->table($table).')');
        return in_array($column,array_map(static fn($r)=>(string)$r['name'],$rows),true);
    }
    public function assertSchema(?array $tableNames=null): void {
        $tableNames=$tableNames??['accounts','balances','sites','tokens','requests','batch_steps','rate','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'];
        // Validate the minimum schema even when the WordPress DB-version option
        // already says migration is current. This catches partial restores,
        // manually damaged tables and interrupted historical migrations.
        $required=[
            'accounts'=>['owner','plan','disabled','sequence','quota_scheme'],
            'balances'=>['owner','period','used','reserved'],
            'sites'=>['id','owner','home','url_hash','plugin_site_id','installation_id','token_sealed','status','created_at'],
            'tokens'=>['hash','owner','scopes','site_ids','expires_at','revoked'],
            'requests'=>['request_key','owner','site_id','period','fingerprint','tool','state','reserved_units','policy_version','response_sealed','reconciliation_evidence','created_at','updated_at'],
            'batch_steps'=>['request_key','step_id','owner','site_id','ordinal','tool','fingerprint','state','charged_units','receipt_sealed','created_at','updated_at'],
            'rate'=>['owner','bucket','slot','hits'],
            'audit'=>['id','owner','site_id','event','request_key','created_at'],
            'oauth_clients'=>['id','name','redirects','created_at'],
            'oauth_codes'=>['hash','owner','client','redirect','resource','scopes','sites','challenge','expires','used'],
            'oauth_grants'=>['id','owner','client','resource','scopes','sites','revoked','expires'],
            'oauth_refresh'=>['hash','grant_id','used','expires'],
            'oauth_access'=>['hash','grant_id','resource'],
            'oauth_rate'=>['bucket','slot','hits'],
            'licenses'=>['id','key_hash','key_prefix','owner','plan','quota_scheme','status','activation_limit','activations','issued_at','starts_at','expires_at','activated_at','revoked_at','replaced_by','replaces','comment','issued_by','source','sequence'],
            'license_activations'=>['license_id','site_id','owner','home','status','activated_at','deactivated_at'],
            'license_events'=>['id','license_id','owner','event','actor','detail_hash','created_at'],
        ];

        if($this->mysql){
            $names=array_map(fn($name)=>$this->prefix.$name,$tableNames);
            $placeholders=implode(',',array_fill(0,count($names),'?'));

            $tableRows=$this->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')',$names);
            $engines=[];
            foreach($tableRows as $row)$engines[(string)$row['TABLE_NAME']]=strtolower((string)$row['ENGINE']);

            $columnRows=$this->rows('SELECT TABLE_NAME,COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')',$names);
            $columnMap=[];
            foreach($columnRows as $row) {
                $physical=(string)$row['TABLE_NAME'];$columnMap[$physical][]=(string)$row['COLUMN_NAME'];
                if (in_array($physical,[$this->prefix.'balances',$this->prefix.'requests'],true) && $row['COLUMN_NAME']==='period' && ($row['DATA_TYPE']!=='varchar' || (int)$row['CHARACTER_MAXIMUM_LENGTH']<10)) throw new Fault('gateway_schema_incompatible',503);
                if ($physical===$this->prefix.'accounts' && $row['COLUMN_NAME']==='quota_scheme' && ($row['DATA_TYPE']!=='varchar' || (int)$row['CHARACTER_MAXIMUM_LENGTH']<20)) throw new Fault('gateway_schema_incompatible',503);
            }

            foreach($tableNames as $name){
                if(!isset($required[$name])) throw new Fault('gateway_schema_incompatible',503);
                $physical=$this->prefix.$name;
                if(!isset($engines[$physical])) throw new Fault('gateway_schema_incompatible',503);
                if($engines[$physical]!=='innodb') throw new Fault('innodb_required',503);
                if(array_diff($required[$name],$columnMap[$physical]??[])) throw new Fault('gateway_schema_incompatible',503);
            }
            return;
        }

        foreach($tableNames as $name){
            if(!isset($required[$name])) throw new Fault('gateway_schema_incompatible',503);
            $rows=$this->rows('PRAGMA table_info('.$this->table($name).')');
            $present=array_map(static fn($r)=>(string)$r['name'],$rows);
            if(array_diff($required[$name],$present)) throw new Fault('gateway_schema_incompatible',503);
        }
    }
    public function audit(string $owner,string $site,string $event,string $key,int $now): void {
        $this->execute('INSERT INTO '.$this->table('audit').' (id,owner,site_id,event,request_key,created_at) VALUES (?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$owner,$site,$event,$key,$now]);
    }
}
