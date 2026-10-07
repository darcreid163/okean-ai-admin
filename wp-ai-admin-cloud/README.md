# WP AI Admin Cloud Core 0.3.5 — automatic browser registration

Install this Cloud Core update only on wpaiadmin.ru. Install the native WP AI Admin 2.1.11 update on every connected site, including wpaiadmin.ru and okean.market. The unchanged public-client 0.3.2 package is not needed for browser onboarding.

The primary cabinet form at https://wpaiadmin.ru/wpai-cloud/connect asks only for the site's canonical HTTPS address. It redirects the user to their site's regular WordPress administrator login and an explicit consent screen identifying the receiving central account. After consent, the gateway exchanges a short-lived one-use code directly with that site and automatically registers it after validating its actual system_manifest. No terminal, application password or manually copied MCP token is required. The old token form is retained in a collapsed legacy section.

The central request lasts ten minutes; the native code lasts at most two minutes. S256 proof, exact fixed callback, initiating account binding, administrator capability checks and WordPress nonces protect the consent flow. The browser never receives the resulting site credential. Native WordPress stores a hash of each independent paired credential; the gateway uses the existing encrypted vault. Credentials stay valid until revoked or the owning administrator loses their role. Native WP AI Admin → «Подключения к gateway» lists the account label and allows per-connection revocation.

The HTTPS transport retains public-DNS validation, address pinning, TLS verification, no redirects/proxy, response bounds and timeouts. Existing central tables, wrapped keys, plans, balances, OAuth grants and connections are preserved. No new database migration is needed. Reconnecting an active site updates its credential without duplicate registration; reconnecting a revoked site uses a new ID and cannot restore old grants. Cross-account transfer or changed native identity is rejected.

Public MCP: https://wpaiadmin.ru/mcp. SSE: https://wpaiadmin.ru/sse. Central OAuth issuer: https://wpaiadmin.ru/wpai-cloud. Native root discovery stays unchanged. After registering sites, connect the AI client to the public MCP URL and select sites on the central OAuth consent screen. Installing a WordPress package does not automatically add an app to a user's ChatGPT account.

The approved-user beta retains PKCE, site consent, refresh rotation/replay revocation and shared account quotas. ChatGPT and Qwen are beta profiles; Claude and DeepSeek are development profiles. Native sandbox, approval, license, verification and rollback remain mandatory; site registration does not activate unattended writes. Production billing, open public enrollment and commercial client entitlement enforcement are not implemented.

Retains 0.3.3 frontend logged-in session handling and 0.3.4 persistent gateway flag parsing (true, 1 and "1"). Live wpaiadmin.ru was confirmed on Core 0.3.4 with configured=true/oauth=true before these changes. The new packages still require installation and real client authorization on the user's sites.

Validation commands and scope are documented in VALIDATION.md. CI runs PHP 8.2/8.3/8.4, SQLite/MySQL, native compatibility and disposable real WordPress integration.
