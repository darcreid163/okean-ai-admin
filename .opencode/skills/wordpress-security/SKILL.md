---
name: wordpress-security
description: Threat-model and audit WordPress authorization, XSS, SQLi, CSRF, SSRF, file access, command execution, secrets and unsafe mutations.
---
# WordPress Security Review
Read AGENTS.md. Threat-model browser/admin, REST, MCP, webhooks, Cloud responses, capabilities, tenants, DB, filesystem, URLs and mutation lifecycle. Authorize every operation server-side with capability and object/site ownership. Validate schemas, types, size, enums and identifiers; sanitize storage and escape output. Use nonces for CSRF, prepared SQL, URL validation and filesystem path containment. Block SSRF/private-network and redirect bypasses. Restrict WP-CLI/filesystem to explicit allowlists, approved roots and bounded timeouts; never expose unrestricted shell. Protect tokens, signing keys, payment credentials, backups and PII. Test replay, IDOR, privilege escalation, races and cross-tenant access. Preserve Approval Engine, conflict guards, Verify, signed Receipt/Proof and Rollback. Classify findings P0-P3 with evidence, reproduction, impact, fix and regression test.
