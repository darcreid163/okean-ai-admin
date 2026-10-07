# WP AI Admin 2.1.13

Install on wpaiadmin.ru and okean.market (and other managed WordPress sites). The MCP toggle retains its access-control role. Its primary instructions now use https://wpaiadmin.ru/mcp and one central ChatGPT OAuth connection for many sites; native direct endpoints and OAuth diagnostics are collapsed. Pairing identity, credentials and OAuth records remain unchanged. Automation requires separate administrator opt-in.

Use Cloud Core 0.3.6 on the central gateway for cross-origin consent/OAuth navigation fixes. CI source/test commit ab2181ecaaba5fbcf0c69775556f58bfa9498ae3 passed PHP 8.2/8.3/8.4 and two real WordPress profiles, including 34 admin-menu checks on WordPress 7.1.3.

This is the exact native ZIP published by the passing CI; all 85 file payloads were compared to the source.

Bytes: 244567
SHA-256: 15a6a693701a9bd1775108e6b630409c1ae96510e849ebb9f9b8fc16c7ae7018

No production installation is claimed. Update through WordPress's plugin replacement flow, then verify menu, MCP status and reads.
