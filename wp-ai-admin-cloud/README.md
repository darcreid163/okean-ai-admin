# WP AI Admin Cloud Core 0.3.8 — reconstructed account candidate

Rebuilt from the retained QA37 Cloud Core 0.3.7 archive, NOT the lost Work 0.3.8 source.

## Changes
- Account overview and shared plan/quota remain isolated to signed-in account.
- Connected site table with nonce-guarded disconnect using existing Core::disconnect owner authorization.
- ChatGPT MCP endpoint instructions and account activity information.
- Existing site pairing, OAuth, token issuance, central gateway, and database layout preserved.

## Status
PHP lint only. **Not live-accepted**, not asserted equivalent to previous Work 0.3.8. Back up and test on staging before production. New detached site credentials are revoked by existing Core logic.
