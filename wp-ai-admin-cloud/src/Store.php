<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Store {
    private bool $mysql;
    public function __construct(private readonly \PDO $db, private readonly string $prefix='wpai_cloud_') {
        if (!preg_match('/^[A-Za-z0-9_]{1,48}$/D',$prefix)) throw new Fault('invalid_table_prefix',503);
        $db->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE,\PDO::FETCH_ASSOC);
        $this->mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        if (!$this->mysql) { $db->exec('PRAGMA busy_timeout=10000'); $db->exec('PRAGMA journal_mode=WAL'); }
    }
    public function table(string $name): string {
        if (!in_array($name,['accounts','balances','sites','tokens','requests','audit','rate'],true)) throw new Fault('invalid_table',503);
        return '`'.$this->prefix.$name.'`';
    }
    public function execute(string $sql, array $params=[]): \PDOStatement { $s=$this->db->prepare($sql); $s->execute($params); return $s; }
    public function row(string $sql,array $params=[]): ?array { return $this->execute($sql,$params)->fetch() ?: null; }
    public function rows(string $sql,array $params=[]): array { return $this->execute($sql,$params)->fetchAll(); }
    public function lock(): string { return $this->mysql?' FOR UPDATE':''; }
    public function ignore(): string { return $this->mysql?'INSERT IGNORE':'INSERT OR IGNORE'; }
    public function transaction(callable $fn): mixed {
        if ($this->mysql) $this->db->beginTransaction(); else $this->db->exec('BEGIN IMMEDIATE');
        try { $value=$fn(); if ($this->mysql) $this->db->commit(); else $this->db->exec('COMMIT'); return $value; }
        catch (\Throwable $e) { if ($this->mysql && $this->db->inTransaction()) $this->db->rollBack(); elseif (!$this->mysql) $this->db->exec('ROLLBACK'); throw $e; }
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
            'audit'=>'id VARCHAR(64) PRIMARY KEY, owner VARCHAR(64) NOT NULL, site_id VARCHAR(64) NOT NULL, event VARCHAR(40) NOT NULL, request_key VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL'
        ];
        foreach ($definitions as $name=>$def) $this->execute('CREATE TABLE IF NOT EXISTS '.$this->table($name).' ('.$def.')'.$tail);
        // Refuse pre-existing nontransactional tables rather than pretending reservations are atomic.
        if ($this->mysql) foreach (array_keys($definitions) as $name) {
            $row=$this->row('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$this->prefix.$name]);
            if (strtolower($row['ENGINE']??'')!=='innodb') throw new Fault('innodb_required',503);
        }
    }
    public function audit(string $owner,string $site,string $event,string $key,int $now): void {
        $this->execute('INSERT INTO '.$this->table('audit').' (id,owner,site_id,event,request_key,created_at) VALUES (?,?,?,?,?,?)',[bin2hex(random_bytes(16)),$owner,$site,$event,$key,$now]);
    }
}
