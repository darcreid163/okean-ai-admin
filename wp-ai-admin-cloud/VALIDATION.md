# Core 0.3.3 validation — 2026-10-06

Scope: replace the admin-cookie auth_redirect guard on frontend portal/consent URLs with the WordPress logged-in session check. Anonymous login redirects preserve canonical return URLs and OAuth parameters without reauth=1. Beta access, nonce and site ownership remain enforced. Gateway-disabled and beta-access messages are explanatory. No schema migration or public-client change.

Local PHP 8.3 SQLite: 125 core, 61 compatibility and 50 OAuth assertions passed. Frontend HTTP fixture: eight tests passed, including real HTTP cookie path selection, single login handoff, anonymous/expired sessions, OAuth return parameters, authenticated consent, beta denial, disabled gateway and nonce rejection. WordPress session validation itself is stubbed. The cookie-scope regression also fails against the previous 0.3.2 Surface, confirming it detects the reported bug.

All PHP source syntax checked. CI repeats PHP 8.2/8.3/8.4 and MySQL 8. Native Cloud health returned HTTP 200 with version 0.3.2, configured=false and oauth=false. WordPress home/siteurl both use https://wpaiadmin.ru. The release has not been installed on the live site; actual WordPress login and provider OAuth require post-installation verification.
