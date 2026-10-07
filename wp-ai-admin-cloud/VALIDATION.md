# Core 0.3.5 browser onboarding validation — 2026-10-07

Local checks: all PHP syntax; 125 core assertions; 76 compatibility assertions; 50 OAuth assertions; ten frontend HTTP tests; browser pairing assertions with actual central/native pairing classes and native MCP authentication. Existing native bootstrap, signed entitlement, Elementor, options and WooCommerce stock unit suites also pass.

The browser pairing fixture covers registration and MCP reads for two separate origins, S256 mismatch, account mismatch, state/code replay, expiry, cancellation, wrong callback, wrong site, administrator/HTTPS requirements, atomic claim failure, encrypted/hash-only credentials, demotion and revocation. Active reconnect retains registration ID; revoked reconnect replaces it and old grants stay denied. Manual token rotation is not performed.

CI runs PHP 8.2/8.3/8.4 and MySQL8. The native WordPress boot matrix performs a real 2.1.5 upgrade with WooCommerce, Elementor, Yoast and a minimal Woodmart native API fixture, then exercises pairing through real WordPress REST, users, nonce, Options API, license and MCP routes. The central-to-site HTTP back channel is substituted within that disposable WordPress; this is not a live DNS/TLS or ChatGPT authorization test. Public-client offline tests and existing process concurrency/OAuth migration checks remain enabled.

Live before update: wpaiadmin.ru health returns Core 0.3.4, configured=true, oauth=true. Native plugins are 2.1.9 on wpaiadmin.ru and okean.market. New production-domain consent and ChatGPT/Qwen authorization remain pending installation. The connector cannot install these WordPress plugins. No website content was changed.

After installation: health must return 0.3.5/configured=true; both native pairing capabilities routes must return wpai-browser-pairing-v1/2.1.10. Use the cabinet's automatic form for each site, verify both appear, then authorize the central gateway in the AI client and read each site by its registration ID. Retest native system.self_test after the normal build change; keep human approval defaults.
