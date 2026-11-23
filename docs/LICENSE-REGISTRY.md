# Cloud licence registry (0.3.13-dev)

Cloud Core is the only authority for the account plan and licence. Native sites,
MCP clients and OAuth clients never submit a plan, quota, `account_id`,
`site_id`, tenant or `license_id` that Cloud trusts without deriving it from the
authenticated account and its own rows.

This stage is quota/licensing only. It adds no payments, automatic money debits,
auto-renewal or paid-quota invention.

## Storage

The registry lives in the authoritative Gateway Store under the
`{wpdb->prefix}wpai_gateway_` prefix:

- `licenses` — immutable identity and mutable lifecycle/lineage fields;
- `license_activations` — one row per licence/site activation;
- `license_events` — append-only hashed evidence;
- `accounts.license_id` — the currently linked licence, if any.

The schema is created lazily by `Store::ensureLicenseSchema()` when a licence
operation or account provisioning actually runs. It is deliberately **not**
part of the gateway DB-version migration: historical 0.3.11 → 0.3.13 upgrade and
restore tests assert the exact production table set. DDL must run before a
transaction because MySQL implicitly commits on `CREATE TABLE`/`ALTER TABLE`.

The dormant Cloud Core v1 tables (`wpai_cloud_*`) remain separate and are not
used by the Gateway licence registry.

## Keys

- Format: `wpai_lic_` + 43 base64url characters (256 bits of entropy).
- At rest: only `key_hash = SHA-256(key)` and a 16-character display prefix.
- The plaintext key is returned exactly once by manual issue or reissue.
- Automatic bound licences are issued with their plaintext discarded; an
  operator can reissue a fresh key if one is needed.
- No list response, event row or log contains the plaintext key or
  `key_hash`.

## States

`unused` (input alias `generated`), `active`, `suspended`, `expired`,
`revoked`, `exhausted`, `replaced`.

- An unbound manual licence is `unused` until its first activation binds it to
  the authenticated owner.
- A bound licence is `active` immediately and applies its plan.
- `replaced` is set on the previous row during reissue or plan reassignment;
  `replaced_by`/`replaces` preserve lineage.
- `exhausted` is reserved for the quota engine and is not emitted
  automatically by this module.

## Lifecycle

- Automatic issuance: `Core::ensureAccount()` creates one current bound
  entitlement per account; `Core::setPlan()` replaces it atomically when the
  plan or scheme changes. A historical account is normalised to the final daily
  plan before issuance; an account whose persisted plan cannot be mapped
  (unknown or quarantined) is not force-licensed and keeps its previous
  behaviour.
- Retired scheme transfer: `Licenses::migrateRetiredScheme()` rewrites a licence
  that still carries `legacy_monthly_v1`/`tariffs_v2` to the equivalent final
  daily plan and records a `scheme_retired` event. The audited
  `Core::migrateRetiredSchemes()` cutover applies it to every stored licence, and
  the billable path re-checks it so a stale licence cannot revert a migrated
  account.
- Manual issuance: operator, optional owner, plan, scheme, activation limit,
  start/expiry or duration, comment. The plan is validated against the scheme;
  a bound manual licence must be active and must not start in the future.
- Activation: owner and site are derived from the authenticated WordPress user
  and the owner-scoped site row. Same-site activation is idempotent. A licence
  bound to another account is rejected with
  `license_bound_to_other_account`. Activation beyond
  `activation_limit` fails with `license_activation_limit_reached`.
- Paired-site activation: `POST /site/license/activate` lets a customer paste a
  purchased key in the Native dashboard. The caller proves possession of the
  pairing read key (`site_url` + `status_key`); Cloud derives the account and
  site from its own site row, throttles attempts and calls the same authoritative
  activation path. The request cannot submit a plan, account, site or licence id.
  The response is `wpai-site-license-v1` and never contains the plaintext key or
  its hash.
- Deactivation frees one activation slot.
- Suspend/revoke: the linked account falls back to Free (`plan=free`,
  `license_id=NULL`) before the next quota computation. Reactivate restores
  the licence plan.
- Expiry: checked lazily on access; an expired linked licence deactivates its
  activations, emits an `expired` event and falls the account back to Free.
- Reissue: preserves remaining expiry, activation limit, plan, scheme and
  comment; marks the old row `replaced`, revokes its activations and returns a
  new key once. Revoked and replaced licences cannot be reissued.

## Quota interaction

A linked licence must be `active` and started for its plan to be used. Any
unusable linked licence falls the account back to Free; accounts without a
linked licence keep their historical plan. Paid quotas for Pro, PRO+ and PRO++
(the stable slugs are `pro`, `business`, `businesspro`) remain
`requires_owner_approval` and fail closed for billable calls until the owner
supplies numbers. Reads, verification, receipts, rollback and usage projection
remain available.

## Dashboard display

The Native dashboard never derives a balance. It displays the server-signed
site-usage projection, which carries `plan_label`, the licence `id`,
`key_prefix`, `status`, `activation_limit`, `activations`, `issued_at` and
`expires_at`. The verifier treats the licence claims as optional for backward
compatibility, but validates them all-or-nothing when present; a perpetual
licence has a `null` expiry while every other claim must be present. The full
key is never sent to the site; only the display prefix is included. A pending
paid quota is shown as an explicit “awaiting owner approval” state with `limit`
and `remaining` set to `null`, never as zero or unlimited.

After a successful activation the Native dashboard refreshes the signed usage
projection and shows the Cloud-returned plan, status, activation count and
expiry. The entered key is sent once over HTTPS and is never stored locally or
logged; local state cannot grant an entitlement or change quota. Cloud refusal
reasons are mapped to safe administrator messages (invalid, bound to another
account, revoked, suspended, expired, activation limit, denied site, rate
limit).

## HTTP surface

Owner (authenticated WordPress user, WordPress REST/HTTPS only):

- `GET /licenses` — licences owned by the caller;
- `POST /licenses/activate` — `key`, `site_id`;
- `POST /licenses/{id}/deactivate` — `site_id`.

Paired Native site (no WordPress cookie or bearer token):

- `POST /site/license/activate` — `site_url`, `status_key`, `license_key`;
  account, site and plan are derived by Cloud.

Operator (`manage_options`, confirmation token required for destructive
actions):

- `POST /licenses` — manual issue;
- `POST /licenses/{id}/reissue|revoke|suspend|reactivate`.

The admin page also exposes issuance, activation, reissue, revoke, suspend and
reactivate forms, a one-time key display and the latest 50 licences (including
unbound ones). The issue form renders only plan/scheme pairs accepted by the
catalogue: the single final scheme `tariffs_v3` offers Free, Daily, Pro, PRO+
(`business`) and PRO++ (`businesspro`). Retired `legacy_monthly_v1`/`tariffs_v2`
schemes and the `power`/`agency`/`scale` slugs are no longer offered, so the form
cannot produce `unknown_plan` by submitting a retired pair. The Native dashboard
exposes the paired-site activation form and the signed usage refresh.

## Tests

`tests/license-registry.php` runs on SQLite and MySQL in `cloud-validate.yml`
and covers key format/hash/one-time display, binding, activation limits,
idempotency, cross-account isolation, automatic issuance, legacy compatibility,
plan lineage, reissue, suspend/reactivate/revoke, expiry fallback, event
append-only evidence and schema/transaction guards.

`wp-ai-admin-cloud/tests/site-usage-projection.php` covers paired-site
activation, idempotent replay, foreign site/key rejection, cross-account bound
keys, activation limits, revoked keys and absence of the plaintext key in the
response. `tests/cloud-license-activation.php` covers the Native side: request
shape, server-derived identity, signed-usage refresh, pending quota, local
refusals, Cloud error mapping, no local key storage and rejection of an invalid
usage signature after activation.