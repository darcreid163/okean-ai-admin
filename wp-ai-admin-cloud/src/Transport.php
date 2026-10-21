<?php
declare(strict_types=1);
namespace WPAICloudGateway;

interface Transport { public function rpc(string $home, string $token, array $request): array; }
interface PairingTransport { public function pairing(string $home,string $action,array $request=[]): array; }

/** Method-specific validation shared by HTTPS and Core's transport boundary. */
final class RpcReply {
    public static function validate(array $reply,array $request): void {
        if (($reply['jsonrpc']??null)!=='2.0' || !array_key_exists('id',$reply) || $reply['id']!==($request['id']??null) || (array_key_exists('error',$reply)===array_key_exists('result',$reply))) self::invalid();
        if (array_key_exists('error',$reply)) {
            $error=$reply['error'];
            if (!is_array($error) || !is_int($error['code']??null) || !is_string($error['message']??null)) self::invalid();
            return;
        }
        if (!is_array($reply['result'])) self::invalid();
        if (($request['method']??'')!=='tools/call') return;
        $result=$reply['result'];
        if (array_key_exists('isError',$result) && !is_bool($result['isError'])) self::invalid();
        if (!isset($result['content']) || !is_array($result['content']) || !array_is_list($result['content'])) self::invalid();
        foreach ($result['content'] as $block) {
            if (!is_array($block) || !is_string($block['type']??null)) self::invalid();
            switch ($block['type']) {
                case 'text':
                    if (!is_string($block['text']??null)) self::invalid();
                    break;
                case 'image': case 'audio':
                    if (!is_string($block['data']??null) || !is_string($block['mimeType']??null)) self::invalid();
                    break;
                case 'resource_link':
                    if (!is_string($block['uri']??null) || !is_string($block['name']??null)) self::invalid();
                    break;
                case 'resource':
                    $resource=$block['resource']??null;
                    if (!is_array($resource) || !is_string($resource['uri']??null) || (!is_string($resource['text']??null) && !is_string($resource['blob']??null))) self::invalid();
                    break;
                default: self::invalid();
            }
        }
    }
    private static function invalid(): never { throw new Fault('upstream_outcome_unknown',502); }
}

final class HttpsTransport implements Transport,PairingTransport {
    public function rpc(string $home, string $token, array $request): array {
        if (!preg_match('/^[A-Za-z0-9._~-]{20,512}$/D', $token)) throw new Fault('invalid_site_token');
        $result=$this->request($home,'/wp-json/okean-ai/mcp','POST',$request,$token);
        RpcReply::validate($result,$request);
        return $result;
    }
    public function pairing(string $home,string $action,array $request=[]): array {
        if(!in_array($action,['capabilities','exchange'],true))throw new Fault('invalid_pairing_action');
        return $this->request($home,'/wp-json/okean-ai/v1/pairing/'.$action,$action==='capabilities'?'GET':'POST',$request,'',true);
    }
    private function request(string $home,string $path,string $method,array $request,string $token,bool $pairing=false): array {
        $home = Security::home($home);
        $host = parse_url($home, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = Security::requirePublicAddresses(array_values(array_filter(array_map(fn($r)=>$r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
        $ip = $ips[0];
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $ch = curl_init($home.$path);
        $body = ''; $tooLarge = false;
        $headers=['Content-Type: application/json','Accept: application/json, text/event-stream','MCP-Protocol-Version: 2025-03-26'];
        if($token!=='')$headers[]='Authorization: Bearer '.$token;
        if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,Security::canonical($request));}
        curl_setopt_array($ch, [CURLOPT_HTTPHEADER=>$headers,
            CURLOPT_RESOLVE=>[$host.':443:'.$pin],CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk) use (&$body,&$tooLarge) {
                if (strlen($body)+strlen($chunk)>1048576) { $tooLarge=true; return 0; }
                $body.=$chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $type = curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        // A timeout, partial response, HTTP error or invalid response cannot prove that an upstream write did not happen.
        if($pairing&&$status===404)throw new Fault('pairing_client_update_required',422);
        if ($ok === false || $status !== 200 || $tooLarge || !is_string($type) || !str_starts_with(strtolower($type),'application/json')) throw new Fault($pairing?'pairing_exchange_failed':'upstream_outcome_unknown',502);
        try { $result=json_decode($body,true,64,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new Fault('upstream_outcome_unknown',502); }
        if(!is_array($result))throw new Fault($pairing?'pairing_exchange_failed':'upstream_outcome_unknown',502);
        return $result;
    }
}
