# Elementor page creation admission — bounded #103 slice

Native Elementor new-page creation writes a WordPress draft and Elementor
document before its pending sandbox operation exists. An interrupted response
could otherwise allow a retry to write another draft. This slice creates an
atomic, durable, actor/blog-scoped creation intent in a non-autoloaded
`oai_elementor_page_creation_*` option **before** calling the registered
builder adapter's `create()`. No timestamp/TTL automatically releases it.

`elementor.create_draft` and `/okean-ai/v1/elementor/create-page` accept an
optional `idempotency_key` (16–128 ASCII `A-Za-z0-9._~-`). Same key and
same payload returns 409 `oai_page_creation_pending`, different payload with
same key returns 409 `oai_page_intent_conflict`; new explicit key is a new
intent. Legacy no-key calls fall back to deterministic hash of the validated
source/title/excerpt/slug/status/template/type and decoded *caller* Elementor
nodes. This hash is created **before** random wrapper/widget IDs are filled by
`normalize_elementor_page_data`, preventing indistinguishable retries
becoming different hash keys due to server-generated values. Associative
object-key ordering is canonical, but element/list ordering remains semantic.
Ownership, editable source-page checks, builder-data decoding/validation,
`edit_pages` permission, and MySQL autocommit all precede reservation.
The unique WordPress options key arbitrates independent concurrent workers.

A 409 returns no previous page ID, private operation ID, claim or receipt. It
is duplicate **rejection**, never replay of the old operation's output.
Uncertain failures, timeouts, missing metadata and old reservations stay held
until independent site evidence establishes the actual state. An operator must
correlate the option claim, draft post, Elementor saved nodes, pending
operation row, Verify and any approval/rollback before deciding whether to
release a claim. Do not delete an artifact or claim based solely on age, error
status or an absent HTTP response. Existing historical draft pages are not
backfilled, and no production or account migration is part of this PR.

Real disposable WordPress/MySQL CI covers successful draft, same-key repeat,
changed-payload conflict, deliberate second creation, invalid key, active
transaction denial, anonymous denial, legacy no-key input, generated wrapper
IDs and six separate concurrent WordPress workers yielding one page and one
operation. Existing media/menu/Woo/workspace and
approval/apply/Verify/rollback/upgrade checks still run. #103 and the
closed-beta release gates remain open for reconciliation, historical rows,
full HTTPS/live testing and other creator paths.
