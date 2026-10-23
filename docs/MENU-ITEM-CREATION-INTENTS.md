# Navigation menu item creation admission — bounded #103 slice

`menu.add_item_sandbox` creates a new WordPress `nav_menu_item` post
**before** its sandbox operation row exists. A repeated call after a timeout
or a second concurrent request previously created another draft item.

After menu existence and sanitized item validation, Native now requires
`edit_theme_options`, refuses active MySQL transactions and atomically
reserves a unique non-autoloaded `oai_menu_item_creation_*` WordPress option
**before** calling `wp_update_nav_menu_item`. The identity binds blog,
authenticated actor, menu ID and a caller-provided retry key. The option
record holds a hash of normalized WordPress `menu_item_args`, never raw
customer fields. Duplicate same-key/same-item returns 409
`oai_menu_item_creation_pending`; a changed item with the same key and
menu returns `oai_menu_item_intent_conflict`. A different explicit key
creates a new intentional item. Missing key means conservative hash of
sanitized menu-item content, blocking exact legacy retries.

`idempotency_key` is an optional 16–128 ASCII string using
`A-Za-z0-9._~-` and is advertised in the MCP schema. A caller must reuse
the same key for retries and choose a fresh one only for another creation.
No claim, item ID, previous operation ID or receipt is returned with a
duplicate error; this is denial, not successful replay.

Pending, failed or uncertain outcomes remain held, regardless of claim age,
term/post presence and expiry. Operator-only recovery/retention needs to
correlate the option claim, matching post and nav-menu relationship, linked
operation, Verify, approval and rollback. Neither this change nor generic
operation cleanup removes old claims. Historical items have no backfill;
Cloud usage/quotas and user data are unchanged.

Disposable real WordPress/MySQL regression tests cover exact retry,
sanitized markup aliases, changed URL/key conflicts, independent actors,
invalid key, active transaction, anonymous call, legacy no-key fallback,
and six concurrent PHP/WordPress workers yielding one navigation item and
one corresponding sandbox operation. Existing menu lifecycle and other
artifact-creation tests continue. Parent issue #103 remains open for
operator reconciliation, historical artifacts and staging acceptance.
