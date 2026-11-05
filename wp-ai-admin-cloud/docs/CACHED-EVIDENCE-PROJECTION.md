# Public cached evidence presentation

Gateway projects known Native metadata, operation/sandbox/lifecycle evidence and
diagnostic error replies after Core authorization and settlement. This applies
to both fresh responses and historical cache hits. Mixed gateway batches project
each known metadata item independently; editable file/content and arbitrary tool
content remain exact. The implementation is standalone Cloud code, with no
dependency on an installed Native plugin.

Changed receipt views carry `_wpai_projection`. Original hashes, verified flags,
outcome states and charges retain their original meanings; the redacted view
cannot reconstruct the canonical payload. The original encrypted receipt,
per-child result digests and evidence remain unchanged. No backfill or re-signing
occurs, and idempotent replay never dispatches or charges again. Uncertain
outcomes still hold reservations pending reconciliation.

Only Gateway.php changes within the recovered runtime baseline, a file already
listed as an intentional 0.3.13-dev delta. The immutable 0.3.11 hash manifest,
version, artifact file list, Core, authorization and ledger code are unchanged.

`tests/cached-evidence-projection.php` uses real Core/Store/Vault/Gateway and fake
Native responses on SQLite and MySQL. Coverage includes old sealed cache after a
simulated Native update, fresh/replay and dotted/underscore consistency, nested
JSON/structured MCP metadata, Linux/Windows/UNC/file-URI paths, malformed evidence,
display-key collisions, raw editable bytes/hash, mixed batch original digests,
failed/uncertain retries, owner/site/scope/expiry/revocation fences, and unchanged
sealed rows/quotas. CI runs it on PHP 8.2, 8.3 and 8.4 alongside existing upgrade,
OAuth, accounting, browser and packaging gates. These tests use synthetic data;
they do not deploy to production or rewrite existing customer receipts.
