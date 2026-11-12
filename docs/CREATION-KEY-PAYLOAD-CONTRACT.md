# Creation idempotency key vs. payload — follow-up for #103

Explicit retry keys identify a single attempted creation within actor/blog/tool
scope. A caller must not use the same key with different parameters, including
different clone_theme for workspace, name for new menu, or another menu target
for an item. Such calls now return a privacy-preserving 409 intent conflict
without an artifact ID, operation ID, claim, receipt or other actor data.
The original payload hash is stored as private option metadata.

An empty/missing key remains conservative: legacy calls use their existing
per-payload/target identity so a changed intentional creation need not reuse
the prior claim. New clients should always generate a random 16–128 ASCII
idempotency_key and persist it across retries. Never automatically generate a
different key in response to a timeout: that is a new creation request.

The updated explicit-key identity is versioned. For exact repeated payloads,
Native also checks reservations from earlier candidate versions: workspace
v2, menu v1 and menu-item v1. Keyless workspace/menu/item identities retain
their prior hashing behavior. No old option is rewritten, deleted, silently
converted to success or released on TTL.

**Important upgrade caveat:** earlier explicit-key reservations included
payload/target fields in their option name. The older record did not always
store the nonce or a separately queryable original payload. A changed-payload
retry across that specific previous-candidate upgrade cannot always be
matched to an older claim without an independent operator inventory. Therefore
do not migrate a site with outstanding older unresolved claims and continue
automatically. Inspect and reconcile any historic records under a controlled
staging/maintenance gate first; otherwise fail closed at the deployment level.
This change is source-only and has not been installed on production.

Disposable WordPress/MySQL regression covers same nonce and changed workspace
clone setting, changed new-menu name, changed item menu target, and exact old
candidate claim matching, in addition to existing six-worker races and
approve/verify/rollback suites. Cloud quota/idempotency remains independent
and is not double-charged by Native denial without a confirmed successful
operation. Operator reconciliation and retention are implemented in #123; see
`docs/CREATION-CLAIM-RECONCILIATION.md` for the administrator-only procedure,
compare-and-swap generation rule, audit event and retention policy.

## Transport parity and Gateway accounting (#124)

The durable claim is transport-neutral: whichever of the Native REST route or
the MCP adapter arrives first creates the single option claim, and the other
transport returns the same privacy-preserving 409 (`pending` or `intent
conflict`) without a second artifact, operation, claim or charge. A different
key with the same payload remains a new, allowed creation.

Cloud Core is the only quota authority. A central-gateway paired MCP call runs
with `OAI_Quota::without_local_metering()` and writes no local quota event or
usage row, including when the Native side answers 409. A Native 409 is a failed
object on the Cloud ledger (`RpcReply::succeeded()` is false), never a refund,
rollback or successful charge. Batch settlement remains per successfully
processed object; an uncertain object keeps its reservation until an authorized
operator reconciles it with independent receipt evidence.

Acceptance: `tests/wordpress-creation-transport.php` (Native smoke) and
`wp-ai-admin-cloud/tests/creation-transport-accounting.php` (SQLite + MySQL).
