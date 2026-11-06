---
name: oauth-security
description: OAuth and PKCE authorization, token lifecycle, site-owner binding, scopes, redirect validation and tenant isolation.
---
# OAuth and Identity Security
Inspect current Cloud identity contract before changing flows. Require PKCE S256 for public clients; validate state, exact registered redirect URI and code binding. Reject wildcards, open redirects and downgrade behavior. Bind grants to account, tenant, user and WP site owner. Validate issuer, audience, expiry, signature, algorithm and scopes. Rotate/revoke safely; encrypt secrets; never log tokens or codes. Prevent substitution, replay, CSRF, mix-up and cross-tenant access. Re-check WP capabilities at execution. Test expired/revoked tokens, wrong audience/owner/tenant, redirect mismatch, missing verifier, replay and concurrent refresh. Document migration and rollback.
