# Creation claim reconciliation and retention (#123)

The read-only `GET /okean-ai/v1/diagnostics/creation-intents` inventory reports
bounded aggregate counts only. It does **not** return claim identifiers,
operation identifiers, paths or raw values. It is **not** a release, retry,
rollback or cleanup command and never changes a claim.

The only claim mutation path is the administrator-only
`POST /okean-ai/v1/diagnostics/creation-intents/reconcile` route implemented by
`OAI_Creation_Claim_Reconciler` (schema
`oai/creation-claim-reconciliation/v1`). An authorized operator obtains the
claim identifier and current generation from a controlled database/support
inspection, then calls the route with:

| Field | Requirement |
|---|---|
| `family` | `media`, `workspace`, `menu`, `menu_item`, `product` or `elementor_page` |
| `claim_id` | full 64-hex claim identifier from the controlled inspection |
| `decision` | `linked` or `absent` |
| `expected` | exact current 32-hex claim token or 64-hex canonical payload fingerprint |
| `artifact_id` | positive decimal attachment/menu/menu-item/product/page id, or a strict `ws-YYYYMMDD-HHMMSS-32hex` workspace id |
| `operation_id` | required positive decimal id for a linked non-workspace family; must be `0` (or omitted) for workspace and for every absent decision |
| `evidence_note` | 20-2000 characters for `linked`, 40-2000 characters for `absent`; stored in the claim, only its SHA-256 is written to the audit log |
| `confirm_absent` | must be exactly `true` for an `absent` decision |

Negative, zero or non-decimal ids are rejected; they are never reinterpreted
with `absint()`.

A `linked` decision verifies that the artifact exists and, where the family has
an operation, that the operation type and target match the artifact. For media
the operation must carry **both** the `_wpai_creation_intent` and
`_wpai_creation_claim` binding; a partial or mismatched binding is rejected.
Workspace metadata is read only after strict id, symlink and size checks (64 KiB
maximum). The response reports the operation status and whether proof/receipt
evidence exists; it does not fabricate either.

An `absent` decision is rejected when the artifact is present. It never accepts
an operation id and never treats a missing response, an old timestamp or a
single failed lookup as proof of absence: the operator must explicitly confirm
the absence with independent evidence. Workspace absence fails closed if the
uploads directory or workspace root is unavailable or unsafe.

The claim update is an exact-value compare-and-swap (`OAI_DB::cas_creation_claim`),
so a stale inventory view cannot overwrite a newer claim generation. A
successful decision writes a `creation_claim_reconciled` audit event containing
the family, claim, artifact, operation, previous state, link strength and
evidence hash. `link_strength` is `operation_intent_claim` for a strong media
binding, `operation_type_target` for a non-media operation link,
`workspace_directory_metadata` for a workspace, and
`operator_confirmed_absence` for an absence. Claims and artifacts are never
deleted or edited by reconciliation; replaying the same decision and evidence is
idempotent, while a different decision or evidence returns `409`.

## Retention policy

`oai/creation-claim-retention/v1`:

- unresolved claims: `held_indefinitely`;
- reconciled-created claims: `retained_with_linked_evidence`;
- reconciled-absent claims: `retained_for_audit`;
- no automatic deletion, no age or missing-reply release, no release on
  sandbox snapshot expiry and no operator purge.

## Verification

`tests/wordpress-creation-claim-reconciliation.php` is a disposable
WordPress/MySQL fixture wired into `tests/wordpress-native-smoke.sh`. It covers
all six families with real synthetic artifacts and ledger operations, explicit
absence for all six families, present-artifact absence rejection, wrong
operation type/target/intent/claim, unreadable and unknown claims, exact-generation
conflict, direct CAS conflict, idempotent replay, changed-decision rejection,
audit content, retention, artifact immutability and administrator/subscriber/anonymous
authorization for both direct calls and the REST route.
