# WP AI Admin — Public Beta Onboarding

Supported release baseline: WP AI Admin 2.1.14 on each managed WordPress site and Cloud Core 0.3.6 on the central wpaiadmin.ru service.

## Normal user path

1. Install and activate the WP AI Admin native plugin on the WordPress site you want to manage.
2. In WordPress, enable MCP access intentionally from the WP AI Admin dashboard. This does not enable AI Lifecycle Control or Test Automation.
3. Create or sign into your WP AI Admin central account on wpaiadmin.ru.
4. Open the central site connection page at `https://wpaiadmin.ru/wpai-cloud/connect`, enter the canonical HTTPS address of your WordPress site, and choose automatic connection.
5. Sign into that WordPress site as an administrator and explicitly approve the browser consent screen. The site returns to the central cabinet after the one-use pairing exchange succeeds.
6. In ChatGPT, connect the MCP endpoint `https://wpaiadmin.ru/mcp`. Complete OAuth Authorization Code + PKCE S256 and explicitly select the site or sites you want that ChatGPT authorization to access.
7. List your connected sites and select the intended `site_id` for each call. There is no global mutable active-site switch.
8. Perform a read-only check such as site information or the site tool catalog before attempting a change.
9. For a change, create a sandbox operation first, inspect the proposed diff, then use the normal approval → apply → verify flow. Rollback remains conflict-checked and verified.
10. Revoke access when it is no longer needed. WordPress site pairing, central site registration, and ChatGPT OAuth grants are separate revocation boundaries.

## Safety defaults

- Direct live writes are disabled.
- Human approval is the production default.
- AI Lifecycle Control and Test Automation are explicit administrator opt-ins and reset on build changes.
- A new site is never silently added to an existing OAuth grant.
- A site already owned by one central account cannot be claimed by another account without a future explicit transfer flow.
- Failed tool calls do not consume the successful-action quota.
- Batch mutation accounting is not yet enabled for the public beta.

## What the normal onboarding path does not require

The normal browser onboarding flow does not require command-line setup, copied WordPress credentials, copied long-lived site tokens, or model-provider API keys. Advanced legacy diagnostics may exist for compatibility, but they are not part of the normal public-beta path.

## Current beta status

This is an approved-user/public-beta readiness flow, not a claim that automatic payment activation, general-public commercial entitlement enforcement, or batch receipt accounting is complete. Those remain separate release gates.
