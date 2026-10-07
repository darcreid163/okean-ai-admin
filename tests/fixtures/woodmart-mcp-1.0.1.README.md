# Frozen WoodMart MCP integration dependency

Public WoodMart MCP 1.0.1 ZIP previously fetched from https://woodmart.xtemos.com/plugins/woodmart-mcp.zip for native 2.1.9 validation. Original SHA256: d9d8a8ac07cc189488cb30356acfec1d7b885312faab741ed40fa0bf6c640615.

The upstream unversioned URL now serves 1.0.2. Preserve the exact previously validated 1.0.1 artifact for deterministic regression tests; do not silently substitute 1.0.2 or weaken the production adapter version guard. Authorship remains XTemos. This test dependency is excluded from WP AI Admin release packages. It is used with a minimal theme API fixture, not the licensed Woodmart theme or a live store.
