<?php
declare(strict_types=1);

namespace WPAICloudGateway {
    function home_url(string $path = ''): string {
        return 'https://wpaiadmin.ru' . $path;
    }
    function rest_url(string $path = ''): string {
        return 'https://wpaiadmin.ru/wp-json/' . ltrim($path, '/');
    }
    final class Settings {
        public static function endpoint(): string {
            return 'https://wpaiadmin.ru/mcp';
        }
    }
}

namespace {
    require dirname(__DIR__) . '/src/Surface.php';

    $checks = 0;
    $assert = static function (bool $condition, string $message) use (&$checks): void {
        ++$checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $resource = \WPAICloudGateway\Surface::resourceMetadata();
    $authorization = \WPAICloudGateway\Surface::authorizationMetadata();

    $assert(($resource['scopes_supported'] ?? null) === ['mcp:read', 'mcp:write'],
        'Protected-resource metadata must advertise only supported MCP scopes');
    $assert(($authorization['scopes_supported'] ?? null) === ['mcp:read', 'mcp:write'],
        'Authorization-server metadata must advertise only supported MCP scopes');
    $assert(($authorization['grant_types_supported'] ?? null) === ['authorization_code'],
        'Authorization-server metadata must not advertise refresh-token grants');
    $assert(($authorization['response_types_supported'] ?? null) === ['code'],
        'Authorization-server metadata must expose only authorization-code responses');
    $assert(($authorization['code_challenge_methods_supported'] ?? null) === ['S256'],
        'Authorization-server metadata must require PKCE S256');
    $assert(!str_contains(json_encode([$resource, $authorization], JSON_THROW_ON_ERROR), 'offline_access'),
        'OAuth discovery must not advertise offline_access');
    $assert(!str_contains(json_encode([$resource, $authorization], JSON_THROW_ON_ERROR), 'refresh_token'),
        'OAuth discovery must not advertise refresh_token');

    echo "PASS {$checks} OAuth discovery policy assertions\n";
}
