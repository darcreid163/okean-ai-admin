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
operation. A dedicated cross-version operator reconciliation/retention tool
remains an open P0 task in #103 and release tracker #40.
