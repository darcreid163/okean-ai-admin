# WP AI Admin Cloud Core 0.3.6

Install only on the central gateway host wpaiadmin.ru. Install native WP AI Admin 2.1.13 on each managed site. The public endpoint remains https://wpaiadmin.ru/mcp. Replace existing plugin files using WordPress's update flow; do not delete plugin data. No schema or key migration is introduced.

Fixes committed cross-origin browser navigation for automatic site consent and central OAuth callbacks while retaining strict CSP. Shows the shared account plan/usage and all sites; adds read-only connection preflight; aligns registry and OAuth/token capacities at 50. New sites require a fresh OAuth consent selecting the desired sites. Paid purchase activation is not part of this release.

Validated source: ab2181ecaaba5fbcf0c69775556f58bfa9498ae3. Cloud CI run 37599267517 and native CI run 37599267619 passed; the exact Core 0.3.5 browser defect was reproduced. See wp-ai-admin-cloud/VALIDATION.md for tests and live acceptance limits.

ZIP: wp-ai-admin-cloud-0.3.6.zip
Bytes: 48094
SHA-256: 362d0a7f150dca09c3f508c168a1b041124bed78af267814e966318fb5fa034a

After update, check the second site from the cabinet, register under the same owner, repeat ChatGPT OAuth selecting both sites, and read each URL using its own registry ID.
