<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Server-side Unsplash search.
 *
 * The access key stays on the gateway: the connected WordPress site never needs
 * an Unsplash credential, and the returned URLs are restricted to Unsplash image
 * hosts before they are offered for media import.
 */
final class Unsplash {
    public const API = 'https://api.unsplash.com/search/photos';
    public const MAX_QUERY = 80;
    public const MAX_RESULTS = 20;
    public const MAX_BYTES = 524288;
    public const IMAGE_HOSTS = array('images.unsplash.com', 'plus.unsplash.com');

    /** @var callable(string):array{status:int,body:string} */
    private $http;

    public function __construct(private readonly string $accessKey, ?callable $http = null) {
        if (strlen($accessKey) < 20 || strlen($accessKey) > 200 || preg_match('/[\x00-\x20\x7F]/', $accessKey) === 1) {
            throw new Fault('unsplash_key_invalid', 503);
        }
        $this->http = $http ?? static fn(string $url): array => self::curl($url, $accessKey);
    }

    /** @return array<string,mixed> */
    public function search(string $query, int $perPage = 10): array {
        $query = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $query) ?? '');
        if ($query === '' || mb_strlen($query) > self::MAX_QUERY) throw new Fault('unsplash_query_invalid', 400);
        $perPage = max(1, min(self::MAX_RESULTS, $perPage));
        $url = self::API.'?'.http_build_query(array('query' => $query, 'per_page' => $perPage, 'content_filter' => 'high'), '', '&', PHP_QUERY_RFC3986);
        $response = ($this->http)($url);
        if (!is_array($response) || ($response['status'] ?? 0) !== 200 || !is_string($response['body'] ?? null)) throw new Fault('unsplash_upstream_failed', 502);
        if (strlen($response['body']) > self::MAX_BYTES) throw new Fault('unsplash_response_too_large', 502);
        try {
            $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new Fault('unsplash_response_invalid', 502);
        }
        if (!is_array($decoded) || !isset($decoded['results']) || !is_array($decoded['results'])) throw new Fault('unsplash_response_invalid', 502);
        $results = array();
        foreach ($decoded['results'] as $item) {
            if (!is_array($item)) continue;
            $regular = $item['urls']['regular'] ?? null;
            $small = $item['urls']['small'] ?? null;
            if (!is_string($regular) || !self::imageUrl($regular)) continue;
            if (!is_string($small) || !self::imageUrl($small)) $small = $regular;
            $results[] = array(
                'id' => is_string($item['id'] ?? null) ? $item['id'] : '',
                'description' => is_string($item['description'] ?? null) ? $item['description'] : (is_string($item['alt_description'] ?? null) ? $item['alt_description'] : ''),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
                'small_url' => $small,
                'regular_url' => $regular,
                'photographer' => is_string($item['user']['name'] ?? null) ? $item['user']['name'] : '',
                'photographer_url' => is_string($item['user']['links']['html'] ?? null) ? $item['user']['links']['html'] : '',
                'html_url' => is_string($item['links']['html'] ?? null) ? $item['links']['html'] : '',
            );
        }
        return array(
            'query' => $query,
            'count' => count($results),
            'results' => $results,
            'attribution' => 'Photos from Unsplash. Photographer credit must be shown wherever the image is published.',
            'next' => 'Call media_import_url_sandbox with regular_url, then media_update_alt_sandbox before publishing.',
        );
    }

    public static function imageUrl(string $url): bool {
        if (strlen($url) > 2048) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') return false;
        if (isset($parts['user']) || isset($parts['pass'])) return false;
        if (isset($parts['port']) && (int) $parts['port'] !== 443) return false;
        $host = strtolower((string) ($parts['host'] ?? ''));
        return in_array($host, self::IMAGE_HOSTS, true);
    }

    /** @return array{status:int,body:string} */
    private static function curl(string $url, string $accessKey): array {
        $ch = curl_init($url);
        $body = '';
        $tooLarge = false;
        curl_setopt_array($ch, array(
            CURLOPT_HTTPHEADER => array('Accept: application/json', 'Accept-Version: v1', 'Authorization: Client-ID '.$accessKey),
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) { $tooLarge = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ));
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok === false || $tooLarge) return array('status' => 0, 'body' => '');
        return array('status' => $status, 'body' => $body);
    }
}
