# Core 0.3.2 validation — 2026-10-05

Address correction: main MCP https://wpaiadmin.ru/mcp; Qwen SSE https://wpaiadmin.ru/sse. Existing provider/site selection and OAuth code preserved. Native root OAuth/protected-resource discovery is left to WP AI Admin; central gateway discovery uses path-specific endpoints.

Local PHP 8.3 SQLite: 125 core assertions, 61 compatibility assertions and 50 OAuth assertions passed. Python public client: 10 offline tests passed. Syntax checked for all PHP files. CI repeats PHP 8.2/8.3/8.4 with SQLite and MySQL 8.

Live read verification: wpaiadmin.ru responds through the existing WP AI Admin connector, native client 2.1.9 and Cloud Core 0.3.1 active. Cloud health HTTP 200 reports configured=false and oauth=false; old subdomain URLs remain in live 0.3.1. This 0.3.2 build has not been installed. Real gateway OAuth, ChatGPT/Qwen sessions and SSE buffering on hosting are not confirmed by fixture tests.

The homepage rainbow heading was applied separately as native operation 34 and verified on the live page; it is not bundled in the plugin.
