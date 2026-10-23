# Menu creation admission — bounded #103 slice

Menu creation makes a persistent WordPress navigation-menu term **before**
the sandbox operation row exists. A lost response or concurrent retry could
otherwise create additional terms or leave orphaned artifacts. This slice
reserves an authenticated actor/blog/normalized menu-name/request-key intent
in a unique non-autoloaded `oai_menu_creation_*` option before calling
`wp_create_nav_menu`. The preflight refuses non-autocommit MySQL connections.

`menu.create_sandbox` accepts an optional `idempotency_key` (16–128 ASCII
`A-Za-z0-9._~-`). Reuse a key for retries; a different key is a **new**
intent. Calls without a key use a fail-closed legacy identity per actor/name.
Retries return 409 with only a status, without an operation/menu ID or cached
response. Alias case folding occurs after WordPress name sanitization.
WP's own name conflict rules still apply: a different nonce cannot override
a pre-existing menu with the same name.

Successful admission creates the term and a normal pending sandbox operation.
Approval, Verify, receipt/proof, rollback and Cloud quotas are unchanged.
Failures after reservation, timeouts and old claims remain held without
automatic TTL. A 409 alone is **not evidence** that an underlying write did
not happen. A separately reviewed operator reconciliation/retention mechanism
must verify the option claim, menu term, linked operation, lifecycle status
and other references before allowing a replacement. Do not delete claims,
menus or operation rows merely because they are old. Historical menus and
pre-existing operation rows have no backfill in this change.

Disposable real WordPress/MySQL tests include exact-key retry, normalized
case aliases, distinct payload/key, another actor, invalid input, active
transaction refusal, anonymous permission denial, and six concurrent workers.
Acceptance requires exactly one term and one operation from those workers.
No production installation or account/tariff changes are authorized.
The remaining #103 creator and operator recovery gates stay open.
