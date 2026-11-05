# Release Gate: creation reservations and historical upgrades
Status: development evidence only. Target Native 2.1.17/DB24 + Cloud 0.3.13-dev/schema4.
Owner approval of a production deployment, tariff migration, tag or beta release is **not** implied.

## Current source evidence

Creation paths with atomic pre-artifact admission have dedicated disposable MySQL/WordPress
acceptance suites: media (#107), workspace (#108), menus (#109), WooCommerce drafts
(#110), Elementor pages (#111), menu items (#113); old/changed nonce defenses (#114).
Generic cleanup preserves creation-operation evidence (#112). Administrator-only read-only
aggregate claim inventory (#115, #116); workspace footprint detection (#117 merged after exact-head Native/Cloud CI). Native/Cloud CI checks are tied to each exact PR head.
These tests are **not** evidence that the installed `wpaiadmin.ru` or `okean.market`
files contain these changes.

## Stop/go conditions before staging or beta creation writes

1. **Installed baseline:** privately compare PHP/plugin files, Cloud schema and
   database versions to the frozen release artifacts; retain source SHA and SHA-256
   checksums. Never infer installed version from marketing text. Production's last
   owner-shared Cloud health was 0.3.11, not the dev candidate.
2. **Paired backups:** obtain privately stored DB + uploads/workspace files + Native
   and Cloud code + OAuth key/config backups and test restoration in a *disposable*
   production-equivalent environment. Include in-flight reservations, receipts,
   approval records, selected sites, revocation state and Cloud Ledger used/reserved.
3. **Historical reservations:** under authorized administrator access inspect
   `GET /wp-json/okean-ai/v1/diagnostics/creation-intents?limit=100` if the
   tested source is installed on the staging clone. Result contains counts only.
   Never place raw option names, claims, operation identifiers, path names or token
   values in public CI, logs or issue comments. If `complete=false`, the sample
   did **not** cover all claims. Even a complete sample is not independently
   verified reconciliation.
4. **Upgrade invariants:** old workspace-v2, menu-v1 and menu-item-v1 claim names
   can include payload fields in a SHA-256 identity with no invertible nonce.
   A changed-payload retry across that candidate upgrade cannot always be matched
   to the old claim. **Block unattended creation writes** if such claims might be
   outstanding; first reconcile all uncertain claims against original site identity,
   request/intent evidence, actual artifact(s), operations and Verify/Receipt.
   Never automatically clear an old claim because it is aged, absent from a
   metadata file or listed as failed.
5. **Known uncertain outcomes:** reserve and reconcile separately:
   - A reserved claim plus linked, verified artifact and ledger operation requires
     operator evidence before any state transition.
   - A reserved claim with no operation link may still have a persisted artifact
     from a crash between WordPress write and operation insertion.
   - A missing artifact on a recovered snapshot is not proof it was absent on
     the original site or at the time the request was dispatched.
   - A terminal operation (including rolled_back/failed) retains its creation
     evidence; generic retention must not remove these records.
6. **Scope and quotas:** a repeated Native key is **not** a Cloud billing receipt.
   Cloud Gateway remains the charging authority. Confirm quota reservations,
   single-charge/idempotency and no redispatch under timeout/retry with a real
   external client and site binding; do not reset paid accounts or enable billing.
7. **Staging acceptance:** exercise explicit reused keys, changed payload/key,
   two admin actors, two tenant accounts, site isolation, concurrency, crash between
   claim and artifact, crash between artifact and operation, actual approve/apply/
   Verify/signed proof/rollback, plus paired restore and reattempt. Record sanitized
   counts, operation **states**, source hashes, reason codes and rollback outcomes;
   exclude private IDs and raw content from public evidence.
8. **Final release control:** finish #92 real ChatGPT expiry/reconnect, #20/#36
   policy/QA gaps, #32 privacy/terms/onboarding; freeze artifact pair, repeat CI
   against exact SHA, produce separate Native and private Cloud ZIP with hashes.
   Obtain explicit owner authorization for live pilot, health verification and
   rollback plan. No auto-payments.

## Decision

A safe closed beta could launch with creation-write capabilities **disabled or
restricted to a controlled pilot** while legacy/uncertain claims are reconciled
and staging proves the full lifecycle. Source tests and an empty aggregate
report alone cannot establish readiness. The release gate remains **BLOCKED**
until independent external, migration and restoration evidence is attached
privately and checked under #40.
