# WP AI Admin Usage Engine v2 — no telemetry

Status: Phase 1 implementation in isolated branch; **not approved for production**.
Base: `codex/private-release-candidate-reconciliation` (Native 2.1.15 / Cloud Core 0.3.7 candidate).
This change does not modify `main`, deployed sites, plan prices or customer balances.

## Explicit exclusion

Do NOT install OpenTelemetry, tracing collectors, telemetry SDKs, prompt capture, or tool payload capture for billing or analytics. Operational `requests`, `balances` and the minimum existing security audit remain: they are authoritative transactional business records, not optional telemetry. `cloud_usage_history` returns neither prompts, arguments, tool outputs nor sealed upstream responses.

## Phase 1: implemented

- The central Cloud Gateway is the **single charging authority** for centrally paired MCP calls. Native still enforces permission, security, local rate limits, human approval and sandbox rules, but no longer debits an *additional local quota* for calls authenticated by a central paired credential. Direct legacy or Native OAuth routes continue their existing local behavior (separate follow-up migration).
- Every successfully executed ordinary site tool call, including reads (`wp_get_site`, `wp_list_plugins`, `woocommerce_search_products`) and Sandbox creation, costs **1 action**. Errors cost 0. Preserve the existing central `FREE_TOOLS` exceptions: verification, receipts and rollback.
- Request reservation, idempotency and uncertain-outcome reconciliation are unchanged. Uncertain outcomes retain their reservation until evidence-based reconciliation; they are not treated as failures for billing.
- New `cloud_usage_history(limit?: integer 1..100)` exposes historical **confirmed** `charged_units` from the sealed receipt already stored in the transactional `requests` table. Pending/uncertain entries return `charged_units: null`, not zero. The tool returns request key, site ID, period, tool, status, policy version and timestamps, but no tool input/output.
- Billing history is scoped to the **account AND the exact site IDs in the calling OAuth grant**. `cloud_usage_history` is a free central-account metadata tool, not a billed site tool; ordinary site reads remain billable.

## Phase 2: batch accounting contract (not enabled yet)

Until Phase 2 is implemented and acceptance-tested, leave `batch_create`, `batch_resume`, and other mutation controls protected by the existing fail-closed gate in `Core::call`. Do not bill a successful batch invocation as one action when it executes multiple objects.

When enabled, count one action for each **successfully processed object**, not one action per batch and not 1 per 5 objects. For 12 submitted objects with 9 successful and 3 failed: charge 9 for the batch objects. Independently billed setup or other successful user-visible tool calls remain separate. Internal executor/helper calls must not incur extra charges. A successful step that created a Sandbox operation counts as an operation even if approval/apply happens later; rollback does not refund an already successful action. Distinguish new execution attempts from rereads/retries to prevent double charging.

The trusted Native batch executor must emit verifiable per-step metering receipts (job id, step id, attempt, operation id or read id, terminal step result), bound to the site/account and existing receipt/proof process. Cloud must reserve an upper bound **before** dispatch, settle from validated per-step receipts, release unspent units and persist one idempotent business record per charged step. A retry of the *same successful step attempt* must not add another charge. If execution outcome or receipt validation is uncertain, keep the reservation and reconcile; never accept a user-provided claim of success or count from mutable arguments.

## Safety and release gates

1. Native paired Cloud call + direct Native call: verify no double debit, preserved rate limit and tenant isolation.
2. Repeated MCP idempotency key: same cached receipt, no second execution or charge. Reuse with altered arguments: hard reject.
3. Success and failure of each read and Sandbox tool: 1 or 0 action respectively.
4. Ledger: confirmed success=1, confirmed failure=0, uncertain=null; verify no private arguments/output and grant-scoped site visibility.
5. Phase 2: 12/12=12; 9/12=9; retry, cancelled, rollback, multiple concurrent batches, partial results, out-of-quota and month rollover.
6. Validate on PHP 8.2/8.3/8.4 and disposable WordPress/MySQL before live deployment; do not merge without CI and real acceptance evidence.

## Compatibility and current limits

The current pilot quota definition in `wp-ai-admin-cloud/config/plans.json` remains untouched. It intentionally disables batch until a strong object-level receipt contract is available. The local Native quota engine on **non-gateway** connections still uses its legacy policy and remains outside the centrally billed account balance. The ledger decrypts an existing sealed receipt server-side to extract its charge; for large-scale production, a future version should materialize immutable charged units in a dedicated transactional column/table (with migration and backfill), while retaining cryptographic receipt verification.
