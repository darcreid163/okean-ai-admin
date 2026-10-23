# Existing-target pending intent guard — stage 1

New authenticated existing-target edits are fingerprinted before insertion from
WP actor, exact type/target, typed before/after snapshots and metadata/rollback
semantics. Object key order is canonical; list order and scalar types remain
significant. The server replaces the reserved fingerprint metadata field.

MySQL named-lock serialization surrounds one conditional INSERT, which also
checks lock ownership on the executing connection. Reconnect/lost-lock cases
cannot silently insert without the guard. An outer transaction or disabled
autocommit fails closed: releasing the lock before commit would be unsafe.
The current session isolation is reapplied for the next transaction only;
MySQL refuses that operation inside an already active transaction without
committing it. Servers without these primitives fail closed for guarded edits.

An identical pending/approved unexpired intent returns `oai_duplicate_pending`
(409), without IDs, cached results, approval or evidence reuse. In-flight and
failed/unknown lifecycle states block regardless of age. Expired pending/approved
or terminal intents permit a fresh creation. Different actor/payload/baseline/
rollback semantics are independent. Existing target authorization, expiry,
conflict, approval, Verify and Rollback remain required.

This is duplicate rejection, not idempotent success replay. Native returns an
error to the caller; Cloud accounting remains authoritative and unchanged.
No claim is made that it refunds already settled or uncertain Cloud requests.

Covered types: generic content/title/excerpt, option/theme setting, scoped file
writes, Woo product updates, Elementor widget/layout updates, media metadata/
featured and existing menu edits. New artifact creators and workspace mutation
are intentionally excluded because their draft side effects precede the ledger
insert. Historical rows without a fingerprint are not backfilled. Anonymous
internal fixture inserts are unchanged; public authentication is still mandatory.
Parent #103 remains open for those remaining paths and rollout acceptance.

`wordpress-pending-intent.php` uses actual WordPress/MySQL and six independent WP
processes. It covers repeated content PATCH, row/audit immutability, typed JSON,
actor/payload/baseline changes, terminal/expiry/unknown outcomes, transaction and
lock loss, and one concurrent winner. It runs on both hosted WordPress profiles
alongside existing approval/verify/rollback and paired restore suites. No schema
migration, version bump, production mutation or old evidence rewrite occurs.
