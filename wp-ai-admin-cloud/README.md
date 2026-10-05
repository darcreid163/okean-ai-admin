# WP AI Admin Cloud Core 0.3.0 — multi-client OAuth beta

Update the installed wp-ai-admin-cloud/wp-ai-admin-cloud.php. Keep the existing WP AI Admin client active. Test on wpaiadmin.ru. Public MCP URL: **https://mcp.wpaiadmin.ru/mcp**.

0.3.0 adds a canonical /mcp surface, protected-resource discovery, separate central OAuth issuer https://wpaiadmin.ru/wpai-cloud, S256 PKCE, public DCR, explicit site consent, 1h access tokens, rotating 30-day refresh tokens with replay-family revocation, revocation endpoint and administrator-approved beta users. The local WP AI Admin OAuth discovery remains intact. Gateway v1 connections/credentials/balances are preserved; the additive gateway schema v2 adds six OAuth tables. The original eleven Cloud Core tables remain unchanged.

Set up mcp.wpaiadmin.ru as an HTTPS host pointing to the existing WordPress document root, without redirecting /mcp or stripping Authorization. Upload this plugin, open WP AI Admin Cloud, enable the gateway, add beta WordPress user IDs and connect the test site. Use /wpai-cloud/connect on the main domain to register owned sites. Public clients use OAuth; the original administrator 1h pilot tokens remain compatible. Configuration and wrapped keys are preserved across upgrades.

Clients: ChatGPT developer-mode native MCP; Qwen3.8-Max through Qwen Code Streamable HTTP/OAuth or the public-client Python Function Calling adapter. Claude and DeepSeek are reserved development profiles; no live provider connection is claimed. See public-client/README-RU.md in the repository or public-client-0.3.0.zip for complete setup.

OAuth endpoint metadata is available at /.well-known/oauth-authorization-server/wpai-cloud on the main domain; protected-resource metadata at /.well-known/oauth-protected-resource/mcp on the MCP host. AS issuer and resource must be kept distinct from the existing local WordPress plugin. Native upstream execution still uses its sandbox/approval/apply/verify/rollback lifecycle. Monthly quotas are shared across AI clients within one account; idempotency keys prevent duplicate dispatch and billing. Failed calls and recovery verification/receipts/rollback cost zero.

No commercial license enforcement V2, payments, batch successful-object accounting, logical intent billing, automatic pairing or public-directory approval is included. This is an approved-user beta, not an anonymous service. Live DNS/TLS, gateway configuration, WordPress login/consent, ChatGPT/Qwen OAuth and native lifecycle must be tested after installation. At inspection on 2026-10-05 the live server is still Core 0.2.0, configured=false.

Validation: tests/run.php (125), tests/compatibility.php (58), tests/oauth.php (50 with ten processes exchanging one code), public-client/tests (10 offline tests). CI runs PHP 8.2/8.3/8.4, SQLite and MySQL 8. Framework login/nonce/REST routing and real provider API calls require live verification.
