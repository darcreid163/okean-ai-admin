# Durable media creation admission — stage 2 of #103

Image validation and upload permission checks precede the guard. A normalized
filename/byte SHA-256/MIME/title/alt intent is scoped to the authenticated WP actor
and atomically reserved in a non-autoloaded `oai_media_creation_*` option before
any persistent upload. Record values contain a server-generated claim, recovery
filename/content hash, timestamp, attachment ID when known and operation ID when
bound. Raw image bytes and OAuth credentials are not stored in this journal.

Option uniqueness provides atomic admission across requests. Exact-value CAS
protects generation replacement and binding; database reads bypass option caches.
Autocommit/transaction checks run before artifacts. The operation INSERT checks
persisted ownership and suppresses repeated SQL for its claim. A committed INSERT
with a lost reply can recover only that invocation's operation ID/target/original
snapshots, never another caller's receipt. Fresh retries return a 409 without IDs.

A pending/in-flight/failed/corrupt/missing binding stays held, including after
process death or an old timestamp. A known terminal linked operation can admit a
new generation only without an active execution lease and with read-only Verify
(or confirmed absence for rejected/expired artifacts). Expiry alone does not
remove a private artifact or release creation admission.

Ambiguous storage exceptions propagate as sanitized exceptions, preserving the
existing Native/central unknown-outcome reservation path. They are not converted
to definite failed JSON-RPC replies. Private artifacts and the durable record are
retained for evidence-based recovery; early crashes can leave attachment_id=0 and
require an uploads/attachment inventory. Known returned errors preserve the
original cleanup path while conservatively retaining the claim. A lost final
binding update still returns the valid newly created operation; its claim stays
held for reconciliation. Cloud ledger, charge and reconciliation code is unchanged.

Generic operation cleanup excludes journal-marked media rows, including terminal
ones, so it cannot erase recovery evidence. Dedicated operator reconciliation and
retention remain a pre-production gate in #103. Do not blindly delete these options,
clear them by age, auto-replay unknown uploads or restore only part of code/DB/files.

Scope: validated direct uploads and URL imports through `media_upload`. No schema
or version migration, historic backfill, new page/product/menu/workspace guard,
production deployment, tariff change or automatic refund claim is included.

Tests use real disposable WordPress/MySQL, both GD/Imagick CI profiles and six WP
workers: duplicates/URL normalization, actor/input changes, claim ownership, actual
approval/verify/rollback, terminal and ambiguous outcomes, lost bindings, replayed
SQL/lost replies, DB reconnect, protected cleanup evidence and one private artifact
under concurrency. Test-only fixture cleanup is not a production recovery tool.
