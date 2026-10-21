<?php
declare(strict_types=1);
namespace WPAICloudGateway;

final class Fault extends \RuntimeException {
    public function __construct(public readonly string $reason, public readonly int $status = 400) { parent::__construct($reason); }
}

final class Security {
    public static function b64(string $bytes): string { return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='); }
    public static function key(string $encoded, int $length): string {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) !== $length) throw new Fault('invalid_server_key', 503);
        return $bytes;
    }
    public static function canonical(mixed $value): string {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (!array_is_list($item)) ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    public static function identifier(mixed $value, int $max = 120): string {
        if (!is_string($value) || strlen($value) > $max || !preg_match('/^[A-Za-z0-9_.:-]{1,120}$/D', $value)) throw new Fault('invalid_identifier');
        return $value;
    }
    public static function home(string $url): string {
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user'], $p['pass']) || isset($p['user']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && $p['port'] !== 443)) throw new Fault('public_https_site_required');
        $host = strtolower($p['host']);
        if (strlen($host) > 253 || filter_var($host, FILTER_VALIDATE_IP) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)) throw new Fault('invalid_site_host');
        $path = $p['path'] ?? '';
        if ($path !== '' && !preg_match('#^/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]*$#D', $path)) throw new Fault('invalid_site_path');
        return 'https://' . $host . rtrim($path, '/');
    }
    public static function publicIp(string $ip): bool {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        // Only global-unicast IPv6; exclude mapped IPv4, NAT64 and transition ranges.
        if (str_contains($ip, ':')) {
            $raw = inet_pton($ip);
            return $raw !== false && (ord($raw[0]) & 0xe0) === 0x20 && substr($raw, 0, 4) !== hex2bin('20010db8') && !(substr($raw,0,2)===hex2bin('2001') && ord($raw[2])<2) && substr($raw, 0, 2) !== hex2bin('2002') && !(substr($raw,0,2)===hex2bin('3fff') && (ord($raw[2]) & 0xf0)===0);
        }
        $n = unpack('N', inet_pton($ip))[1];
        foreach ([[0x64400000, 10], [0xc0000000, 24], [0xc0000200, 24], [0xc0586300, 24], [0xc6336400, 24], [0xcb007100, 24], [0xc6120000, 15], [0xe0000000, 4]] as [$base, $bits]) {
            $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
            if (($n & $mask) === $base) return false;
        }
        return $n < 0xf0000000;
    }
    public static function requirePublicAddresses(array $ips): array {
        if (!$ips || count($ips) > 32) throw new Fault('site_dns_unavailable', 502);
        foreach ($ips as $ip) if (!is_string($ip) || !self::publicIp($ip)) throw new Fault('private_site_address_blocked');
        return array_values(array_unique($ips));
    }
}

final class Vault {
    public function __construct(private readonly string $key) {
        if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) throw new Fault('invalid_vault_key', 503);
    }
    public function seal(string $text, string $binding): string {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        return base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($text, $binding, $nonce, $this->key));
    }
    public function open(string $encoded, string $binding): string {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) < 40) throw new Fault('invalid_sealed_secret', 503);
        $value = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, 24), $binding, substr($bytes, 0, 24), $this->key);
        if ($value === false) throw new Fault('secret_binding_failed', 503);
        return $value;
    }
}

final class Signer {
    public const FEATURES = ['read','plan','sandbox','preview','verify','proof','rollback','elementor','gutenberg','woocommerce','wp_cli','mcp','mcp_apply','mcp_rollback','agent'];
    public function __construct(private readonly string $secret) {
        if (strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) throw new Fault('invalid_signing_key', 503);
    }
    public function publicKey(): string { return Security::b64(sodium_crypto_sign_publickey_from_secretkey($this->secret)); }
    /** V1 interoperability proof only. Never describe this legacy daily allowance as a commercial monthly entitlement. */
    public function foundation(array $site, int $sequence, int $now): array {
        $claims = ['contract_version'=>1,'issuer'=>'okean-control-plane','audience'=>'wp-ai-admin',
            'site_id'=>$site['plugin_site_id'],'installation_id'=>$site['installation_id'],'registration_id'=>$site['id'],
            'issued_at'=>$now,'not_before'=>$now,'expires_at'=>$now+300,'policy_sequence'=>$sequence,
            'plan'=>'free','active'=>true,'write_frozen'=>false,'features'=>self::FEATURES,'quota'=>['limit'=>100,'period'=>'day']];
        $payload = Security::b64(Security::canonical($claims));
        $public = sodium_crypto_sign_publickey_from_secretkey($this->secret);
        return ['alg'=>'Ed25519','key_id'=>substr(hash('sha256',$public),0,16),'payload'=>$payload,
            'signature'=>Security::b64(sodium_crypto_sign_detached("OAI-ENTITLEMENT-V1\n".$payload,$this->secret))];
    }
}
