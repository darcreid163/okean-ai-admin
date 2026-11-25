<?php
/**
 * Runtime regression for the strict Native OAuth policy.
 * Executed only inside disposable WordPress by wordpress-native-smoke.sh.
 */
if (!defined('ABSPATH') || !class_exists('OAI_MCP_OAuth')) {
    throw new RuntimeException('Native OAuth runtime is unavailable');
}
$normalize = new ReflectionMethod(OAI_MCP_OAuth::class, 'normalize_scope');
$normalize->setAccessible(true);
if ($normalize->invoke(null, '', true) !== 'mcp:read mcp:write') {
    throw new RuntimeException('Native OAuth default scopes must exclude offline access');
}
if ($normalize->invoke(null, 'mcp:read offline_access', false) !== '') {
    throw new RuntimeException('Native OAuth accepted the forbidden offline_access scope');
}
$request = new WP_REST_Request('POST', '/okean-ai/v1/oauth/token');
$request->set_param('grant_type', 'refresh_token');
$request->set_param('refresh_token', 'legacy-refresh-token');
$request->set_param('client_id', 'legacy-client');
$response = OAI_MCP_OAuth::token($request);
if (!$response instanceof WP_REST_Response || $response->get_status() !== 400) {
    throw new RuntimeException('Native OAuth refresh_token grant was not rejected with HTTP 400');
}
$data = $response->get_data();
if (($data['error'] ?? '') !== 'unsupported_grant_type') {
    throw new RuntimeException('Native OAuth returned an unexpected refresh grant response');
}
echo "PASS: Native OAuth rejects offline_access and refresh_token grants\\n";
