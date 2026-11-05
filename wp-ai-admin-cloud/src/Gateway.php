<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Gateway {
    /** Presentation only; Core retains canonical encrypted billing/evidence bytes. */
    private static function metadataTool(string $tool): bool {
        $name=str_replace('.','_',strtolower($tool));
        return AbilitiesPolicy::isMetadataRead($name) || in_array($name,[
            'wp_get_site','wp_health','wp_diagnostics','system_manifest','system_capabilities','system_tool_catalog',
            'system_security_status','wp_list_operations','operation_get','operation_status','operation_diff',
            'operation_verify','verify_operation','operation_proof','proof_operation','operation_get_receipt',
            'operation_approve','operation_apply','operation_reject','operation_rollback','batch_receipt','batch_proof',
            'woodmart_update_setting_sandbox','wp_option_update_sandbox','gutenberg_patch','content_patch','woocommerce_patch',
            'woocommerce_update_product_sandbox','woocommerce_create_product_sandbox','media_upload_sandbox','media_import_url_sandbox',
            'media_update_alt_sandbox','media_update_title_sandbox','media_set_featured_sandbox','menu_create_sandbox',
            'menu_add_item_sandbox','menu_remove_item_sandbox','menu_move_item_sandbox','menu_assign_location_sandbox',
            'elementor_patch_container_settings','elementor_patch_page_layout','elementor_create_draft','elementor_patch_widget',
            'elementor_patch_fragment','elementor_patch_settings','file_write_sandbox'
        ],true);
    }
    private static function publicSiteReceipt(array $receipt,string $tool): array {
        $reply=$receipt['upstream']??null;
        if (!is_array($reply)) return $receipt;
        // Raw editable reads and arbitrary third-party content are not evidence
        // views. Error replies remain diagnostic even for those tools.
        if (!self::metadataTool($tool) && !isset($reply['error']) && empty($reply['result']['isError'])) return $receipt;
        $changed=false;
        $receipt['upstream']=self::publicMetadata($reply,'',$changed);
        if ($changed) $receipt['_wpai_projection']=[
            'redacted'=>true,'reason'=>'server_filesystem_paths','stored_evidence_modified'=>false,
            'hashes_attest'=>'original_evidence','canonical_payload_available'=>false
        ];
        return $receipt;
    }
    private static function publicMetadata(mixed $value,string $key,bool &$changed): mixed {
        if ($value instanceof \stdClass) return (object)self::publicMetadata(get_object_vars($value),$key,$changed);
        if (is_array($value)) {
            $out=[];
            foreach ($value as $name=>$child) {
                $public=is_string($name)?self::publicPathText($name,'',$changed):$name;
                if ($public!==$name) $public.=':'.substr(hash('sha256',$name),0,12);
                while (array_key_exists($public,$out)) { $changed=true; $public.=':'; }
                $out[$public]=self::publicMetadata($child,(string)$name,$changed);
            }
            return $out;
        }
        if (!is_string($value)) return $value;
        $trim=ltrim($value);
        if ($trim!=='' && in_array($trim[0],['{','['],true)) {
            $decoded=json_decode($value);
            if (is_array($decoded) || $decoded instanceof \stdClass) {
                $nested=false; $public=self::publicMetadata($decoded,$key,$nested);
                if ($nested) { $changed=true; return json_encode($public,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION); }
            } elseif (in_array($key,['text','proof','receipt'],true) && json_last_error()!==JSON_ERROR_NONE) {
                $changed=true; return '[invalid serialized metadata]';
            }
        }
        return self::publicPathText($value,$key,$changed);
    }
    private static function publicPathText(string $text,string $key,bool &$changed): string {
        $original=$text;
        $text=preg_replace('~\bfile://[^\s"\'<>|,;)}\]]+~i','[server-path]',$text);
        $text=preg_replace('~(?<![A-Za-z0-9_:/\\\\.-])(?:[A-Za-z]:[/\\\\]|\\\\\\\\[^\\\\\s]+\\\\)[^\s"\'<>|,;)}\]]+~','[server-path]',$text);
        $text=preg_replace('~(?<![A-Za-z0-9_:/.-])/(?:home|var|srv|opt|tmp|usr|private|mnt|workspace|Users|Applications|Library|Volumes|www|htdocs|data|sites|public_html|inetpub)(?=/|$|[\s"\'<>|,;)}\]])(?:/[^\s"\'<>|,;)}\]]*)?~','[server-path]',$text);
        if (preg_match('/(?:^|_)(?:error|message)$/i',$key)) $text=preg_replace('~(?<![A-Za-z0-9_:/.-])/(?:[^/\s"\'<>|,;)}\]]+/)+[^\s"\'<>|,;)}\]]+~','[server-path]',$text);
        if (preg_match('/(?:^|_)(?:file|path|dir|directory|root|basedir)$/i',$key) && preg_match('~^/(?!/)~',$text)) $text='[server-path]';
        if ($text!==$original) $changed=true;
        return $text;
    }
    public const VERSIONS=['2025-03-26','2025-06-18','2025-11-25'];
    public function __construct(
        private readonly Core $core,
        private readonly ?Skills $skills=null,
        private readonly ?Unsplash $unsplash=null
    ) {}
    private function skillLibrary(): Skills {
        return $this->skills ?? Skills::fromConfig(dirname(__DIR__).'/config/skills.php');
    }
    private function mediaSearch(array $args): array {
        $provider=$this->unsplash;
        if ($provider===null && defined('WP_AI_CLOUD_UNSPLASH_ACCESS_KEY')) {
            $key=constant('WP_AI_CLOUD_UNSPLASH_ACCESS_KEY');
            if (!is_string($key)) throw new Fault('unsplash_disabled',503);
            $provider=new Unsplash($key);
        }
        if ($provider===null) throw new Fault('unsplash_disabled',503);
        return $provider->search($args['query'],$args['per_page']??10);
    }
    public static function tools(): array {
        $site=['type'=>'string','description'=>'Explicit registered site ID; never inferred from a global active site.'];
        $tool=function(string $name,string $description,array $properties,array $required,bool $read):array {
            return ['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>(object)$properties,'required'=>$required,'additionalProperties'=>false],
                'securitySchemes'=>[['type'=>'oauth2','scopes'=>$read?['mcp:read']:['mcp:read','mcp:write']]],'annotations'=>['readOnlyHint'=>$read,'destructiveHint'=>!$read,'idempotentHint'=>true,'openWorldHint'=>false]];
        };
        return [
            $tool('cloud_skills_list','List closed server-side AI workflows. Read-only; human approval is still needed for writes.',[],[],true),
            $tool('cloud_skill_load','Read one server-side workflow, not a command to execute.',['skill_id'=>['type'=>'string']],['skill_id'],true),
            $tool('cloud_media_search_images','Search licensed image metadata through an optional server-side Unsplash key. Never sends the key to a site.',['query'=>['type'=>'string'],'per_page'=>['type'=>'integer','minimum'=>1,'maximum'=>20]],['query'],true),
            $tool('cloud_sites_list','List only connected sites allowed by this access token.',[],[],true),
            $tool('cloud_usage','Show the shared account quota, UTC day or month window and pending reservations.',[],[],true),
            $tool('cloud_usage_history','List authenticated account billing entries for sites selected in this OAuth grant; includes verified charges without tool inputs/outputs.',['limit'=>['type'=>'integer','minimum'=>1,'maximum'=>100]],[],true),
            $tool('cloud_site_batch_call','Run up to 20 existing Native read or sandbox item calls. Reserve the upper bound first; each successful item costs one action and failed/cancelled items cost zero. Sandbox approval/apply remains separate. Server opt-in is required. Reuse idempotency_key on retries; do not resubmit an uncertain item.',
                ['site_id'=>$site,'items'=>['type'=>'array','minItems'=>1,'maxItems'=>20,'items'=>['type'=>'object','properties'=>['id'=>['type'=>'string'],'tool'=>['type'=>'string'],'arguments'=>['type'=>'object']],'required'=>['id','tool','arguments'],'additionalProperties'=>false]],'idempotency_key'=>['type'=>'string'],'stop_on_error'=>['type'=>'boolean']],['site_id','items','idempotency_key'],false),
            $tool('cloud_site_batch_receipt','Read confirmed per-item charges and held unknown outcomes for a batch in an authorized site. Free at capacity. Does not execute or retry any Native tool.',
                ['site_id'=>$site,'request_key'=>['type'=>'string']],['site_id','request_key'],true),
            $tool('cloud_site_tools_list','Get the complete tool catalog of an explicitly selected site. Identical features on all plans.',['site_id'=>$site],['site_id'],true),
            $tool('cloud_site_tool_call','Execute a site tool through its local security and approval lifecycle. A successful single call costs one action; failed calls, operation verification, receipts and rollback cost zero even when the quota is exhausted. Never retry an uncertain write with a new idempotency_key. Legacy Native batch execution is gated; use cloud_site_batch_call only when object-v1 server policy is enabled.',
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
            return $reply(['protocolVersion'=>$version,'capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'wp-ai-admin-cloud','title'=>'https://wpaiadmin.ru/mcp','version'=>\WPAI_CLOUD_VERSION],'instructions'=>'Multi-client beta gateway. One account and shared plan can hold up to 50 sites. Streamable HTTP is primary; legacy SSE is available for Qwen Responses API. Always select site_id explicitly. For an uncertain outcome reconcile the existing action before issuing another mutation. OAuth onboarding uses a WordPress account and explicit site consent. Newly added sites need reauthorization. Bounded gateway batch is available only under explicit object-v1 server policy; Native resumable batch controls remain gated.']);
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
                if ($rule['type']==='integer' && (!is_int($args[$key]) || $args[$key]<1 || $args[$key]>100)) throw new Fault('invalid_argument_type');
                if ($rule['type']==='array' && (!is_array($args[$key]) || !array_is_list($args[$key]))) throw new Fault('invalid_argument_type');
                if ($rule['type']==='boolean' && !is_bool($args[$key])) throw new Fault('invalid_argument_type');
                if ($rule['type']==='object' && (!is_array($args[$key]) || ($args[$key]!==[] && array_is_list($args[$key])))) throw new Fault('invalid_argument_type');
            }
            if (!in_array('mcp:read',$actor['scopes'],true)) throw new Fault('read_scope_required',403);
            $value=match($name) {
                'cloud_skills_list'=>['skills'=>$this->skillLibrary()->listing()],
                'cloud_skill_load'=>$this->skillLibrary()->load($args['skill_id']),
                'cloud_media_search_images'=>$this->mediaSearch($args),
                'cloud_sites_list'=>array_values(array_filter($this->core->sites($actor['owner']),fn($s)=>in_array($s['id'],$actor['site_ids'],true))),
                'cloud_usage'=>$this->core->usage($actor['owner'],$now),
                'cloud_usage_history'=>$this->core->ledger($actor['owner'],$args['limit']??50,$actor['site_ids']),
                'cloud_site_batch_call'=>$this->core->batchCall($actor,$args['site_id'],$args['items'],$args['idempotency_key'],$args['stop_on_error']??false,$now),
                'cloud_site_batch_receipt'=>$this->core->batchReceipt($actor,$args['site_id'],$args['request_key'],$now),
                'cloud_site_tools_list'=>$this->core->catalog($actor,$args['site_id'],$now),
                'cloud_site_tool_call'=>$this->core->call($actor,$args['site_id'],$args['tool'],$args['arguments'],$args['idempotency_key'],$now)
            };
            // Applies equally to fresh replies and old Core cache hits, after
            // authorization/settlement. Never persist this presentation copy.
            if ($name==='cloud_site_tool_call') $value=self::publicSiteReceipt($value,$args['tool']);
            if (in_array($name,['cloud_site_batch_call','cloud_site_batch_receipt'],true)) {
                $tools=[];
                foreach ($value['steps']??[] as $step) $tools[$step['step_id']]=$step['tool'];
                foreach ($args['items']??[] as $item) $tools[$item['id']]=$item['tool'];
                foreach ($value['results']??[] as $i=>$result) {
                    if (isset($tools[$result['step_id']??''])) $value['results'][$i]=self::publicSiteReceipt($result,$tools[$result['step_id']]);
                }
            }
            $failed=in_array($name,['cloud_site_tool_call','cloud_site_batch_call'],true) && in_array(($value['state']??''),['failed','uncertain'],true);
            return $reply(['content'=>[['type'=>'text','text'=>Security::canonical($value)]],'isError'=>$failed]);
        } catch(Fault $e) {
            $result=['content'=>[['type'=>'text','text'=>Security::canonical(['error'=>$e->reason,'status'=>$e->status])]],'isError'=>true];
            if(in_array($e->reason,['scope_or_site_denied','read_scope_required'],true)&&class_exists(Surface::class))$result['_meta']=['mcp/www_authenticate'=>str_replace('scope="mcp:read"','scope="mcp:read mcp:write"',Surface::challenge())];
            return $reply($result);
        }
    }
}
