<?php
declare(strict_types=1);
namespace WPAICloud;

final class Gateway {
    public const VERSIONS=['2025-03-26','2025-06-18','2025-11-25'];
    public function __construct(private readonly Core $core) {}
    public static function tools(): array {
        $site=['type'=>'string','description'=>'Explicit registered site ID; never inferred from a global active site.'];
        $tool=function(string $name,string $description,array $properties,array $required,bool $read):array {
            return ['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>!$read,'idempotentHint'=>true,'openWorldHint'=>false]];
        };
        return [
            $tool('cloud_sites_list','List only connected sites allowed by this access token.',[],[],true),
            $tool('cloud_usage','Show central monthly quota and pending reservations.',[],[],true),
            $tool('cloud_site_tools_list','Get the complete tool catalog of an explicitly selected site. Identical features on all plans.',['site_id'=>$site],['site_id'],true),
            $tool('cloud_site_tool_call','Execute a site tool through its local security and approval lifecycle. A successful single call costs one action; failed calls cost zero. Never retry an uncertain write with a new idempotency_key. Batch execution is unavailable until receipt-based accounting is implemented.',
                ['site_id'=>$site,'tool'=>['type'=>'string'],'arguments'=>['type'=>'object'],'idempotency_key'=>['type'=>'string','description'=>'Stable unique ID of this logical action, reused verbatim for retries; different actions require different IDs.']],['site_id','tool','arguments','idempotency_key'],false)
        ];
    }
    public function handle(array $actor,mixed $message,int $now): ?array {
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc']??null)!=='2.0' || !is_string($message['method']??null) || (isset($message['id']) && !is_string($message['id']) && !is_int($message['id']))) throw new Fault('invalid_json_rpc',400);
        $method=$message['method'];
        if (!array_key_exists('id',$message)) {
            if ($method==='notifications/initialized' || $method==='notifications/cancelled') return null;
            throw new Fault('unsupported_notification',400);
        }
        if ($message['id']===null) throw new Fault('null_request_id',400);
        $id=$message['id']; $p=$message['params']??[];
        if (!is_array($p)) throw new Fault('invalid_params',400);
        $reply=fn($r)=>['jsonrpc'=>'2.0','id'=>$id,'result'=>$r];
        if ($method==='initialize') {
            $requested=$p['protocolVersion']??'';
            $version=in_array($requested,self::VERSIONS,true)?$requested:'2025-11-25';
            return $reply(['protocolVersion'=>$version,'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'wp-ai-cloud','version'=>'0.1.0'],'instructions'=>'Pilot gateway. Always select site_id explicitly. For an uncertain outcome reconcile the existing action before issuing another mutation. OAuth onboarding and batch receipt accounting are not available in this build.']);
        }
        if ($method==='ping') return $reply((object)[]);
        if ($method==='tools/list') return $reply(['tools'=>self::tools()]);
        if ($method!=='tools/call') return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']];
        $name=$p['name']??''; $args=$p['arguments']??[];
        if (!is_string($name) || !is_array($args)) throw new Fault('invalid_tool_call');
        try {
            $definition=null; foreach (self::tools() as $t) if ($t['name']===$name) $definition=$t;
            if (!$definition) throw new Fault('unknown_gateway_tool');
            $properties=(array)$definition['inputSchema']['properties'];
            if (array_diff(array_keys($args),array_keys($properties))) throw new Fault('unexpected_argument');
            foreach ($definition['inputSchema']['required'] as $key) if (!array_key_exists($key,$args)) throw new Fault('missing_argument');
            foreach ($properties as $key=>$rule) if (array_key_exists($key,$args)) {
                if ($rule['type']==='string' && !is_string($args[$key])) throw new Fault('invalid_argument_type');
                if ($rule['type']==='object' && (!is_array($args[$key]) || ($args[$key]!==[] && array_is_list($args[$key])))) throw new Fault('invalid_argument_type');
            }
            if (!in_array('mcp:read',$actor['scopes'],true)) throw new Fault('read_scope_required',403);
            $value=match($name) {
                'cloud_sites_list'=>array_values(array_filter($this->core->sites($actor['owner']),fn($s)=>in_array($s['id'],$actor['site_ids'],true))),
                'cloud_usage'=>$this->core->usage($actor['owner'],$now),
                'cloud_site_tools_list'=>$this->core->catalog($actor,$args['site_id'],$now),
                'cloud_site_tool_call'=>$this->core->call($actor,$args['site_id'],$args['tool'],$args['arguments'],$args['idempotency_key'],$now)
            };
            $failed=$name==='cloud_site_tool_call' && ($value['state']??'')==='failed';
            return $reply(['content'=>[['type'=>'text','text'=>Security::canonical($value)]],'isError'=>$failed]);
        } catch(Fault $e) {
            return $reply(['content'=>[['type'=>'text','text'=>Security::canonical(['error'=>$e->reason,'status'=>$e->status])]],'isError'=>true]);
        }
    }
}
