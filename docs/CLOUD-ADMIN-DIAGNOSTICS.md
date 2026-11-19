# Cloud Core — read-only site diagnostics

Open WP AI Admin Cloud → Gateway / test mode as a WordPress administrator. Each active registered site now has a “Диагностика подключения и MCP” button.

The report runs in two stages:

1. Local credential checks: active owner/site binding, sealed payload format, Vault decryption and site-token format.
2. One upstream Native MCP tools/list request, only if the local credential check succeeds. It reports catalog reachability and whether the seven required read tools are present:
   - wp.get_site
   - wp.list_plugins
   - wp.list_themes
   - wp.read_post
   - gutenberg.get_blocks
   - elementor.get_structure
   - woocommerce.search_products

The diagnostic is restricted to WordPress administrators and the currently authenticated Cloud owner. The submitted site ID is checked against that owner's active registrations.

## Safety and accounting

- No registration, token, Vault key, site content, or WordPress option is changed.
- No site tool is dispatched; only the MCP catalog discovery method tools/list is requested.
- The site token, status key, ciphertext, and key material are never included in the report.
- The diagnostic is not a billable user action and reports billable_actions: 0.
- If Vault validation fails, no outbound request is attempted.
- The seven tools are only checked for catalog presence. Running the seven actual read operations remains a separate Cloud MCP acceptance test and is subject to the normal quota.

## Interpreting failures

- secret_binding_failed: current Vault key or owner/registration binding does not match the sealed credential. Do not rotate keys or reconnect before comparing the database and key backup.
- invalid_sealed_secret: sealed value is malformed or truncated; compare with a coherent database backup.
- invalid_site_credential: Vault opened the value, but its credential document is invalid.
- upstream_outcome_unknown: the Native endpoint did not return a valid MCP response; inspect DNS, TLS, HTTP reachability and Native authentication without exposing credentials.
- Missing required tools: Native plugin/catalog version or tool registration needs review.

This feature does not replace full tenant-isolation, OAuth lifecycle, or release acceptance tests.
