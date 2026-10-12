# WP AI Admin connection contract
Contract version: **1.3**  
Recorded: **2026-10-12**  
Supported baseline: native **2.1.14**, central **Cloud Core 0.3.6**; retained predecessor: native 2.1.12 / Core 0.3.5  
Purpose: preserve working onboarding and authorization across future releases.

This is the compatibility baseline, not a prohibition on improvements. Changes to the contract require an explicit versioned design, applicable data migration, updated tests and documentation, and the two-site acceptance flow below. Do not silently replace this flow with manual tokens or change persistent identities to make a failing connection appear successful.

## Amendment 1.3 — 2026-10-12 (private release candidate)

Native candidate 2.1.15 / Cloud Core candidate 0.3.7 preserve contract 1.2 endpoints, browser consent flows, persistent identities, keys and existing grants. Native build is `2.1.15-release-reconciled-20261012`. No ordinary-upgrade identity/key reset is introduced. New executable hardening includes administrator-demotion checks before native token exchange/refresh, control-plane option denial, single-winner lifecycle transitions, and execution/recovery leases. Deactivation turns autonomous modes OFF and clears leases while retaining credentials and license state. Central suspension remains revoke-first and reactivation requires fresh authorization; it does not restore revoked access.

Lease expiry and release delete only the exact value originally read. Recovery acquires its own lease, reloads the operation and atomically claims terminal state, so a concurrent request cannot erase a newer holder or finalize the same abandoned operation twice. No data migration is needed for these option leases. Rollback to the prior supported code preserves identities and keys but loses the candidate's additional protections; do it only after stopping autonomous work and reconciling in-flight operations.

Required added gates: `tests/wordpress-operation-lease-race.php` and `tests/wordpress-operation-recovery-race.php`, alongside real ZIP install/upgrade/reactivation and the existing OAuth, MCP, isolation, quota and schema gates. Automated Work results are recorded separately from the outstanding external ChatGPT/two-site acceptance; this amendment does not authorize production deployment or public enrollment.

## Amendment 1.2 — 2026-10-07

Native 2.1.14 applies committed no-store handoff navigation to the actual administrator consent handler after both allow and deny. The target remains the exact fixed callback. This completes the reverse leg missed by Core 0.3.6: after submitting the native self-only consent form, an HTTP redirect to the central domain is blocked by Chromium. Do not widen CSP or drop nonce/administrator/HTTPS/request checks. Both response documents retain no-referrer, no-store and strict form-action self; only the one-use code and state leave the site. No persistent migration, key rotation or identity reset. Core remains 0.3.6.

New gate: native-consent-navigation.js loads the exact released native 2.1.13 handler to reproduce the blocked callback, then the candidate actual authorize() handler for allow, deny, manual continuation, bad nonce, invalid request, administrator and HTTPS denial. WordPress primitives/backchannel are fixtures; real Chromium handles actual response headers and cross-origin navigation. Keep the existing real WordPress nonce/code/exchange tests and central browser gates. Do not present a simplified consent form as production handler coverage.

## Amendment 1.1 — 2026-10-07

This is a compatible onboarding/UI update. Native pairing protocol still supports 2.1.12; 2.1.13 updates the primary MCP block to the central gateway and retains native discovery in collapsed diagnostics. Core 0.3.6 replaces cross-origin POST redirects with a committed no-store handoff document, automatic meta navigation and a manual continuation link to an already validated target. Keep form-action self, no scripts, no arbitrary URL input and Referrer-Policy no-referrer. Apply this to both target-site consent navigation and central OAuth allow/deny callbacks. Same-origin callback-to-cabinet redirects remain valid.

One account owns multiple registrations and one plan/shared quota. Registration capacity stays 50 for all plans; OAuth consent and token site capacity increase from 20 to the same 50 without expanding any existing grant. Existing identities, keys, tables, credentials and balances do not migrate or reset. A paid plan is assigned once to the account; automatic payment activation is a separate billing implementation, not part of this update.

The cabinet shows shared quota, all active sites, an add-site form and optional read-only connection preflight. Authenticated /wp-json/wpai-cloud/v1/connection-check preserves public DNS validation, pinned TLS and owner/account eligibility; it issues no credential and consumes no action quota. Stable sanitized error codes aid diagnosis.

Additional required gates: wp-ai-admin-cloud/tests/multisite-account.php; frontend-session.py handoff/preflight/account summary cases; browser-navigation.js in real Chromium using exact released Core 0.3.5 source for failure reproduction and candidate Surface source for both flows; wordpress-admin-navigation.php primary central endpoint/advanced native diagnostics checks. Keep future user instructions aligned.

## 1. Deployment and current limits

- Native WP AI Admin runs on every managed WordPress site, including wpaiadmin.ru and okean.market.
- Cloud Core 0.3.6 is the central service on wpaiadmin.ru. Automatic pairing protocol minimum is native 2.1.11; 2.1.14 is the supported browser/UI baseline, retaining corrected administrator navigation.
- The public-client 0.3.2 package is a separate optional API client; browser onboarding does not require it.
- Current access is approved-user beta: central administrators or explicitly approved testers. Site registration does not create a ChatGPT plugin automatically.
- ChatGPT and Qwen are beta profiles; Claude and DeepSeek are planned profiles. Billing, open public enrollment, commercial client entitlement enforcement and batch receipt accounting are not implemented. Do not describe them as complete.

## 2. Stable addresses and discovery

| Role | Address or route |
| --- | --- |
| Canonical central MCP resource | https://wpaiadmin.ru/mcp |
| Legacy SSE transport | https://wpaiadmin.ru/sse |
| Central site cabinet | https://wpaiadmin.ru/wpai-cloud/connect |
| Central OAuth issuer | https://wpaiadmin.ru/wpai-cloud |
| Central protected-resource metadata | /.well-known/oauth-protected-resource/mcp |
| Central authorization-server metadata | /.well-known/oauth-authorization-server/wpai-cloud |
| Alternate central authorization metadata | /wpai-cloud/.well-known/oauth-authorization-server |
| Central OAuth authorization | /wpai-cloud/oauth/authorize |
| Central DCR / token / revoke | /wp-json/wpai-cloud/v1/oauth/register, /oauth/token, /oauth/revoke within the same REST namespace |
| Central pairing descriptor | /wp-json/wpai-cloud/v1/pairing/request?state=... |
| Fixed pairing callback | https://wpaiadmin.ru/wpai-cloud/pair/callback |
| Native pairing capabilities | /wp-json/okean-ai/v1/pairing/capabilities |
| Native pairing exchange | /wp-json/okean-ai/v1/pairing/exchange |
| Native administrator consent | /wp-admin/admin-post.php?action=oai_gateway_pair&state=... |
| Native MCP | /wp-json/okean-ai/mcp on each managed site |
| Native dashboard | /wp-admin/admin.php?page=okean-ai-admin |
| Native gateway connections | /wp-admin/admin.php?page=oai-gateway-connections |

The central gateway is on the main domain; do not revert to mcp.wpaiadmin.ru. Central path-specific OAuth discovery must coexist with the native root /.well-known/oauth-protected-resource and /.well-known/oauth-authorization-server. Do not substitute one issuer's metadata for the other.

## 3. Two independent consent flows

### A. Administrator registers a site

1. An eligible authenticated central user enters the site's canonical HTTPS home in the cabinet and selects automatic connection.
2. Central Pairing checks native capabilities, creates a 600-second pending request, and binds initiating owner, home, label, state and S256 verifier. The verifier is encrypted in the existing Vault.
3. The browser opens the target site's administrator consent endpoint. Normal WordPress login preserves the state; require manage_options and an action/state-bound WordPress nonce.
4. Display the target home and receiving central account. Only explicit administrator consent issues the code and enables native MCP. Initialize first-time local-owner access only if no license record exists; never overwrite an existing paused/expired/subscription record.
5. Native stores a hash of a one-use code, valid for at most 120 seconds and no longer than the pending request. Code is bound to state, challenge, administrator and receiving account.
6. Browser redirect carries code/state to the fixed callback, never a durable site credential.
7. Central claims the request atomically and checks initiating owner. Native exchange checks HTTPS, exact callback, state, S256 verifier, expiry and administrator capability; consuming the code must have a single atomic winner.
8. Native issues an independent oai_pair_ credential. Native stores only its SHA-256 hash and administrator/account metadata; central seals the credential with the existing owner/registration-bound Vault.
9. Before registry commit, Core proves access using the actual native system_manifest and validates plugin identity, installation identity and direct_live_writes=false.

Cancellation, expired links, replay, wrong owner, wrong home/callback/verifier and administrator demotion fail closed. Restart from the cabinet; do not bypass checks. Normal browser flow must not require PowerShell, application passwords, copied tokens or model-provider API keys. Retain the collapsed legacy manual-token form for compatibility.

### B. User authorizes ChatGPT

1. User adds one custom MCP plugin with https://wpaiadmin.ru/mcp and OAuth. Current central registration is DCR; client_id_metadata_document_supported=false, token_endpoint_auth_methods_supported=[none]. Never request a WordPress password or model API key as an OAuth client secret.
2. ChatGPT discovers central resource/issuer and registers its client. The browser enters central OAuth consent, preserving all OAuth parameters through normal WordPress frontend login.
3. User signs into the same central account that owns the site registrations and explicitly selects allowed sites and scopes.
4. Use authorization-code + PKCE S256, exact registered redirect, exact resource audience, nonce-bound consent and state. Codes expire after 300 seconds and are single-use.
5. Access tokens default to 3600 seconds; grant lifetime is 30 days. Issue rotating refresh credentials only for offline_access; replay revokes the grant.
6. Every invocation checks owner, audience, expiry, scopes and the selected site grant. A newly registered site is excluded from existing grants until renewed explicit authorization.

Central frontend session checks use is_user_logged_in(). Do not restore an admin-cookie auth_redirect()/forced-reauth login loop. Target-site administrator credentials may differ from the central account credentials.

Site-to-gateway credentials and ChatGPT-to-gateway OAuth credentials are distinct. ChatGPT must not receive a site's durable paired credential. Do not log credential values, authorization codes, verifiers or full callback query strings.

## 4. Identity, reconnect and revocation

- Preserve native site_id and installation_id during ordinary upgrades. Preserve central wrapped Vault keys, site records, OAuth registrations/grants, balances and accounting history.
- Active reconnect by the same owner and same native identity replaces the sealed site credential and retains registration ID; no duplicate registration.
- A centrally revoked registration receives a new registration ID on reconnect; old site grants must remain denied. This is distinct from revoking only a native credential.
- Cross-owner transfer and changed native identity are rejected. A transfer/replacement needs an explicit design; do not weaken checks as a workaround.
- Each paired credential is independently revocable by its creating native administrator, with nonce protection. Losing administrator capability also invalidates it.
- Active reconnect does not automatically revoke previous native paired credentials. Native connection management lists them for separate revocation.
- Native credential revocation can leave a registry row visible; subsequent upstream requests must fail. Central registry disconnect and ChatGPT-client revocation are separate boundaries. Do not promise a nonexistent central-portal disconnect button or that one control revokes all layers.
- Native MCP disable blocks the endpoint and stops automation while retaining connection/identity records. Re-enable does not mint a replacement identity.

## 5. Tool routing and change lifecycle

The central tools/list contract exposes:
- cloud_sites_list: only active sites permitted by the caller's token;
- cloud_usage: account quota/reservations;
- cloud_site_tools_list: catalog for an explicit site_id;
- cloud_site_tool_call: tool, arguments, explicit site_id and stable idempotency_key.

Native 2.1.12 baseline contains 116 tools. The number may grow with compatible additions; do not duplicate all native exports as central tools or discard the per-site catalog. Obtain exact callable names and argument schemas from that catalog; wrapper names in a chat export are not authoritative for upstream routing.

No global mutable “active site” selection. Check authorization on every call, including cached/replayed results. A token cannot access another owner or an unselected/revoked site.

Reuse an idempotency_key for the same logical action and its retries; a different action needs a new key. For an uncertain write outcome, reconcile the existing request with evidence before any new mutation; never retry under a fresh key.

Preserve native permission/license/rate/conflict/expiry checks and sandbox → approval → apply → verify/rollback lifecycle. Automatic registration does not activate AI Lifecycle Control or Test Automation. Those modes remain explicit administrator opt-ins bound to the relevant client/build/session; build change resets their activity. Direct live writes remain disabled.

Existing accounting policy charges successful single-tool calls once. Failed calls and designated verification/receipt/rollback operations remain zero-cost; batch execution remains blocked until its receipt-based accounting contract exists. Do not modify quota policy as a side effect of onboarding.

## 6. Transport and admin navigation

Central-to-site transport preserves HTTPS, all-address public DNS validation, pinned address, TLS verification, disabled redirects/proxy, response limits and timeouts. Do not loosen these controls to handle a hosting failure. Keep canonical home comparison exact; investigate redirects/aliases instead.

OAI_Admin::menu() registers in one ordered pass:
1. parent WP AI Admin, slug okean-ai-admin;
2. explicit first dashboard submenu with the same slug and position 0;
3. OAI_Gateway_Pairing::menu(), slug oai-gateway-connections.

Do not reintroduce a separate earlier admin_menu hook for the pairing submenu. Valid plugin admin links use admin.php?page=..., never a bare /wp-admin/oai-gateway-connections path. Dashboard controls, operation history and approvals remain on the dashboard; connection management is a separate child page.

## 7. Required regression gates for relevant changes

Use .github/workflows/validate.yml and .github/workflows/wp-ai-cloud.yml as the executable command source. Match test scope to the changed component; do not invent passing results.

| Changed area | Required existing gates |
| --- | --- |
| Pairing / native authentication | tests/browser-pairing.php; tests/wordpress-browser-pairing.php through disposable WordPress integration; expiry/replay/account/verifier/admin/revoke tests |
| Native menus or dashboard | tests/wordpress-admin-navigation.php; render real menu and both callbacks, retain controls/credentials/IDs |
| OAuth / central login | wp-ai-admin-cloud/tests/oauth.php; wp-ai-admin-cloud/tests/frontend-session.py; preserve consent query/session |
| Gateway/registry/accounting/transport | wp-ai-admin-cloud/tests/run.php; wp-ai-admin-cloud/tests/compatibility.php; affected two-site/denial/idempotency tests |
| Upgrade/storage/schema | native upgrade/boot matrix, enabled flag handling, persistent IDs/credentials, wrapped Vault keys and migration/concurrency gates |
| Release package | PHP 8.2/8.3/8.4 syntax, SQLite/MySQL where applicable, exact ZIP contents/version/hash and source correspondence |

Negative cases are required: decline consent, replay, expiry, wrong account/site/verifier/callback, demoted administrator, revoked credential, unselected site, old grant after revoked reconnect, and uncertain-write retry. They must remain denied.

Existing fixture and WordPress pairing checks substitute parts of HTTP transport. Passing them is not proof of a live two-origin TLS/browser OAuth flow with ChatGPT.

## 8. Two-site live acceptance record

Status recorded on 2026-10-07:
- Public capabilities checked on wpaiadmin.ru and okean.market: wpai-browser-pairing-v1, native 2.1.12, canonical matching homes.
- Authenticated central health: Core 0.3.5, configured=true, oauth=true, approved_beta; canonical resource https://wpaiadmin.ru/mcp.
- Native 2.1.12 source CI was previously green for commit a599405a334e0b8e76e3297673344250671005f7. Push runs: 37593916596 (native), 37593916612 (Core); PR runs: 37593922126 (native), 37593922100 (Core).
- Pending: actual ChatGPT central OAuth and reads against both site registration IDs. Current chat's existing native connector does not prove central multi-site authorization.

Acceptance procedure:
1. Register both sites in one eligible central account through automatic consent; record canonical homes and distinct registration IDs without secrets.
2. Add/install the central ChatGPT MCP plugin using OAuth DCR; sign into that same central account.
3. Select both sites explicitly, return to ChatGPT, list sites and get each catalog.
4. Read each site's actual URL/version using its site_id. Check no cross-site response.
5. Separately exercise a disposable draft through native sandbox/approval/apply/verify/rollback; preserve original content.
6. Verify that unselected/revoked access is denied, then restore only the explicitly intended access.
7. Record client, versions/builds, time, steps, result and sanitized error/evidence. Mark a gate passed only after observed success.

## 9. Future contract changes

Before a connection-affecting change:
1. Read this contract and affected source/tests.
2. Identify preserved invariants and proposed differences. Add a versioned amendment and migration/rollback design where persistent data or endpoints change.
3. Implement the compatible change and focused regression cases.
4. Run relevant gates; rerun broader CI if behavior or dependencies require it.
5. Complete applicable live acceptance; distinguish verified code from unverified client behavior.
6. Update user instructions, supported-version matrix and release notes together. Never reuse a published version for different package bytes.

Documentation alone cannot guarantee future behavior. This contract, AGENTS.md and executable regression gates together define the preservation process.
