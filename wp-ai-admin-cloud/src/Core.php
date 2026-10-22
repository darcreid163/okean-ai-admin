<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Core {
    // Exceeds the bounded HTTPS catalog + tool request timeouts. Expiry only
    // fences local settlement; it never proves a remote mutation did not occur.
    public const DISPATCH_LEASE_SECONDS = 300;
    public const MAX_SITES = 50;
    public const READ_TOOLS = ['woodmart_status','woodmart_list_settings','woodmart_get_setting','wp_get_site','wp_inspect_html','wp_visual_qa_start','wp_visual_qa_result','system_page_qa_run','wp_get_theme_mods','wp_list_plugins','wp_list_themes','wp_list_post_types','wp_list_taxonomies','wp_list_media','wp_list_menus','wp_list_options','wp_list_users','wp_list_comments','wp_list_widgets','wp_health','wp_diagnostics','wp_list_operations','operation_get','operation_verify','operation_proof','operation_preview_link','wp_list_files','wp_search_files','wp_execution_readiness','elementor_get_structure','elementor_get_widget','elementor_get_schema','elementor_get_globals','elementor_get_container','elementor_get_page_layout','woocommerce_get_product','operation_get_receipt','gutenberg_get_blocks','gutenberg_get_outline','gutenberg_locate_block','builder_catalog','builder_detect','system_capabilities','wp_read_content','wp_read_post','wp_read_meta','wp_read_option','wp_rest_routes','wp_rest_read','file_read','wp_file_outline','woocommerce_search_products','woocommerce_get_variations','woocommerce_search_orders','woocommerce_get_product_full','operation_status','operation_diff','preview_get','verify_operation','proof_operation','elementor_inspect_page','elementor_inspect_widget','system_manifest','batch_plan','batch_preflight','batch_dry_run','batch_list','batch_status','batch_receipt','batch_proof','file_diff','system_tool_catalog','system_self_test','system_security_status','system_test_automation_status','system_regression_history','system_coverage_report'];
    public const FREE_TOOLS = ['operation_get','operation_status','operation_verify','operation_proof','operation_preview_link','operation_get_receipt','operation_diff','operation_rollback','verify_operation','proof_operation','preview_get','batch_receipt','batch_proof','batch_status','batch_list','batch_rollback'];
    public static function units(string $name): int { return in_array(str_replace('.','_',$name),self::FREE_TOOLS,true)?0:1; }
    public function __construct(private readonly Store $store, private readonly Vault $vault, private readonly Transport $transport, private readonly array $policy, private readonly ?Signer $signer=null) {
        QuotaPolicy::validate($policy);
    }
    public function ensureAccount(string $owner): void {
        Security::identifier($owner,64);
        $this->store->execute($this->store->ignore().' INTO '.$this->store->table('accounts').' (owner,plan,quota_scheme) VALUES (?,?,?)',[$owner,'free',QuotaPolicy::scheme($this->policy)]);
    }
    private function account(string $owner,bool $lock=false): array {
        $a=$this->store->row('SELECT * FROM '.$this->store->table('accounts').' WHERE owner=?'.($lock?$this->store->lock():''),[$owner]);
        if (!$a || (int)$a['disabled']!==0) throw new Fault('account_unavailable',403);
        QuotaPolicy::terms($this->policy,$a);
        return $a;
    }
    private function ownedSite(string $owner,string $id): array {
        $s=$this->store->row('SELECT * FROM '.$this->store->table('sites').' WHERE id=? AND owner=? AND status=?',[$id,$owner,'active']);
        if (!$s) throw new Fault('site_unavailable',404);
        return $s;
    }
    public function assertActiveAccount(string $owner,bool $lock=false): void {
        $this->account($owner,$lock);
    }
    public function sites(string $owner): array {
        $this->account($owner);
        return $this->store->rows('SELECT id,home,plugin_site_id,installation_id,status,created_at FROM '.$this->store->table('sites').' WHERE owner=? AND status=? ORDER BY created_at',[$owner,'active']);
    }
    public function closureInventory(string $owner): array {
        $this->account($owner);
        $count=function(string $table,string $where,array $args) {
            $row=$this->store->row('SELECT COUNT(*) AS n FROM '.$this->store->table($table).' WHERE '.$where,$args);
            return (int)($row['n']??0);
        };
        return [
            'owner'=>$owner,
            'active_sites'=>$count('sites','owner=? AND status=?',[$owner,'active']),
            'active_tokens'=>$count('tokens','owner=? AND revoked=0',[$owner]),
            'active_oauth_grants'=>$count('oauth_grants','owner=? AND revoked=0',[$owner]),
            'unused_oauth_codes'=>$count('oauth_codes','owner=? AND used=0',[$owner]),
            'retained_requests'=>$count('requests','owner=?',[$owner]),
            'retained_audit'=>$count('audit','owner=?',[$owner]),
            'retained_balances'=>$count('balances','owner=?',[$owner]),
            'action'=>'suspend_first_delete_later_under_retention_policy',
            'destructive_delete_performed'=>false,
        ];
    }
    public function suspendAccount(string $owner,int $now): array {
        return $this->store->transaction(function() use($owner,$now) {
            $before=$this->closureInventory($owner);
            $this->account($owner,true);
            // Clear credentials from every historical registration for this owner,
            // including legacy rows already marked revoked.
            $this->store->execute('UPDATE '.$this->store->table('sites').' SET status=?,token_sealed=? WHERE owner=?',['revoked','',$owner]);
            $this->store->execute('UPDATE '.$this->store->table('tokens').' SET revoked=1 WHERE owner=?',[$owner]);
            $this->store->execute('UPDATE '.$this->store->table('oauth_grants').' SET revoked=1 WHERE owner=?',[$owner]);
            $this->store->execute('UPDATE '.$this->store->table('oauth_codes').' SET used=1 WHERE owner=? AND used=0',[$owner]);
            $this->store->execute('UPDATE '.$this->store->table('accounts').' SET disabled=1,sequence=sequence+1 WHERE owner=?',[$owner]);
            $this->store->audit($owner,'','account_suspended','',$now);
            return [
                'suspended'=>true,
                'access_revoked'=>true,
                'site_credentials_cleared'=>true,
                'destructive_delete_performed'=>false,
                'retained_evidence'=>[
                    'requests'=>$before['retained_requests'],
                    'audit'=>$before['retained_audit']+1,
                    'balances'=>$before['retained_balances'],
                ],
            ];
        });
    }
    public function connect(string $owner,string $home,string $token,int $now,bool $replace=false): array {
        $this->account($owner); $home=Security::home($home);
        $this->throttle($owner,'site_proof',$now,10);
        if (!preg_match('/^[A-Za-z0-9._~-]{20,512}$/D',$token)) throw new Fault('invalid_site_token');
        $reply=$this->transport->rpc($home,$token,['jsonrpc'=>'2.0','id'=>'cloud-proof-'.bin2hex(random_bytes(8)),'method'=>'tools/call','params'=>['name'=>'system_manifest','arguments'=>(object)[]]]);
        if (isset($reply['error']) || !empty($reply['result']['isError'])) throw new Fault('site_admin_proof_failed',403);
        $texts=$reply['result']['content']??[]; $manifest=null;
        foreach ($texts as $block) if (($block['type']??'')==='text') { $v=json_decode($block['text']??'',true); if (is_array($v) && isset($v['site_id'],$v['installation_id'])) { $manifest=$v; break; } }
        if (!$manifest || ($manifest['plugin_name']??'')!=='WP AI Admin' || ($manifest['release']['production_defaults']['direct_live_writes']??null)!==false) throw new Fault('site_manifest_incompatible',422);
        $pluginSite=Security::identifier($manifest['site_id']); $installation=Security::identifier($manifest['installation_id']);
        return $this->store->transaction(function() use ($owner,$home,$token,$pluginSite,$installation,$now,$replace) {
            $this->account($owner,true);
            $hash=hash('sha256',$home); $existing=$this->store->row('SELECT * FROM '.$this->store->table('sites').' WHERE url_hash=?',[$hash]);
            if($existing) {
                if(!$replace||$existing['owner']!==$owner)throw new Fault('site_already_registered',409);
                if($existing['plugin_site_id']!==$pluginSite||$existing['installation_id']!==$installation)throw new Fault('site_identity_changed',409);
                // A revoked registration gets a fresh ID: old grants must not regain access.
                $id=$existing['status']==='active'?$existing['id']:'reg_'.bin2hex(random_bytes(16));
                $this->store->execute('UPDATE '.$this->store->table('sites').' SET id=?,token_sealed=?,status=?,created_at=? WHERE id=?',[$id,$this->vault->seal($token,'site:'.$owner.':'.$id),'active',$now,$existing['id']]);
                $this->store->audit($owner,$id,'site_reconnected','',$now);
                return ['id'=>$id,'home'=>$home,'plugin_site_id'=>$pluginSite,'installation_id'=>$installation,'status'=>'active'];
            }
            if ((int)$this->store->row('SELECT COUNT(*) AS n FROM '.$this->store->table('sites').' WHERE owner=?',[$owner])['n']>=self::MAX_SITES) throw new Fault('pilot_site_count_limit',409);
            $id='reg_'.bin2hex(random_bytes(16));
            try {
                $this->store->execute('INSERT INTO '.$this->store->table('sites').' (id,owner,home,url_hash,plugin_site_id,installation_id,token_sealed,status,created_at) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$owner,$home,$hash,$pluginSite,$installation,$this->vault->seal($token,'site:'.$owner.':'.$id),'active',$now]);
            } catch (\PDOException $e) {
                // Another request may have registered the same canonical URL
                // after our initial lookup but before this INSERT. On MySQL the
                // winning row can still be uncommitted/invisible to this
                // transaction, so do not rely on a follow-up SELECT. Normalize
                // only duplicate-key integrity violations; propagate unrelated
                // storage failures.
                $driverCode=(int)($e->errorInfo[1]??0);
                if ((string)$e->getCode()==='23000' && $driverCode===1062) {
                    throw new Fault('site_already_registered',409);
                }
                // SQLite duplicate uniqueness is also an ownership race.
                if ((string)$e->getCode()==='23000' || str_contains(strtolower($e->getMessage()),'unique constraint failed')) {
                    throw new Fault('site_already_registered',409);
                }
                throw $e;
            }
            $this->store->audit($owner,$id,'site_connected','',$now);
            return ['id'=>$id,'home'=>$home,'plugin_site_id'=>$pluginSite,'installation_id'=>$installation,'status'=>'active'];
        });
    }
    public function disconnect(string $owner,string $id,int $now): void {
        $this->store->transaction(function() use($owner,$id,$now) {
            $this->account($owner,true); $this->ownedSite($owner,$id);
            $this->store->execute('UPDATE '.$this->store->table('sites').' SET status=?,token_sealed=? WHERE owner=? AND id=?',['revoked','',$owner,$id]);
            $this->store->execute('UPDATE '.$this->store->table('accounts').' SET sequence=sequence+1 WHERE owner=?',[$owner]);
            $this->store->audit($owner,$id,'site_disconnected','',$now);
        });
    }
    public function mint(string $owner,array $siteIds,array $scopes,int $now,int $ttl=3600): array {
        if (!$siteIds || count($siteIds)>self::MAX_SITES || !array_is_list($siteIds) || !array_is_list($scopes) || count($scopes)>2 || array_filter($siteIds,fn($v)=>!is_string($v)) || array_filter($scopes,fn($v)=>!is_string($v))) throw new Fault('invalid_token_policy');
        if (count(array_unique($siteIds,SORT_STRING))!==count($siteIds) || !in_array('mcp:read',$scopes,true) || array_diff($scopes,['mcp:read','mcp:write']) || count(array_unique($scopes))!==count($scopes) || $ttl<60 || $ttl>3600) throw new Fault('invalid_token_policy');

        // Serialize bearer issuance with account suspension. Without the account
        // row lock, a mint that read "active" just before suspension could
        // insert a fresh non-revoked token after the suspension's bulk revoke.
        return $this->store->transaction(function() use($owner,$siteIds,$scopes,$now,$ttl) {
            $this->account($owner,true);
            foreach ($siteIds as $id) $this->ownedSite($owner,Security::identifier($id));
            $token='wpai_cloud_'.Security::b64(random_bytes(32));
            $hash=hash('sha256',$token);
            $this->store->execute('INSERT INTO '.$this->store->table('tokens').' (hash,owner,scopes,site_ids,expires_at) VALUES (?,?,?,?,?)',[$hash,$owner,Security::canonical($scopes),Security::canonical($siteIds),$now+$ttl]);
            $this->store->audit($owner,'','token_issued',$hash,$now);
            return ['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>$ttl,'scope'=>implode(' ',$scopes),'site_ids'=>$siteIds];
        });
    }
    public function authenticate(string $token,int $now): array {
        if (!preg_match('/^wpai_cloud_[A-Za-z0-9_-]{43}$/D',$token)) throw new Fault('bearer_required',401);
        $row=$this->store->row('SELECT * FROM '.$this->store->table('tokens').' WHERE hash=? AND revoked=0 AND expires_at>?',[hash('sha256',$token),$now]);
        if (!$row) throw new Fault('invalid_or_expired_token',401);
        $this->account($row['owner']);
        $this->throttle($row['owner'],'mcp_http',$now,120);
        return ['owner'=>$row['owner'],'scopes'=>json_decode($row['scopes'],true,8,JSON_THROW_ON_ERROR),'site_ids'=>json_decode($row['site_ids'],true,8,JSON_THROW_ON_ERROR),'token_hash'=>$row['hash']];
    }
    public function revokeToken(string $owner,string $hash,int $now): void {
        if (!preg_match('/^[a-f0-9]{64}$/D',$hash)) throw new Fault('invalid_token_hash');
        $this->store->execute('UPDATE '.$this->store->table('tokens').' SET revoked=1 WHERE owner=? AND hash=?',[$owner,$hash]);
        $this->store->audit($owner,'','token_revoked',$hash,$now);
    }
    private function authorize(array $actor,string $site,string $scope,int $now): array {
        // Re-check revocation for every invocation, including replayed cached responses.
        $row=$this->store->row('SELECT hash FROM '.$this->store->table('tokens').' WHERE hash=? AND owner=? AND revoked=0 AND expires_at>?',[$actor['token_hash'],$actor['owner'],$now]);
        if (!$row) throw new Fault('invalid_or_expired_token',401);
        if (!in_array($scope,$actor['scopes'],true) || !in_array($site,$actor['site_ids'],true)) throw new Fault('scope_or_site_denied',403);
        $this->account($actor['owner']); return $this->ownedSite($actor['owner'],$site);
    }
    private function upstream(array $site,array $request): array {
        $token=$this->vault->open($site['token_sealed'],'site:'.$site['owner'].':'.$site['id']);
        return $this->transport->rpc($site['home'],$token,$request);
    }
    public function catalog(array $actor,string $siteId,int $now): array {
        $site=$this->authorize($actor,$siteId,'mcp:read',$now);
        $reply=$this->upstream($site,['jsonrpc'=>'2.0','id'=>'catalog-'.bin2hex(random_bytes(8)),'method'=>'tools/list']);
        $tools=$reply['result']['tools']??null;
        if (!is_array($tools) || count($tools)>500) throw new Fault('invalid_upstream_catalog',502);
        return $tools;
    }
    public static function writes(string $name,array $args): bool {
        $name=str_replace('.','_',$name);
        if ($name==='wordpress_execute') return ($args['mode']??'read')!=='read';
        return !in_array($name,self::READ_TOOLS,true);
    }
    public function usage(string $owner,int $now): array {
        $a=$this->account($owner); $terms=QuotaPolicy::terms($this->policy,$a); $window=QuotaPolicy::window($terms,$now); $period=$window['period'];
        $b=$this->store->row('SELECT used,reserved FROM '.$this->store->table('balances').' WHERE owner=? AND period=?',[$owner,$period]) ?? ['used'=>0,'reserved'=>0];
        $limit=$terms['limit'];
        return $window+$terms+['plan'=>$a['plan'],'timezone'=>'UTC','limit'=>$limit,'used'=>(int)$b['used'],'reserved'=>(int)$b['reserved'],'remaining'=>max(0,$limit-(int)$b['used']-(int)$b['reserved']),'policy_version'=>$this->policy['version'],'batch_available'=>$this->batchAvailable()];
    }
    public function batchAvailable(): bool {return ($this->policy['batch']??'')===BatchAccounting::POLICY;}
    private function batchAccounting(): BatchAccounting {return new BatchAccounting($this->store,$this->vault,$this->transport,$this->policy);}
    public function batchCall(array $actor,string $siteId,array $items,string $nonce,bool $stopOnError,int $now): array {
        $items=BatchAccounting::items($items);
        $site=$this->authorize($actor,$siteId,'mcp:read',$now);
        foreach($items as $item)if(self::writes($item['tool'],$item['arguments']))$this->authorize($actor,$siteId,'mcp:write',$now);
        return $this->batchAccounting()->call($actor,$site,$items,$nonce,$stopOnError,$now,fn($scope,$at)=>$this->authorize($actor,$siteId,$scope,$at),fn($at)=>$this->catalog($actor,$siteId,$at));
    }
    public function batchReceipt(array $actor,string $siteId,string $key,int $now): array {
        $this->authorize($actor,$siteId,'mcp:read',$now);
        return $this->batchAccounting()->receipt($actor['owner'],$siteId,$key);
    }
    public function call(array $actor,string $siteId,string $name,array $args,string $nonce,int $now): array {
        Security::identifier($name); Security::identifier($nonce);
        $site=$this->authorize($actor,$siteId,self::writes($name,$args)?'mcp:write':'mcp:read',$now);
        $normalized=str_replace('.','_',$name);
        if (in_array($normalized,['batch_create','batch_resume','batch_pause','batch_retry','batch_cancel','system_regression_run','system_regression_matrix'],true)) throw new Fault('batch_receipt_accounting_not_ready',422);
        if (strlen(Security::canonical($args))>65536) throw new Fault('arguments_too_large',413);
        $fingerprint=hash('sha256',Security::canonical([$siteId,$name,$args])); $key=hash('sha256',$actor['owner'].'|'.$nonce);
        $units=self::units($name);
        $reservation=$this->store->transaction(function() use ($actor,$siteId,$name,$fingerprint,$key,$now,$units) {
            $a=$this->account($actor['owner'],true); $this->ownedSite($actor['owner'],$siteId);
            $previous=$this->store->row('SELECT * FROM '.$this->store->table('requests').' WHERE request_key=?',[$key]);
            if ($previous) {
                if (!hash_equals($previous['fingerprint'],$fingerprint)) throw new Fault('idempotency_conflict',409);
                if (!in_array($previous['state'],['succeeded','failed'],true)) {
                    if ($this->abandonedDispatch($previous,$now)) {
                        $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,updated_at=? WHERE request_key=? AND state=?',['uncertain',$now,$key,'dispatching']);
                        $this->store->audit($actor['owner'],$siteId,'dispatch_lease_expired',$key,$now);
                    }
                    // Commit the stale -> uncertain fence before returning the
                    // error. Throwing inside this transaction would undo it.
                    return ['pending'=>true];
                }
                return ['cached'=>json_decode($this->vault->open($previous['response_sealed'],'response:'.$actor['owner'].':'.$key),true,64,JSON_THROW_ON_ERROR)];
            }
            $terms=QuotaPolicy::terms($this->policy,$a); $period=QuotaPolicy::window($terms,$now)['period'];
            $this->store->execute($this->store->ignore().' INTO '.$this->store->table('balances').' (owner,period) VALUES (?,?)',[$actor['owner'],$period]);
            $b=$this->store->row('SELECT * FROM '.$this->store->table('balances').' WHERE owner=? AND period=?'.$this->store->lock(),[$actor['owner'],$period]);
            if ($units>0 && (int)$b['used']+(int)$b['reserved']+$units > $terms['limit']) throw new Fault(QuotaPolicy::exhausted($terms),429);
            $this->store->execute('UPDATE '.$this->store->table('balances').' SET reserved=reserved+? WHERE owner=? AND period=?',[$units,$actor['owner'],$period]);
            $this->store->execute('INSERT INTO '.$this->store->table('requests').' (request_key,owner,site_id,period,fingerprint,tool,state,reserved_units,policy_version,response_sealed,reconciliation_evidence,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$key,$actor['owner'],$siteId,$period,$fingerprint,$name,'dispatching',$units,$this->policy['version'],'','',$now,$now]);
            $this->store->audit($actor['owner'],$siteId,'request_reserved',$key,$now);
            return ['key'=>$key,'period'=>$period];
        });
        if (isset($reservation['cached'])) return $reservation['cached'];
        if (isset($reservation['pending'])) throw new Fault('request_outcome_pending_reconciliation',409);
        try {
            $catalog=$this->catalog($actor,$siteId,$now); $names=array_column($catalog,'name');
            if (!in_array($name,$names,true)) {
                $reply=['jsonrpc'=>'2.0','id'=>$key,'error'=>['code'=>-32602,'message'=>'Unknown site tool']];
            } else {
                $reply=$this->upstream($site,['jsonrpc'=>'2.0','id'=>$key,'method'=>'tools/call','params'=>['name'=>$name,'arguments'=>$args ?: (object)[]]]);
            }
            RpcReply::validate($reply,['jsonrpc'=>'2.0','id'=>$key,'method'=>'tools/call']);
            $success=!isset($reply['error']) && ($reply['result']['isError']??false)===false;
            $receipt=['request_key'=>$key,'state'=>$success?'succeeded':'failed','charged_units'=>$success?$units:0,'site_id'=>$siteId,'policy_version'=>$this->policy['version'],'upstream'=>$reply];
            $this->finish($actor['owner'],$siteId,$key,$reservation['period'],$receipt,$success,$now);
            return $receipt;
        } catch (\Throwable $e) {
            // Never retry an upstream write when its outcome is unknown. Keep the slot reserved until evidence-based reconciliation.
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,updated_at=? WHERE request_key=? AND state=?',['uncertain',$now,$key,'dispatching']);
            throw new Fault('request_outcome_pending_reconciliation',502);
        }
    }
    private function abandonedDispatch(array $row,int $now): bool {
        return $row['state']==='dispatching' && $now >= (int)$row['updated_at'] + self::DISPATCH_LEASE_SECONDS;
    }
    private function finish(string $owner,string $site,string $key,string $period,array $receipt,bool $success,int $now): void {
        $sealed=$this->vault->seal(Security::canonical($receipt),'response:'.$owner.':'.$key);
        $this->store->transaction(function() use($owner,$site,$key,$period,$receipt,$success,$sealed,$now) {
            // Even an account disabled during the HTTP request must settle its existing reservation.
            $this->store->row('SELECT owner FROM '.$this->store->table('accounts').' WHERE owner=?'.$this->store->lock(),[$owner]);
            $r=$this->store->row('SELECT state,reserved_units FROM '.$this->store->table('requests').' WHERE request_key=?'.$this->store->lock(),[$key]);
            if (!$r || $r['state']!=='dispatching') throw new Fault('reservation_not_dispatching',409);
            $s=$this->store->execute('UPDATE '.$this->store->table('balances').' SET reserved=reserved-?,used=used+? WHERE owner=? AND period=? AND reserved>=?',[(int)$r['reserved_units'],$receipt['charged_units'],$owner,$period,(int)$r['reserved_units']]);
            if ((int)$r['reserved_units']>0 && $s->rowCount()!==1) throw new Fault('reservation_balance_invalid',503);
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,response_sealed=?,updated_at=? WHERE request_key=?',[$receipt['state'],$sealed,$now,$key]);
            $this->store->audit($owner,$site,$success?'request_succeeded':'request_failed',$key,$now);
        });
    }
    public function foundation(string $owner,string $siteId,int $now): array {
        $a=$this->account($owner); $site=$this->ownedSite($owner,$siteId);
        if (!$this->signer) throw new Fault('signer_not_configured',503);
        return ['purpose'=>'v1_compatibility_proof_only','commercial_entitlement_enforced'=>false,'envelope'=>$this->signer->foundation($site,(int)$a['sequence'],$now),'public_key'=>$this->signer->publicKey()];
    }
    public function throttle(string $owner,string $bucket,int $now,int $limit): void {
        Security::identifier($bucket,20);
        $this->store->transaction(function() use($owner,$bucket,$now,$limit) {
            $this->account($owner,true); $slot=intdiv($now,60);
            $this->store->execute($this->store->ignore().' INTO '.$this->store->table('rate').' (owner,bucket,slot) VALUES (?,?,?)',[$owner,$bucket,$slot]);
            $row=$this->store->row('SELECT hits FROM '.$this->store->table('rate').' WHERE owner=? AND bucket=? AND slot=?',[$owner,$bucket,$slot]);
            if ((int)$row['hits']>=$limit) throw new Fault('rate_limit_exceeded',429);
            $this->store->execute('UPDATE '.$this->store->table('rate').' SET hits=hits+1 WHERE owner=? AND bucket=? AND slot=?',[$owner,$bucket,$slot]);
            $this->store->execute('DELETE FROM '.$this->store->table('rate').' WHERE owner=? AND slot<?',[$owner,$slot-1440]);
        });
    }
    /**
     * Authoritative billing history; no prompts, tool arguments, responses, or telemetry.
     * Optional site allowlist enforces each OAuth grant's explicitly selected sites.
     * Uncertain requests have null charged_units until receipt-based reconciliation.
     */
    public function ledger(string $owner,int $limit=50,?array $allowedSiteIds=null): array {
        $this->account($owner); $limit=max(1,min(100,$limit));
        $sql='SELECT request_key,site_id,period,tool,state,policy_version,response_sealed,created_at,updated_at FROM '.$this->store->table('requests').' WHERE owner=?';
        $params=[$owner];
        if ($allowedSiteIds!==null) {
            $allowedSiteIds=array_values(array_unique(array_filter($allowedSiteIds,static fn($id)=>is_string($id)&&$id!=='')));
            if (!$allowedSiteIds) return [];
            $sql.=' AND site_id IN ('.implode(',',array_fill(0,count($allowedSiteIds),'?')).')';
            array_push($params,...$allowedSiteIds);
        }
        $rows=$this->store->rows($sql.' ORDER BY created_at DESC,request_key DESC LIMIT '.$limit,$params);
        foreach($rows as &$row) {
            $state=(string)$row['state'];
            $charge=null;
            if(in_array($state,['succeeded','failed'],true)) {
                if(!is_string($row['response_sealed']) || $row['response_sealed']==='') throw new Fault('billing_receipt_missing',503);
                try {
                    $receipt=json_decode($this->vault->open($row['response_sealed'],'response:'.$owner.':'.$row['request_key']),true,64,JSON_THROW_ON_ERROR);
                } catch (\Throwable $e) {
                    throw new Fault('billing_receipt_invalid',503);
                }
                if(!is_array($receipt) || !is_int($receipt['charged_units']??null) || $receipt['charged_units']<0) throw new Fault('billing_receipt_invalid',503);
                $charge=$receipt['charged_units'];
            }
            unset($row['response_sealed']);
            if($row['tool']===BatchAccounting::TOOL){
                $batch=$this->batchAccounting()->receipt($owner,$row['site_id'],$row['request_key']);
                $charge=$batch['charged_units'];$row['pending_units']=$batch['pending_units'];
            }
            $row['charged_units']=$charge;
        }
        unset($row);
        return $rows;
    }
    /** Trusted operator only: the HTTP adapter requires manage_options. No customer may assert their own billing outcome. */
    public function reconcile(string $operator,string $owner,string $key,string $outcome,array $evidence,int $now): array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$key) || !in_array($outcome,['succeeded','failed'],true) || !is_string($evidence['note']??null) || strlen($evidence['note'])<20 || strlen($evidence['note'])>2000 || !is_string($evidence['receipt_sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$evidence['receipt_sha256'])) throw new Fault('operator_receipt_evidence_required');
        $batchRow=$this->store->row('SELECT tool FROM '.$this->store->table('requests').' WHERE owner=? AND request_key=?',[$owner,$key]);
        if(($batchRow['tool']??'')===BatchAccounting::TOOL)return $this->batchAccounting()->reconcile($operator,$owner,$key,$outcome,$evidence,$now);
        return $this->store->transaction(function() use($operator,$owner,$key,$outcome,$evidence,$now) {
            // Trusted reconciliation is deliberately allowed for a suspended
            // account so an uncertain pre-suspension request can release/settle
            // its reservation. This path never restores account/site/token access.
            $account=$this->store->row('SELECT owner,disabled FROM '.$this->store->table('accounts').' WHERE owner=?'.$this->store->lock(),[$owner]);
            if(!$account) throw new Fault('account_unavailable',403);
            $row=$this->store->row('SELECT * FROM '.$this->store->table('requests').' WHERE owner=? AND request_key=?'.$this->store->lock(),[$owner,$key]);
            // Fresh dispatches may still be in flight. Expired dispatches can
            // be settled by this trusted, evidence-requiring operator path.
            // The account/request locks fence any late finish() transaction.
            if (!$row || ($row['state']!=='uncertain' && !$this->abandonedDispatch($row,$now))) throw new Fault('request_not_reconcilable',409);
            $receipt=['request_key'=>$key,'site_id'=>$row['site_id'],'state'=>$outcome,'charged_units'=>$outcome==='succeeded'?(int)$row['reserved_units']:0,'policy_version'=>$row['policy_version'],'reconciled'=>true,'message'=>'Operator reconciled from independently checked site receipt; do not redispatch.'];
            $s=$this->store->execute('UPDATE '.$this->store->table('balances').' SET reserved=reserved-?,used=used+? WHERE owner=? AND period=? AND reserved>=?',[(int)$row['reserved_units'],$receipt['charged_units'],$owner,$row['period'],(int)$row['reserved_units']]);
            if ((int)$row['reserved_units']>0 && $s->rowCount()!==1) throw new Fault('reservation_balance_invalid',503);
            $proof=$this->vault->seal(Security::canonical(['operator'=>$operator,'evidence'=>$evidence]),'evidence:'.$owner.':'.$key);
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,response_sealed=?,reconciliation_evidence=?,updated_at=? WHERE request_key=?',[$outcome,$this->vault->seal(Security::canonical($receipt),'response:'.$owner.':'.$key),$proof,$now,$key]);
            $this->store->audit($owner,$row['site_id'],'operator_reconciled',$key,$now);
            return $receipt;
        });
    }
    public function reactivateAccount(string $operator,string $owner,int $now): array {
        return $this->store->transaction(function() use($operator,$owner,$now) {
            $row=$this->store->row('SELECT owner,disabled FROM '.$this->store->table('accounts').' WHERE owner=?'.$this->store->lock(),[$owner]);
            if(!$row) throw new Fault('account_unavailable',404);
            if((int)$row['disabled']===0) throw new Fault('account_already_active',409);
            $this->store->execute('UPDATE '.$this->store->table('accounts').' SET disabled=0,sequence=sequence+1 WHERE owner=?',[$owner]);
            $this->store->audit($owner,'','operator_account_reactivated',hash('sha256',$operator),$now);
            return [
                'reactivated'=>true,
                'old_access_restored'=>false,
                'reconnect_required'=>true,
                'oauth_reauthorization_required'=>true,
            ];
        });
    }

    public function setPlan(string $operator,string $owner,string $plan,int $now): void {
        if (!isset($this->policy['plans'][$plan])) throw new Fault('unknown_plan');
        $this->store->transaction(function() use($operator,$owner,$plan,$now) {
            $this->account($owner,true);
            $this->store->execute('UPDATE '.$this->store->table('accounts').' SET plan=?,quota_scheme=?,sequence=sequence+1 WHERE owner=?',[$plan,QuotaPolicy::scheme($this->policy),$owner]);
            $this->store->audit($owner,'','operator_plan_changed',hash('sha256',$operator.'|'.$plan),$now);
        });
    }
}
