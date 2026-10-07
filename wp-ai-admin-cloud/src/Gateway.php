<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Gateway {
    public const VERSIONS=['2025-03-26','2025-06-18','2025-11-25'];

    public function __construct(private readonly Core $core) {}

    private static function cloudTools(): array {
        $site=['type'=>'string','description'=>'Explicit registered site ID; never inferred from a global active site.'];
        $tool=function(string $name,string $description,array $properties,array $required,bool $read):array {
            return [
                'name'=>$name,
                'description'=>$description,
                'inputSchema'=>[
                    'type'=>'object',
                    'properties'=>(object)$properties,
                    'required'=>$required,
                    'additionalProperties'=>false
                ],
                'securitySchemes'=>[['type'=>'oauth2','scopes'=>$read?['mcp:read']:['mcp:read','mcp:write']]],
                'annotations'=>[
                    'readOnlyHint'=>$read,
                    'destructiveHint'=>!$read,
                    'idempotentHint'=>true,
                    'openWorldHint'=>false
                ]
            ];
        };
        return [
            $tool('cloud_sites_list','List only connected sites allowed by this access token.',[],[],true),
            $tool('cloud_usage','Show central monthly quota and pending reservations.',[],[],true),
            $tool(
                'cloud_site_tools_list',
                'Get the complete tool catalog of an explicitly selected site. Identical features on all plans.',
                ['site_id'=>$site],
                ['site_id'],
                true
            ),
            $tool(
                'cloud_site_tool_call',
                'Execute a site tool through its local security and approval lifecycle. A successful single call costs one action; failed calls, operation verification, receipts and rollback cost zero even when the monthly quota is exhausted. Never retry an uncertain write with a new idempotency_key.',
                [
                    'site_id'=>$site,
                    'tool'=>['type'=>'string'],
                    'arguments'=>['type'=>'object'],
                    'idempotency_key'=>[
                        'type'=>'string',
                        'description'=>'Stable unique ID of this logical action, reused verbatim for retries; different actions require different IDs.'
                    ]
                ],
                ['site_id','tool','arguments','idempotency_key'],
                false
            )
        ];
    }

    private function publishedTools(array $actor,int $now): array {
        $tools=self::cloudTools();
        $seen=array_fill_keys(array_column($tools,'name'),true);
        $allowed=array_values(array_filter(
            $this->core->sites($actor['owner']),
            fn($site)=>in_array($site['id'],$actor['site_ids'],true)
        ));
        if (!$allowed) return $tools;

        $siteIds=array_values(array_column($allowed,'id'));
        foreach ($siteIds as $siteId) {
            try { $catalog=$this->core->catalog($actor,$siteId,$now); }
            catch (\Throwable $e) { continue; }

            foreach ($catalog as $upstream) {
                if (!is_array($upstream) || !is_string($upstream['name']??null)) continue;
                $name=$upstream['name'];
                if (isset($seen[$name]) || str_starts_with($name,'cloud_')) continue;

                $schema=is_array($upstream['inputSchema']??null)?$upstream['inputSchema']:[];
                $properties=is_array($schema['properties']??null)?$schema['properties']:[];
                if (isset($properties['site_id']) || isset($properties['idempotency_key'])) continue;

                $properties=[
                    'site_id'=>[
                        'type'=>'string',
                        'enum'=>$siteIds,
                        'description'=>'WP AI Admin Cloud registered site ID. Use cloud_sites_list when you need the matching site URL.'
                    ],
                    'idempotency_key'=>[
                        'type'=>'string',
                        'minLength'=>8,
                        'maxLength'=>128,
                        'description'=>'Stable unique ID for this logical call. Reuse the same value if the same call is retried.'
                    ]
                ]+$properties;

                $required=$schema['required']??[];
                if (!is_array($required)) $required=[];
                $required=array_values(array_unique(array_merge(['site_id','idempotency_key'],$required)));

                $proxy=$upstream;
                $proxy['description']='WP AI Admin site tool routed by Cloud Core. '.(string)($upstream['description']??'');
                $proxy['inputSchema']=[
                    'type'=>'object',
                    'properties'=>(object)$properties,
                    'required'=>$required,
                    'additionalProperties'=>false
                ];
                $tools[]=$proxy;
                $seen[$name]=true;
            }
        }
        return $tools;
    }

    private static function toolByName(array $tools,string $name): ?array {
        foreach ($tools as $tool) if (($tool['name']??null)===$name) return $tool;
        return null;
    }

    private static function validateArguments(array $definition,array $args): void {
        $schema=$definition['inputSchema']??[];
        $rawProperties=$schema['properties']??[];
        $properties=is_array($rawProperties)?$rawProperties:(is_object($rawProperties)?(array)$rawProperties:[]);
        if (array_diff(array_keys($args),array_keys($properties))) throw new Fault('unexpected_argument');
        foreach (($schema['required']??[]) as $key) if (!array_key_exists($key,$args)) throw new Fault('missing_argument');

        foreach ($properties as $key=>$rule) {
            if (!array_key_exists($key,$args) || !is_array($rule)) continue;
            $type=$rule['type']??null;
            if ($type==='string' && !is_string($args[$key])) throw new Fault('invalid_argument_type');
            if ($type==='object' && (!is_array($args[$key]) || ($args[$key]!==[] && array_is_list($args[$key])))) throw new Fault('invalid_argument_type');
            if ($type==='array' && !is_array($args[$key])) throw new Fault('invalid_argument_type');
            if ($type==='integer' && !is_int($args[$key])) throw new Fault('invalid_argument_type');
            if ($type==='boolean' && !is_bool($args[$key])) throw new Fault('invalid_argument_type');
            if (isset($rule['enum']) && !in_array($args[$key],$rule['enum'],true)) throw new Fault('invalid_argument_value');
            if (is_string($args[$key]??null)) {
                if (isset($rule['minLength']) && strlen($args[$key])<(int)$rule['minLength']) throw new Fault('invalid_argument_value');
                if (isset($rule['maxLength']) && strlen($args[$key])>(int)$rule['maxLength']) throw new Fault('invalid_argument_value');
            }
        }
    }

    public function handle(array $actor,mixed $message,int $now): ?array {
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc']??null)!=='2.0' || !is_string($message['method']??null) || (isset($message['id']) && !is_string($message['id']) && !is_int($message['id']))) throw new Fault('invalid_json_rpc',400);
        $method=$message['method'];

        if (!array_key_exists('id',$message)) {
            if ($method==='notifications/initialized' || $method==='notifications/cancelled') return null;
            throw new Fault('unsupported_notification',400);
        }
        if ($message['id']===null) throw new Fault('null_request_id',400);

        $id=$message['id'];
        $p=$message['params']??[];
        if (!is_array($p)) throw new Fault('invalid_params',400);
        $reply=fn($r)=>['jsonrpc'=>'2.0','id'=>$id,'result'=>$r];

        if ($method==='initialize') {
            $requested=$p['protocolVersion']??'';
            $version=in_array($requested,self::VERSIONS,true)?$requested:'2025-11-25';
            return $reply([
                'protocolVersion'=>$version,
                'capabilities'=>['tools'=>['listChanged'=>false]],
                'serverInfo'=>['name'=>'wp-ai-admin-cloud','title'=>'https://wpaiadmin.ru/mcp','version'=>'0.3.6'],
                'instructions'=>'Central multi-site WP AI Admin gateway. tools/list publishes Cloud Core tools plus the WP AI Admin site-tool catalog. Every proxied site tool requires site_id and idempotency_key. Use cloud_sites_list to map site IDs to WordPress URLs. Site mutations retain the local sandbox, approval, apply, verify and rollback lifecycle.'
            ]);
        }

        if ($method==='ping') return $reply((object)[]);
        if ($method==='tools/list') return $reply(['tools'=>$this->publishedTools($actor,$now)]);
        if ($method!=='tools/call') return ['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>-32601,'message'=>'Method not found']];

        $name=$p['name']??'';
        $args=$p['arguments']??[];
        if (!is_string($name) || !is_array($args)) throw new Fault('invalid_tool_call');

        try {
            if (!in_array('mcp:read',$actor['scopes'],true)) throw new Fault('read_scope_required',403);

            $cloud=self::toolByName(self::cloudTools(),$name);
            if ($cloud!==null) {
                self::validateArguments($cloud,$args);
                $value=match($name) {
                    'cloud_sites_list'=>array_values(array_filter($this->core->sites($actor['owner']),fn($s)=>in_array($s['id'],$actor['site_ids'],true))),
                    'cloud_usage'=>$this->core->usage($actor['owner'],$now),
                    'cloud_site_tools_list'=>$this->core->catalog($actor,$args['site_id'],$now),
                    'cloud_site_tool_call'=>$this->core->call($actor,$args['site_id'],$args['tool'],$args['arguments'],$args['idempotency_key'],$now)
                };
                $failed=$name==='cloud_site_tool_call' && ($value['state']??'')==='failed';
                return $reply(['content'=>[['type'=>'text','text'=>Security::canonical($value)]],'isError'=>$failed]);
            }

            $published=self::toolByName($this->publishedTools($actor,$now),$name);
            if ($published===null) throw new Fault('unknown_gateway_tool');
            self::validateArguments($published,$args);

            $siteId=$args['site_id'];
            $nonce=$args['idempotency_key'];
            unset($args['site_id'],$args['idempotency_key']);

            $receipt=$this->core->call($actor,$siteId,$name,$args,$nonce,$now);
            $upstream=$receipt['upstream']??null;
            if (is_array($upstream) && isset($upstream['result']) && is_array($upstream['result'])) {
                $result=$upstream['result'];
                $meta=is_array($result['_meta']??null)?$result['_meta']:[];
                $meta['wpai_cloud']=[
                    'site_id'=>$siteId,
                    'request_key'=>$receipt['request_key']??null,
                    'state'=>$receipt['state']??null,
                    'charged_units'=>$receipt['charged_units']??0,
                    'policy_version'=>$receipt['policy_version']??null
                ];
                $result['_meta']=$meta;
                return $reply($result);
            }

            return $reply([
                'content'=>[['type'=>'text','text'=>Security::canonical($receipt)]],
                'isError'=>($receipt['state']??'')!=='succeeded'
            ]);
        } catch(Fault $e) {
            $result=[
                'content'=>[['type'=>'text','text'=>Security::canonical(['error'=>$e->reason,'status'=>$e->status])]],
                'isError'=>true
            ];
            if(in_array($e->reason,['scope_or_site_denied','read_scope_required'],true)&&class_exists(Surface::class)) {
                $result['_meta']=['mcp/www_authenticate'=>str_replace('scope="mcp:read"','scope="mcp:read mcp:write"',Surface::challenge())];
            }
            return $reply($result);
        }
    }
}
