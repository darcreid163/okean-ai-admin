WP AI Admin Native 2.1.17 — recovered source candidate; Cloud Core 0.3.11 recovered in private GitHub.
Source archive is verified, installed on-server binary checksum is not; production installation is NOT approved.
See docs/RECOVERY-NATIVE-2.1.17-2026-10-21.md and private Cloud recovery PR #12.

Historical notes only (do not follow for current installation):
WP AI Admin 2.1.15 — public release hardening candidate
Deactivating the native plugin now forcibly disables AI Lifecycle Control and Test Automation while preserving site/installation identity, OAuth/MCP credentials and license state. Re-activation never silently restores autonomous modes.
WP AI Admin 2.1.14 — native consent callback fix
After allow/deny on the managed site, a committed no-store handoff safely returns to the fixed central callback. The strict CSP, nonce, HTTPS, owner binding and one-use codes are preserved. Core stays 0.3.6.
WP AI Admin 2.1.14 — central MCP connection instructions
Historical 2.1.14 instruction (SUPERSEDED): earlier versions used Native 2.1.15 and Cloud Core 0.3.6; do not use these for current installations or upgrades.
The MCP toggle controls access to this site. The primary ChatGPT endpoint is https://wpaiadmin.ru/mcp; direct native MCP and OAuth links are in collapsed diagnostics.
Register multiple sites in one account at https://wpaiadmin.ru/wpai-cloud/connect, log into each site as administrator, then authorize the central ChatGPT app for the selected sites. One account plan and shared quota cover all sites, up to 50 registrations in this beta. Payment activation remains a separate billing stage.
Existing credentials, installation identities, dashboard navigation and sandbox/approval/apply/verify/rollback behavior are preserved. Enabling MCP does not enable AI Lifecycle Control or Test Automation. Build changes reset optional automation activity as before.
