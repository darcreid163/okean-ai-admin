# Workspace creation admission — bounded #103 slice

## Creation contract

`POST /okean-ai/v1/workspace/create` takes `clone_theme` and optional
`idempotency_key` (16–128 ASCII characters from `A–Z a–z 0–9 . _ ~ -`).
Clients **must retain and reuse the same key** for transport retries and use
a fresh key only when deliberately creating another workspace. A call without
a key uses a legacy deterministic intent; after its first reservation, repeated
legacy calls fail closed with `oai_workspace_creation_pending` (409).
This is not an idempotent success replay: no workspace ID is leaked on a retry.
Clients that need to create more than one equivalent workspace must upgrade
to explicit keys; silently clearing a legacy reservation is unsafe.

The reservation identity binds the WordPress blog ID, authenticated actor,
stylesheet/template, clone flag and caller's idempotency key. It is stored as a
SHA-256-derived `oai_workspace_creation_*` non-autoloaded option with a
server-generated workspace ID before the first workspace directory is created.
WordPress's unique `option_name` key is the insert arbitration point. The
autocommit guard prevents a caller's surrounding transaction from rolling the
reservation back after files have been created. Authorization is checked before
making any directory. An invalid key is rejected before a persistent write.
Different authorized actors and deliberate new keys get distinct reservations.

## Failure and recovery

A 409 means either a request is in progress, successfully created, failed to
persist its metadata, or its response was lost. The source **does not** treat
an old timestamp, a missing metadata file or an HTTP timeout as absence of a
workspace. The claim is held; no automatic TTL/cleanup or blind recreation is
allowed. Known metadata-write failures return a structured error and leave
the claim for investigation.

**Operator-only recovery must be designed separately**: correlate option claim,
workspace directory, `workspace.json`, current active workspace, permission
and file inventory. Never delete the directory/option just because it is
stale. Do not publish server filesystem paths or claim IDs via duplicate
responses. Existing historical workspaces have no retroactive claims; migration
and explicit claim cleanup are not part of this change. The active-workspace
option is not a proof that a private workspace directory was never created.
`workspace_write` and pending operation approval/rollback remain independent.

## Scope and gate

Real disposable WordPress/MySQL smoke covers duplicate rejection, creating a
second workspace with a new nonce, alternate administrator isolation,
transaction refusal, anonymous refusal, validation, six independent concurrent
WordPress creation workers and exactly one filesystem artifact for the shared
key. No changes to Cloud accounting, billing, quotas, live themes or installed
sites. Before production, assess legacy callers missing `idempotency_key`,
operator reconciliation, retention and paired code/database/uploads restore.
Issue #103 remains open for other artifact creators and historical records.
