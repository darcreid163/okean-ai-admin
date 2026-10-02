<?php
declare(strict_types=1);
namespace WPAICloudGateway;

interface Transport { public function rpc(string $home, string $token, array $request): array; }

final class HttpsTransport implements Transport {
    public function rpc(string $home, string $token, array $request): array {
        $home = Security::home($home);
        if (!preg_match('/^[A-Za-z0-9._~-]{20,512}$/D', $token)) throw new Fault('invalid_site_token');
        $host = parse_url($home, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $ips = Security::requirePublicAddresses(array_values(array_filter(array_map(fn($r)=>$r['ip'] ?? $r['ipv6'] ?? null, $records ?: []))));
        $ip = $ips[0];
        $pin = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $ch = curl_init($home.'/wp-json/okean-ai/mcp');
        $body = ''; $tooLarge = false;
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>Security::canonical($request),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json, text/event-stream','Authorization: Bearer '.$token,'MCP-Protocol-Version: 2025-03-26'],
            CURLOPT_RESOLVE=>[$host.':443:'.$pin],CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_WRITEFUNCTION=>function($handle,$chunk) use (&$body,&$tooLarge) {
                if (strlen($body)+strlen($chunk)>1048576) { $tooLarge=true; return 0; }
                $body.=$chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $type = curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
        // A timeout, partial response, HTTP error or invalid response cannot prove that an upstream write did not happen.
        if ($ok === false || $status !== 200 || $tooLarge || !is_string($type) || !str_starts_with(strtolower($type),'application/json')) throw new Fault('upstream_outcome_unknown',502);
        try { $result=json_decode($body,true,64,JSON_THROW_ON_ERROR); } catch (\Throwable $e) { throw new Fault('upstream_outcome_unknown',502); }
        if (!is_array($result) || ($result['jsonrpc']??'')!=='2.0' || ($result['id']??null)!==($request['id']??null) || (isset($result['error']) === isset($result['result']))) throw new Fault('upstream_outcome_unknown',502);
        return $result;
    }
}
