# WP AI Admin Cloud Core 0.3.3 — frontend login fix

Upgrade the installed wp-ai-admin-cloud/wp-ai-admin-cloud.php on wpaiadmin.ru. Existing configuration, wrapped keys, database tables, quotas and provider/site selections are preserved. Public-client 0.3.2 and the native WP AI Admin plugin require no update for this fix.

The /wpai-cloud/connect portal and /wpai-cloud/oauth/authorize consent page previously used auth_redirect(), an admin-cookie guard. WordPress scopes its admin authentication cookies to admin/plugin paths, while these frontend pages receive the logged-in cookie. A valid frontend session could therefore be sent repeatedly to wp-login.php with reauth=1.

These frontend pages now use is_user_logged_in(), which uses the WordPress-validated current user. Anonymous visitors receive a safe redirect to the WordPress login page without forced reauthentication. The return URL is canonical; the OAuth client, callback, state, scope, resource and PKCE parameters are preserved. Eligibility, account/site ownership, WordPress nonce checks and consent validation remain in place. A disabled gateway and missing beta access now have explanatory messages.

After replacement, open https://wpaiadmin.ru/wpai-cloud/connect directly. Sign in with the regular WordPress account password. Enable the gateway in WP AI Admin Cloud and grant the test account beta access if necessary. Application passwords are used for REST Basic authentication, not this browser login.

Public MCP: https://wpaiadmin.ru/mcp. SSE: https://wpaiadmin.ru/sse. Central OAuth issuer: https://wpaiadmin.ru/wpai-cloud. Protected-resource discovery uses /.well-known/oauth-protected-resource/mcp and leaves native root discovery intact. The approved-user OAuth beta retains S256 PKCE, site consent, one-hour access tokens, refresh rotation/replay revocation and shared account quotas. ChatGPT and Qwen are beta profiles; Claude and DeepSeek are development profiles. No new commercial billing or license enforcement is introduced.

Validation: 125 core assertions, 61 compatibility assertions, 50 OAuth assertions, and eight HTTP frontend-session regression tests. The HTTP fixture exercises the real Surface code with browser cookie-path selection and a stubbed WordPress session validator; it does not validate a live WordPress login. CI repeats checks on PHP 8.2/8.3/8.4, SQLite and MySQL 8.

At inspection on 2026-10-06, wpaiadmin.ru has Core 0.3.2 and configured=false. This 0.3.3 build requires installation; live login, provider OAuth and hosting SSE behavior remain to be verified afterward.
