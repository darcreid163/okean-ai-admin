# WP AI Admin Cloud Core 0.3.4 — persistent gateway enablement

Replace the installed Cloud Core on wpaiadmin.ru with this build. It retains the frontend login fix from 0.3.3 and corrects reading the saved gateway enable flag after the enabling request ends. Existing wrapped keys, schema, connections, provider selections, OAuth tokens and quota data are preserved. No new database migration or public-client update is required.

WordPress returns a non-serialized true option as the string "1" after a database read; immediately after update_option the cached value may still be boolean true. Previously Settings::enabled accepted only boolean true. The gateway could enable successfully in the administrator request and become unavailable on the next page load. It now accepts exactly true, 1 and "1". False, zero, empty and noncanonical values stay disabled; the WP_AI_CLOUD_ENABLED constant keeps its previous strict override behavior.

On 2026-10-07 live wpaiadmin.ru reports Core 0.3.3 and configured=false. The administrator's wpai_gateway_enabled option is "1", and /wpai-cloud/v1/sites returns cloud_not_configured. The fix has not been installed live yet. After replacement, the existing saved enable flag should be recognized; do not regenerate keys or reset the database. Verify /wp-json/wpai-cloud/v1/health reports version 0.3.4 with configured=true and oauth=true before registering sites.

Public MCP: https://wpaiadmin.ru/mcp. SSE: https://wpaiadmin.ru/sse. Central OAuth issuer: https://wpaiadmin.ru/wpai-cloud. Protected-resource metadata is /.well-known/oauth-protected-resource/mcp. Native root discovery is unchanged.

Enable the central gateway only on wpaiadmin.ru. Register both wpaiadmin.ru and okean.market in https://wpaiadmin.ru/wpai-cloud/connect, using each site's separate native WP AI Admin MCP token. Client sites need the native WP AI Admin plugin; they do not need to operate another central gateway. Public-client remains version 0.3.2. Browser login uses the normal WordPress account password; tokens belong in the site-registration form, never chat.

The approved-user beta retains PKCE, site consent, refresh rotation/replay revocation and shared account quotas. ChatGPT and Qwen are beta profiles; Claude and DeepSeek are development profiles. No new billing or license enforcement is introduced.

Validation: 125 core assertions, 76 compatibility assertions including cold Options API reads and enabled/off values, 50 OAuth assertions, eight HTTP frontend-session tests and PHP syntax. The cold-read test fails against 0.3.3 Settings. CI repeats PHP 8.2/8.3/8.4 with SQLite/MySQL. These fixtures do not validate a live WordPress login or real provider OAuth; verify those after installation.
