<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/** Bounded gateway orchestration; each item is an existing Native read/sandbox call. */
final class BatchAccounting {
    public const TOOL='cloud_site_batch_call';
    public const POLICY='successful_object_v1';
    public const MAX_ITEMS=20;
    public const TOOLS=['wp_get_site','wp_read_content','wp_read_post','wp_read_meta','wp_read_option','wp_rest_read','woocommerce_get_product','woocommerce_search_products','woocommerce_get_variations','woocommerce_search_orders','gutenberg_get_outline','builder_detect','file_read','content_patch','gutenberg_patch','woocommerce_patch','woocommerce_update_product_sandbox','elementor_create_draft','elementor_patch_widget','elementor_patch_fragment','elementor_patch_settings','file_write_sandbox'];
    public function __construct(private readonly Store $store,private readonly Vault $vault,private readonly Transport $transport,private readonly array $policy) {}
    public static function items(array $items): array {
        if(!array_is_list($items)||count($items)<1||count($items)>self::MAX_ITEMS)throw new Fault('batch_item_limit',422);
        $ids=[];
        foreach($items as &$item){
            if(!is_array($item)||array_diff(array_keys($item),['id','tool','arguments'])||!isset($item['id'],$item['tool'],$item['arguments']))throw new Fault('invalid_batch_item',422);
            Security::identifier($item['id'],64);Security::identifier($item['tool']);
            if(isset($ids[$item['id']]))throw new Fault('duplicate_batch_step',422);$ids[$item['id']]=true;
            $item['tool']=str_replace('.','_',$item['tool']);
            if(!in_array($item['tool'],self::TOOLS,true))throw new Fault('batch_tool_not_allowed',422);
            if(!is_array($item['arguments'])||($item['arguments']!==[]&&array_is_list($item['arguments'])))throw new Fault('invalid_batch_arguments',422);
            if(in_array($item['tool'],['woocommerce_patch','woocommerce_update_product_sandbox'],true)){
                if(isset($item['arguments']['changes'])&&!is_array($item['arguments']['changes']))throw new Fault('invalid_batch_arguments',422);
                if(array_key_exists('variations',$item['arguments']['changes']??[]))throw new Fault('batch_multiple_targets_not_allowed',422);
            }
        }
        unset($item);
        if(strlen(Security::canonical($items))>65536)throw new Fault('arguments_too_large',413);
        return $items;
    }
    /** wpdb returns BIGINT IDs as canonical decimal strings; reject floats and overflow. */
    private static function operationId(mixed $value): ?int {
        if(is_int($value))return $value>0?$value:null;
        if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,18}$/D',$value))return null;
        $max=(string)PHP_INT_MAX;
        if(strlen($value)>strlen($max)||(strlen($value)===strlen($max)&&strcmp($value,$max)>0))return null;
        return (int)$value;
    }
    private function row(string $owner,string $key,bool $lock=false): array {
        $row=$this->store->row('SELECT * FROM '.$this->store->table('requests').' WHERE owner=? AND request_key=?'.($lock?$this->store->lock():''),[$owner,$key]);
        if(!$row||$row['tool']!==self::TOOL)throw new Fault('batch_request_unavailable',404);return $row;
    }
    private function lockAccount(string $owner): array {
        $a=$this->store->row('SELECT * FROM '.$this->store->table('accounts').' WHERE owner=?'.$this->store->lock(),[$owner]);
        if(!$a)throw new Fault('account_unavailable',403);return $a;
    }
    private function stepKey(string $parent,string $step): string {return hash('sha256',$parent.'|'.$step);}
    private function sealStep(array $row,string $state,?string $digest=null,?int $operation=null): string {
        return $this->vault->seal(Security::canonical(['step_id'=>$row['step_id'],'tool'=>$row['tool'],'state'=>$state,'charged_units'=>$state==='succeeded'?1:($state==='uncertain'?null:0),'result_sha256'=>$digest,'operation_id'=>$operation]),'batch-step:'.$row['owner'].':'.$row['request_key'].':'.$row['step_id']);
    }
    private function steps(string $key): array {return $this->store->rows('SELECT * FROM '.$this->store->table('batch_steps').' WHERE request_key=? ORDER BY ordinal',[$key]);}
    private function summary(array $r): array {
        $steps=[];$used=0;$pending=0;$counts=['succeeded'=>0,'failed'=>0,'cancelled'=>0,'uncertain'=>0,'dispatching'=>0,'planned'=>0];
        foreach($this->steps($r['request_key']) as $s){
            if(!array_key_exists($s['state'],$counts))throw new Fault('batch_receipt_invalid',503);$counts[$s['state']]++;
            if(in_array($s['state'],['succeeded','failed','cancelled','uncertain'],true)){
                try{$v=json_decode($this->vault->open($s['receipt_sealed'],'batch-step:'.$r['owner'].':'.$r['request_key'].':'.$s['step_id']),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Fault('batch_receipt_invalid',503);}
                $expected=$s['state']==='succeeded'?1:($s['state']==='uncertain'?null:0);
                if(!is_array($v)||($v['step_id']??null)!==$s['step_id']||($v['tool']??null)!==$s['tool']||($v['state']??null)!==$s['state']||!array_key_exists('charged_units',$v)||$v['charged_units']!==$expected||(int)$s['charged_units']!==($s['state']==='succeeded'?1:0))throw new Fault('batch_receipt_invalid',503);
            }else{$v=['step_id'=>$s['step_id'],'tool'=>$s['tool'],'state'=>$s['state'],'charged_units'=>null,'operation_id'=>null];}
            $used+=(int)($v['charged_units']??0);if(in_array($s['state'],['planned','dispatching','uncertain'],true))$pending++;
            $v['receipt_id']=$this->stepKey($r['request_key'],$s['step_id']);$steps[]=$v;
        }
        if($pending!==(int)$r['reserved_units'])throw new Fault('batch_reservation_invalid',503);
        return ['request_key'=>$r['request_key'],'site_id'=>$r['site_id'],'period'=>$r['period'],'state'=>$r['state'],'batch_outcome'=>$pending?'uncertain':($counts['succeeded']===count($steps)?'completed':($used?'partial':'failed')),'charged_units'=>$used,'pending_units'=>$pending,'policy_version'=>$r['policy_version'],'batch_policy'=>self::POLICY,'original_output_retained'=>false,'summary'=>$counts,'steps'=>$steps];
    }
    private function cached(array $r): array {
        try{$v=json_decode($this->vault->open($r['response_sealed'],'response:'.$r['owner'].':'.$r['request_key']),true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Fault('billing_receipt_invalid',503);}
        if(!is_array($v)||($v['request_key']??'')!==$r['request_key']||($v['site_id']??'')!==$r['site_id']||!is_int($v['charged_units']??null)||($v['state']??'')!==$r['state'])throw new Fault('billing_receipt_invalid',503);return $v;
    }
    public function receipt(string $owner,string $site,string $key): array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new Fault('invalid_request_key');
        return $this->store->transaction(function()use($owner,$site,$key){
            $this->lockAccount($owner);$r=$this->row($owner,$key,true);
            if($r['site_id']!==$site)throw new Fault('batch_request_unavailable',404);
            return in_array($r['state'],['succeeded','failed'],true)?$this->cached($r):$this->summary($r);
        });
    }
    public function call(array $actor,array $site,array $items,string $nonce,bool $stopOnError,int $now,\Closure $authorize,\Closure $catalog): array {
        Security::identifier($nonce);$owner=$actor['owner'];$key=hash('sha256',$owner.'|'.$nonce);$started=time();
        $fingerprint=hash('sha256',Security::canonical(['batch_v1',$site['id'],$items,$stopOnError]));
        $reserved=$this->store->transaction(function()use($owner,$key,$fingerprint,$site,$items,$now,$authorize){
            $a=$this->lockAccount($owner);$authorize('mcp:read',$now);
            $previous=$this->store->row('SELECT * FROM '.$this->store->table('requests').' WHERE request_key=?',[$key]);
            if($previous){
                if($previous['owner']!==$owner||$previous['tool']!==self::TOOL||!hash_equals($previous['fingerprint'],$fingerprint))throw new Fault('idempotency_conflict',409);
                if(in_array($previous['state'],['succeeded','failed'],true))return ['cached'=>$this->cached($previous)];
                return ['pending'=>true];
            }
            if(($this->policy['batch']??'')!==self::POLICY)throw new Fault('batch_receipt_accounting_not_ready',422);
            if((int)$a['disabled']!==0)throw new Fault('account_unavailable',403);
            $terms=QuotaPolicy::terms($this->policy,$a);
            $period=QuotaPolicy::window($terms,$now)['period'];$n=count($items);
            $this->store->execute($this->store->ignore().' INTO '.$this->store->table('balances').' (owner,period) VALUES (?,?)',[$owner,$period]);
            $b=$this->store->row('SELECT used,reserved FROM '.$this->store->table('balances').' WHERE owner=? AND period=?'.$this->store->lock(),[$owner,$period]);
            if((int)$b['used']+(int)$b['reserved']+$n>$terms['limit'])throw new Fault(QuotaPolicy::exhausted($terms),429);
            $this->store->execute('UPDATE '.$this->store->table('balances').' SET reserved=reserved+? WHERE owner=? AND period=?',[$n,$owner,$period]);
            $this->store->execute('INSERT INTO '.$this->store->table('requests').' (request_key,owner,site_id,period,fingerprint,tool,state,reserved_units,policy_version,response_sealed,reconciliation_evidence,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$key,$owner,$site['id'],$period,$fingerprint,self::TOOL,'dispatching',$n,$this->policy['version'],'','',$now,$now]);
            foreach($items as $i=>$item)$this->store->execute('INSERT INTO '.$this->store->table('batch_steps').' (request_key,step_id,owner,site_id,ordinal,tool,fingerprint,state,charged_units,receipt_sealed,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',[$key,$item['id'],$owner,$site['id'],$i,$item['tool'],hash('sha256',Security::canonical($item['arguments'])),'planned',0,'',$now,$now]);
            $this->store->audit($owner,$site['id'],'batch_reserved',$key,$now);return [];
        });
        if(isset($reserved['cached']))return $reserved['cached'];
        if(isset($reserved['pending']))throw new Fault('batch_outcome_pending_reconciliation',409);
        // Catalog failure cannot mean an item executed: no item has been dispatched yet.
        try{$names=[];foreach($catalog($now) as $definition){$name=$definition['name']??null;if(is_string($name))$names[str_replace('.','_',$name)]=$name;}}catch(\Throwable $e){return $this->close($owner,$key,$now);}
        $outputs=[];
        foreach($items as $item){
            $at=$now+max(0,time()-$started);
            try{
                $this->store->transaction(function()use($owner,$key,$item,$at,$authorize){
                    $this->lockAccount($owner);$authorize(Core::writes($item['tool'],$item['arguments'])?'mcp:write':'mcp:read',$at);$r=$this->row($owner,$key,true);
                    if($r['state']!=='dispatching')throw new Fault('reservation_not_dispatching',409);
                    $s=$this->store->execute('UPDATE '.$this->store->table('batch_steps').' SET state=?,updated_at=? WHERE request_key=? AND step_id=? AND state=?',['dispatching',$at,$key,$item['id'],'planned']);
                    if($s->rowCount()!==1)throw new Fault('batch_step_not_planned',409);
                    $this->store->execute('UPDATE '.$this->store->table('requests').' SET updated_at=? WHERE request_key=?',[$at,$key]);
                });
            }catch(\Throwable $e){return $this->close($owner,$key,$now+max(0,time()-$started));}
            $request=['jsonrpc'=>'2.0','id'=>$this->stepKey($key,$item['id']),'method'=>'tools/call','params'=>['name'=>$names[$item['tool']]??$item['tool'],'arguments'=>$item['arguments']?:(object)[]]];
            try{
                if(!isset($names[$item['tool']]))$reply=['jsonrpc'=>'2.0','id'=>$request['id'],'error'=>['code'=>-32602,'message'=>'Unknown site tool']];
                else $reply=$this->transport->rpc($site['home'],$this->vault->open($site['token_sealed'],'site:'.$owner.':'.$site['id']),$request);
                RpcReply::validate($reply,$request);$ok=!isset($reply['error'])&&($reply['result']['isError']??false)===false;$operation=null;
                if($ok&&Core::writes($item['tool'],$item['arguments'])){
                    foreach($reply['result']['content'] as $block)if(($block['type']??'')==='text'){$data=json_decode($block['text'],true);if(is_array($data)&&($parsed=self::operationId($data['id']??null))!==null){$operation=$parsed;break;}}
                    if($operation===null)throw new Fault('batch_operation_receipt_missing',502);
                }
                $this->finishStep($owner,$key,$item['id'],$ok,hash('sha256',Security::canonical($reply)),$operation,$now+max(0,time()-$started));
                $outputs[]=['step_id'=>$item['id'],'upstream'=>$reply];
                if(!$ok&&$stopOnError)break;
            }catch(\Throwable $e){return $this->close($owner,$key,$now+max(0,time()-$started));}
        }
        $receipt=$this->close($owner,$key,$now+max(0,time()-$started));$receipt['results']=$outputs;return $receipt;
    }
    private function balance(array $r,int $release,int $charge): void {
        if($release<0||$charge<0||$charge>$release||(int)$r['reserved_units']<$release)throw new Fault('batch_reservation_invalid',503);
        if($release===0)return;
        $s=$this->store->execute('UPDATE '.$this->store->table('balances').' SET reserved=reserved-?,used=used+? WHERE owner=? AND period=? AND reserved>=?',[$release,$charge,$r['owner'],$r['period'],$release]);
        if($s->rowCount()!==1)throw new Fault('reservation_balance_invalid',503);
        $this->store->execute('UPDATE '.$this->store->table('requests').' SET reserved_units=reserved_units-? WHERE request_key=?',[$release,$r['request_key']]);
    }
    private function finishStep(string $owner,string $key,string $id,bool $ok,string $digest,?int $operation,int $at): void {
        $this->store->transaction(function()use($owner,$key,$id,$ok,$digest,$operation,$at){
            $this->lockAccount($owner);$r=$this->row($owner,$key,true);if($r['state']!=='dispatching')throw new Fault('reservation_not_dispatching',409);
            $s=$this->store->row('SELECT * FROM '.$this->store->table('batch_steps').' WHERE request_key=? AND step_id=?'.$this->store->lock(),[$key,$id]);
            if(!$s||$s['state']!=='dispatching')throw new Fault('batch_step_not_dispatching',409);
            $state=$ok?'succeeded':'failed';$sealed=$this->sealStep($s,$state,$digest,$operation);$this->balance($r,1,$ok?1:0);
            $this->store->execute('UPDATE '.$this->store->table('batch_steps').' SET state=?,charged_units=?,receipt_sealed=?,updated_at=? WHERE request_key=? AND step_id=?',[$state,$ok?1:0,$sealed,$at,$key,$id]);
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET updated_at=? WHERE request_key=?',[$at,$key]);
        });
    }
    /** Stop scheduling, preserve unknown dispatches and release only never-started items. */
    private function close(string $owner,string $key,int $at): array {
        try{return $this->store->transaction(function()use($owner,$key,$at){
            $this->lockAccount($owner);$r=$this->row($owner,$key,true);if(in_array($r['state'],['succeeded','failed'],true))return $this->cached($r);
            $released=0;
            foreach($this->steps($key) as $s){
                if(!in_array($s['state'],['planned','dispatching'],true))continue;
                $state=$s['state']==='planned'?'cancelled':'uncertain';if($state==='cancelled')$released++;
                $this->store->execute('UPDATE '.$this->store->table('batch_steps').' SET state=?,receipt_sealed=?,updated_at=? WHERE request_key=? AND step_id=?',[$state,$this->sealStep($s,$state),$at,$key,$s['step_id']]);
            }
            $this->balance($r,$released,0);$r=$this->row($owner,$key);$v=$this->summary($r);$state=$v['pending_units']?'uncertain':($v['charged_units']>0?'succeeded':'failed');$v['state']=$state;
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,response_sealed=?,updated_at=? WHERE request_key=?',[$state,$this->vault->seal(Security::canonical($v),'response:'.$owner.':'.$key),$at,$key]);
            $this->store->audit($owner,$r['site_id'],'batch_checkpoint',$key,$at);return $v;
        });}catch(Fault $e){throw $e;}catch(\Throwable $e){throw new Fault('batch_outcome_pending_reconciliation',503);}
    }
    /** Operator evidence resolves at most one dispatched item, never arbitrary client counts. */
    public function reconcile(string $operator,string $owner,string $key,string $outcome,array $evidence,int $now): array {
        return $this->store->transaction(function()use($operator,$owner,$key,$outcome,$evidence,$now){
            $this->lockAccount($owner);$r=$this->row($owner,$key,true);
            if($r['state']!=='uncertain'&& !($r['state']==='dispatching'&&$now>=(int)$r['updated_at']+Core::DISPATCH_LEASE_SECONDS))throw new Fault('request_not_reconcilable',409);
            $unresolved=array_values(array_filter($this->steps($key),fn($s)=>in_array($s['state'],['dispatching','uncertain'],true)));
            if(count($unresolved)>1)throw new Fault('batch_receipt_invalid',503);
            if(!$unresolved&&$outcome!=='failed')throw new Fault('batch_no_dispatched_step',409);
            if($unresolved){$s=$unresolved[0];$this->balance($r,1,$outcome==='succeeded'?1:0);$this->store->execute('UPDATE '.$this->store->table('batch_steps').' SET state=?,charged_units=?,receipt_sealed=?,updated_at=? WHERE request_key=? AND step_id=?',[$outcome,$outcome==='succeeded'?1:0,$this->sealStep($s,$outcome,$evidence['receipt_sha256']),$now,$key,$s['step_id']]);}
            // Fence the old runner before releasing never-dispatched items.
            $this->store->execute('UPDATE '.$this->store->table('requests').' SET state=?,reconciliation_evidence=?,updated_at=? WHERE request_key=?',['uncertain',$this->vault->seal(Security::canonical(['operator'=>$operator,'evidence'=>$evidence]),'evidence:'.$owner.':'.$key),$now,$key]);
            return $this->close($owner,$key,$now);
        });
    }
}
