# WP AI Admin Cloud Core 0.3.7 — public release hardening candidate

This candidate adds revoke-first account suspension, fail-fast gateway schema validation, stricter account/bearer/OAuth revocation boundaries and expanded release qualification. Existing gateway keys and registered site/OAuth state must be preserved on upgrade from 0.3.6.


Install candidate Core 0.3.7 only on central wpaiadmin.ru; 0.3.6 is the preserved upgrade baseline. Native 2.1.12 remains compatible for pairing; native 2.1.13 on every site updates the administrator MCP instructions to the central gateway.

Open https://wpaiadmin.ru/wpai-cloud/connect, enter a canonical HTTPS site address, optionally select «Проверить связь», then «Подключить автоматически». Sign into that site's WordPress administrator account and consent. The browser returns to the central cabinet without copying tokens or application passwords. Repeat for other sites in the same central account.

The primary account page shows all connected sites, the account plan, total/used/reserved/remaining actions and a form to add another site. One assigned plan/shared monthly quota covers all sites. The beta permits up to 50 registrations per account on every plan; OAuth and access tokens now accept up to the same 50 selected sites (previously 20). Assigning a paid plan through the operator API affects the account's shared quota. Payment collection, automatic purchase activation and open public enrollment are not implemented by this release.

Core 0.3.5 sent a cross-origin 303 after a form POST, while its original document's CSP restricted form-action to self. Chromium can reject the navigation to a second site's consent or the ChatGPT OAuth callback. Core 0.3.6 returns a no-store HTML handoff document and starts a new navigation to the already validated target. An automatic meta refresh and a manual «Продолжить подключение» link preserve operation without widening form-action, allowing scripts or adding arbitrary redirect targets.

Public MCP remains https://wpaiadmin.ru/mcp; SSE remains https://wpaiadmin.ru/sse; central issuer remains https://wpaiadmin.ru/wpai-cloud. Add/install one ChatGPT MCP plugin using OAuth DCR, sign into the same central account, and explicitly select the desired registered sites. Adding a new site requires another authorization; existing grants do not expand automatically.

The authenticated GET /wp-json/wpai-cloud/v1/connection-check?home=... checks the public native pairing capabilities from the gateway server with the same DNS/address-pinned HTTPS transport. It does not issue credentials, register a site or charge tool quota. The cabinet displays a stable sanitized error code when a check fails.

No database migration or key rotation: native credentials/identities, central Vault wrapping, existing registrations, grants, plans and balances are retained. Active reconnect retains its registration ID; centrally revoked reconnect changes it. Cross-account access/transfer, replay, expired codes and wrong state/verifier/callback remain denied. All native write lifecycle protections and transport limits stay in force. Registration does not enable optional automation.

See VALIDATION.md and ../docs/CHATGPT-CONNECTION-CONTRACT.md for regression scope. Real Chromium checks use fixture sessions/back-channel calls with actual old/new Surface code. They do not prove actual ChatGPT authorization or installed-site administrator consent.
