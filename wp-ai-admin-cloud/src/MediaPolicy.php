<?php
declare(strict_types=1);
namespace WPAICloudGateway;

/**
 * Media URL policy for the gateway side.
 *
 * The site performs the authoritative download checks (size, real MIME, redirects).
 * The gateway rejects obviously unsafe targets first so an invalid URL never
 * becomes a billable action and never reaches a customer site.
 */
final class MediaPolicy {
    public const MAX_URL = 2048;
    public const BLOCKED_HOSTS = array('localhost', 'localhost.localdomain', 'metadata.google.internal');

    public static function importUrl(string $url): string {
        $url = trim($url);
        if ($url === '' || strlen($url) > self::MAX_URL) throw new Fault('media_url_invalid', 400);
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') throw new Fault('media_url_insecure', 400);
        if (isset($parts['user']) || isset($parts['pass'])) throw new Fault('media_url_credentials_not_allowed', 400);
        if (isset($parts['port']) && (int) $parts['port'] !== 443) throw new Fault('media_url_port_not_allowed', 400);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (str_starts_with($host, '[') || preg_match('/^[0-9.]+$/D', $host) === 1) throw new Fault('media_url_ip_literal_blocked', 400);
        if ($host === '' || !str_contains($host, '.')) throw new Fault('media_url_host_invalid', 400);
        if (in_array($host, self::BLOCKED_HOSTS, true)) throw new Fault('media_url_host_blocked', 400);
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) throw new Fault('media_url_host_blocked', 400);
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/D', $host)) throw new Fault('media_url_host_invalid', 400);
        $path = (string) ($parts['path'] ?? '');
        if ($path === '' || str_ends_with($path, '/')) throw new Fault('media_url_path_missing', 400);
        return 'https://'.$host.$path.(isset($parts['query']) ? '?'.(string) $parts['query'] : '');
    }

    public static function extensionAllowed(string $url): bool {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|avif)$/D', $path);
    }
}
