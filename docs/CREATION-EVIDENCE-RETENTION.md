# Artifact-creation evidence retention before operator reconciliation

A newly created site artifact may exist after a lost HTTP response even when
its corresponding Native pending operation has been rejected, rolled back,
expired or moved into another terminal state. The generic
`OAI_Operations_Cleanup::run` operator utility is not a safe way to determine
whether an artifact exists, nor is an expired timestamp evidence of absence.

All terminal operation rows of types `media_upload`, `menu_create`,
`menu_add_item`, `woocommerce_product_create` and
`elementor_page_create` are **excluded** from generic retention, including
historical rows that predate the new atomic creation guards. The existing
`_wpai_creation_intent` metadata guard remains for any other operation type.
For all remaining noncreation terminal rows, the same dry-run/default,
`include_applied`, `max_id`, role and audit-log semantics continue.

This is a conservative, potentially unbounded retention safeguard. It does
**not** prove successful creation, resolve or delete reservations, discover
abandoned files, alter quotas or provide an operator reconciliation command.
Future cleanup requires independent verification of option claim, real
artifact, linked operation, approval/apply/verify/rollback, and all relevant
owners/callers before erasing durable evidence. Historical rows may lack the
claim fields required for automated matching, and should remain protected
until a separately approved migration/retention strategy exists.

The real WordPress/MySQL regression uses an isolated throwaway ledger table:
it seeds each protected creation type, an unrelated historically marked row
and ordinary terminal operations; confirms dry-run eligibility, deletes
only ordinary rows, repeats cleanup, checks anonymous denial and drops only
the disposable test table. This change does not run an operator cleanup on
production or modify customer records.

Parent issue #103 and release gate #40 stay open for creation admission on
remaining routes, operator reconciliation and safe retention/expiry.
