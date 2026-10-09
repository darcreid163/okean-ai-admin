<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Store {
    private bool $mysql;
    private int $transactionDepth=0;
    public function __construct(private readonly \PDO $db, private readonly string $prefix='wpai_cloud_') {
        if (!preg_match('/^[A-Za-z0-9_]{1,48}$/D',$prefix)) throw new Fault('invalid_table_prefix',503);
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE,\PDO::FETCH_ASSOC);
        $this->mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        if (!$this->mysql) { $db->exec('PRAGMA busy_timeout=10000'); $db->exec('PRAGMA journal_mode=WAL'); }
    }
    public function table(string $name): string {
        if (!in_array($name,['accounts','balances','sites','tokens','requests','audit','rate','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'],true)) throw new Fault('invalid_table',503);
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
            'accounts'=>'owner VARCHAR(64) PRIMARY KEY, plan VARCHAR(20) NOT NULL, disabled INTEGER NOT NULL DEFAULT 0, sequence INTEGER NOT NULL DEFAULT 1',
            'balances'=>'owner VARCHAR(64) NOT NULL, period VARCHAR(7) NOT NULL, used INTEGER NOT NULL DEFAULT 0, reserved INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(owner,period)',
            'sites'=>'id VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, home VARCHAR(512) NOT NULL, url_hash VARCHAR(64) NOT NULL UNIQUE, plugin_site_id VARCHAR(120) NOT NULL, installation_id VARCHAR(120) NOT NULL, token_sealed TEXT NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL',
            'tokens'=>'hash VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, scopes TEXT NOT NULL, site_ids TEXT NOT NULL, expires_at BIGINT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0',
            'requests'=>'request_key VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, site_id VARCHAR(64) NOT NULL, period VARCHAR(7) NOT NULL, fingerprint VARCHAR(64) NOT NULL, tool VARCHAR(120) NOT NULL, state VARCHAR(20) NOT NULL, reserved_units INTEGER NOT NULL DEFAULT 1, policy_version VARCHAR(80) NOT NULL, response_sealed '.$large.' NOT NULL, reconciliation_evidence '.$large.' NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL',
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
        $this->assertSchema(array_keys($definitions));
    }
    public function assertSchema(?array $tableNames=null): void {
        $tableNames=$tableNames??['accounts','balances','sites','tokens','requests','rate','audit','oauth_clients','oauth_codes','oauth_grants','oauth_refresh','oauth_access','oauth_rate'];
        // Validate the minimum schema even when the WordPress DB-version option
        // already says migration is current. This catches partial restores,
        // manually damaged tables and interrupted historical migrations.
        $required=[
            'accounts'=>['owner','plan','disabled','sequence'],
            'balances'=>['owner','period','used','reserved'],
            'sites'=>['id','owner','home','url_hash','plugin_site_id','installation_id','token_sealed','status','created_at'],
            'tokens'=>['hash','owner','scopes','site_ids','expires_at','revoked'],
            'requests'=>['request_key','owner','site_id','period','fingerprint','tool','state','reserved_units','policy_version','response_sealed','reconciliation_evidence','created_at','updated_at'],
            'rate'=>['owner','bucket','slot','hits'],
            'audit'=>['id','owner','site_id','event','request_key','created_at'],
            'oauth_clients'=>['id','name','redirects','created_at'],
            'oauth_codes'=>['hash','owner','client','redirect','resource','scopes','sites','challenge','expires','used'],
            'oauth_grants'=>['id','owner','client','resource','scopes','sites','revoked','expires'],
            'oauth_refresh'=>['hash','grant_id','used','expires'],
            'oauth_access'=>['hash','grant_id','resource'],
            'oauth_rate'=>['bucket','slot','hits'],
        ];

        if($this->mysql){
            $names=array_map(fn($name)=>$this->prefix.$name,$tableNames);
            $placeholders=implode(',',array_fill(0,count($names),'?'));

            $tableRows=$this->rows('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')',$names);
            $engines=[];
            foreach($tableRows as $row)$engines[(string)$row['TABLE_NAME']]=strtolower((string)$row['ENGINE']);

            $columnRows=$this->rows('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')',$names);
            $columnMap=[];
            foreach($columnRows as $row)$columnMap[(string)$row['TABLE_NAME']][]=(string)$row['COLUMN_NAME'];

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
