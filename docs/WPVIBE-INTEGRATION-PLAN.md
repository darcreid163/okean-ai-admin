# WPVibe capability integration — implementation plan

**Status:** integration branch created; implementation is staged behind this plan.
**Target:** WP AI Admin Native plugin only. Cloud Core must not contain WPVibe-derived code.
**Upstream:** https://github.com/awesomemotive/wpvibe-ai-mcp
**Reviewed upstream ref:** `main`, tree `f6824a089c20bd8e52b2c2bad32f931333e17739`
**Upstream licence:** GPL-2.0 (repository metadata); local project licence is GPL-2.0-or-later.
**Base:** `22e625845b3f3d4306971d183d4b9897b03c95c7`

## Non-negotiable integration contract

Every operation must remain inside the WP AI Admin lifecycle:

`Read → Plan/Sandbox → Approval → Apply → Verify → Receipt/Proof → Rollback`

No new direct live-write endpoint. No alternate approval flow, quota counter, audit ledger, token store, or authentication path. All billable operation outcomes must be finalized by Cloud Gateway Ledger. Preserve tenant/site isolation, OAuth/PKCE, capabilities, idempotency, mutation conflict checks, verification, signed receipts and rollback.

WPVibe files are GPL-compatible source, but each copied/derived file requires preserved upstream copyright and licence notices, an exact upstream commit, a local path, a modification note, and corresponding source distribution. Do not copy code into the private Cloud Core. Do not port generic arbitrary REST writes, arbitrary PHP execution, self-updaters, shell execution or any shortcut around approval.

## Existing local foundations (avoid duplication)

- Native already has `OAI_Abilities`, registered on `wp_abilities_api_init`.
- Native has `OAI_Command_Registry`, `OAI_WP_CLI_Execution`, `OAI_Execution_Dispatcher`, argument/permission resolvers.
- Native has `OAI_Files`, `OAI_Operations`, receipts/proofs, verification and rollback.
- Native has an adapter catalog, builder manager, Elementor adapter, Gutenberg support and Woodmart integration.
- Open PR #199 addresses one legacy WPVibe-branded capability key; coordinate with it and do not reintroduce branding.

## Upstream capability inventory and port strategy

| Upstream capability | Local status / action | Gate |
|---|---|---|
| MCP tools / content operations (posts, pages, CPT) | Compare with existing content/read/operation registry; add missing verbs to existing schemas and dispatcher, never an independent REST writer | capability + schema + sandbox/approval + verify + ledger |
| WordPress Abilities API | Extend current OAI_Abilities to expose only explicitly public abilities (REST-visible or MCP-public), filter/paginate, inspect schemas, normalize/validate/check permissions and execute only through approved operation bridge | WordPress-version feature detection; destructive abilities require plan+approval |
| WP-CLI 34-command allowlist and native PHP dispatch | Diff upstream allowlist/handlers against OAI_Command_Registry. Adopt only missing safe commands and argument validation; never shell out or invoke a binary | registry entry, capability map, denylist, destructive plan, per-object ledger |
| Theme draft / preview / PHP syntax validation | Reuse OAI_Files and operation preview/apply flow; port only missing validation/preview details | path sandbox, extension allowlist, token_get_all(TOKEN_PARSE), no source execution |
| File operations | Compare path traversal/symlink/realpath/write allowlist and read/write implementation; improve local class only | active-theme root, path canonicalization, symlink escape rejection, bounded reads |
| Media / Unsplash | Compare upload/search implementation. Integrate as a planner/provider and media operation; preserve external image attribution | upload_files, MIME/content validation, size limits, safe download, attribution |
| Skills / load_skill | Implement first-party progressive skills registry and discovery in Native; no new unsafe execution capability | static reviewed instructions, versioned schemas, no executable remote skill payloads |
| Elementor / Gutenberg | Existing local adapters remain source of truth; compare round-trip preservation/cache invalidation tests | fixture tests, unknown-data preservation, preview and rollback |
| Divi | Add adapter only after upstream schema and runtime requirements are verified | integration tests with disposable Divi fixture; fail closed if absent |
| Bricks | Add adapter only after verifying schema and native save/cache APIs | integration tests; no raw meta writes unless native codec requires and is wrapped |
| Breakdance | Add adapter only after verifying codec, validation and cache lifecycle | integration tests; protect existing layout on failure |
| Beaver Builder | Add adapter only after validating node object shape, kses/capability checks and save/merge semantics | integration tests; no partial save |
| SeedProd | Inventory upstream tree to establish whether an adapter exists; no inferred support | implement only if upstream code and testable contract exist |
| Divi/Bricks/Breakdance/Beaver/SeedProd cache | Use each builder's own supported invalidation hooks, not generic cache clearing | assert saved document renders and cache is refreshed |

## Ordered implementation phases

1. **Inventory and safety baseline** — list exact upstream file tree and commit; compare local class coverage, existing tests, native/cloud boundaries; document candidate files and licenses.
2. **Abilities parity** — close any gap in discovery/schema/permission/execute behavior without bypassing planner; WordPress pre-6.9/6.9+ compatibility and non-public ability tests.
3. **WP-CLI parity** — machine-readable diff of allowlists, missing command coverage, capability checks and unsafe flag tests. Keep existing command registry canonical.
4. **Theme/files/media hardening** — path sandbox and extension tests, PHP syntax validation, staged theme-file diffs, media validation/provider flow.
5. **Skills** — static progressive guidance for core, content, WooCommerce, Elementor, Gutenberg and each verified builder; skill metadata and loading tests.
6. **Builder adapters** — implement and test each confirmed upstream adapter one at a time, using native save APIs and rollback-safe operation lifecycle.
7. **Full QA and packaging** — PHP lint, unit/integration tests, WP/PHP matrix, capability negative tests, no direct-write tests, archive manifest, license review, regression suite.
8. **Draft PR only** — report gaps and evidence. Do not merge, tag, publish or deploy without owner approval and green exact-HEAD CI.

## Required comparison evidence

For each candidate upstream file record:
- upstream path and blob SHA at reviewed commit;
- upstream copyright/license header;
- current local equivalent and exact uncovered behavior;
- classification: reuse as-is / adapted derivative / reimplement from behavior / do not port;
- local path and class/function mapping;
- tests added and CI evidence.

## Acceptance criteria

- No new endpoint can mutate live state without a sandbox operation and explicit approval.
- No bypass of Cloud Gateway Ledger; successful objects are accounted once by idempotent receipt/idempotency identity.
- Abilities which are not explicitly public are not enumerated or executed; the ability permission callback is enforced at execution.
- Every command is allowlisted, flags are normalized, capabilities are checked per command, and sensitive options remain denied.
- File operations cannot escape the active-theme sandbox, including traversal and symlink cases.
- Invalid PHP is rejected before save without executing the submitted source.
- Builder adapters detect missing/incompatible builders and preserve existing content on failed save.
- Native ZIP contains no Cloud Core files; Cloud Core contains no upstream-derived code.
- Attribution and GPL source obligations are documented before any port is merged.

## Initial implementation note

This branch begins as a planning/inventory baseline. It does not claim that the complete WPVibe feature set has already been implemented. The next implementation commits must be small, testable, and mapped to the phases above.

## First implementation increment — public Abilities discovery

Implemented on this branch:
- Added `OAI_Abilities_Compat` as an original Native compatibility layer.
- Added read-only public Ability inventory with category filter, paging, schema exposure and stable ordering.
- Exposure is restricted to WordPress REST-visible abilities or explicit MCP-public metadata. A malformed MCP metadata structure fails closed.
- Added REST endpoints `GET /okean-ai/v1/abilities/public` and `GET /okean-ai/v1/abilities/public/{name}`.
- Added a read-only `ability schema` entry to the existing command registry/dispatcher.
- Ability execution intentionally returns a plan-required conflict; it does not call arbitrary `execute()`. A later phase must map an ability to a typed WP AI Admin operation before any mutating ability can execute.
- Added the compatibility file to the Native bootstrap and added its provenance note to `THIRD-PARTY.md`. This implementation is original code informed by the upstream behavior; it is not a source copy.

Validation performed so far:
- GitHub read-back confirms bootstrap include, both REST route registrations/callbacks, command registry entry, CLI dispatch handler, fail-closed public metadata filter, plan-required execution guard and attribution entry.
- Full PHP lint, WordPress integration tests and Actions CI have not yet been run. Do not treat this as release-ready.
- WordPress Abilities API exists only on newer WordPress versions; the implementation checks function availability and returns a controlled unavailable response on older installations.

## Upstream source inventory and WP-CLI coverage delta (reviewed 2026-11-16)

Pinned upstream commit: `f6824a089c20bd8e52b2c2bad32f931333e17739` (2026-11-11), tree `f44a7dee5a042d93adc985e277957a8150ae8d75`. The upstream `class-wpvibe-cli.php` declares 139 allowlist entries, not 34. The local Native Command Registry has 43 entries at the baseline reviewed in this increment; 41 names overlap exactly, and the raw upstream-minus-local delta contains 98 distinct command keys. This is a textual inventory only: alias normalization, command semantics and safe implementation are not yet reconciled, so it is not a claim that 98 independent product features are missing.

### Command delta — candidates, not approved to enable

The exact name-only upstream delta:
```text
plugin status
plugin search
theme status
option pluck
option list
post term list
term meta get
term meta list
comment create
comment approve
comment unapprove
comment spam
comment unspam
comment trash
comment untrash
comment delete
rewrite list
cache type
plugin auto-updates enable
plugin auto-updates disable
plugin auto-updates status
core update
core update-db
core verify-checksums
plugin verify-checksums
post type list
menu location list
help
cli version
cli info
config get
maintenance-mode status
media image-size
transient get
theme get
cron test
plugin install
plugin uninstall
option add
option delete
transient delete
transient list
user delete
post delete
post meta update
post meta add
post meta delete
post term set
post term add
post term remove
cache flush
litespeed-purge
elementor flush-css
elementor flush_css
rocket clean
sg purge
super-cache flush
w3-total-cache flush
breeze purge
option patch
rewrite flush
search-replace
menu create
menu item add-custom
menu item add-post
menu item add-term
menu location assign
term create
term update
term delete
term meta update
term meta set
term meta add
term meta delete
menu item update
menu item delete
theme mod set
rewrite structure
cron event run
cron event delete
theme install
theme update
theme delete
cap add
cap remove
role create
role delete
role reset
user add-cap
user remove-cap
user create
user update
user set-role
user add-role
user remove-role
user meta add
user meta update
user meta delete
```

### Security classification before any port

- **Read-first candidates:** plugin/theme status/search, option pluck/list (with strict option denylist), post-term list, term-meta key inspection/redaction, rewrite list, cache-type inventory, checksums, help/catalog metadata, CLI identity, config get with secret redaction, maintenance status, media image sizes, transient get/list with credential redaction, cron test as diagnostic only, menu-location list.
- **Writes that must become typed sandbox operations:** comment moderation/create, post and term lifecycle, post/user/term metadata, menu create/update/delete/location assignment, theme mods, rewrite configuration, cache purge/flush, transient delete, role/capability/user changes, plugin/theme install/update/activate/deactivate/uninstall, core update and DB update, search-replace. No upstream handler may be called directly from the read dispatcher.
- **Not ported:** shell/eval, arbitrary SQL mutation, arbitrary PHP, arbitrary REST passthrough, hidden credentials/options, automatic updater/self-update and any bypass of Cloud Ledger or approval.

### Actual builder source files found in the upstream tree

The recursive source tree confirms these builder-specific files:
- `includes/class-wpvibe-beaver.php` — Beaver Builder
- `includes/class-wpvibe-breakdance.php` — Breakdance
- `includes/class-wpvibe-bricks.php` — Bricks
- `includes/class-wpvibe-elementor.php` — Elementor
- `includes/class-wpvibe-elementor-validator.php` — Elementor validation
- No Divi or SeedProd implementation file was present in the reviewed tree by the builder-specific path search; do not market native write-adapter support for those based on this upstream snapshot.
- A generic `includes/class-wpvibe-file-ops.php` is present; the earlier assumed `class-wpvibe-content-files.php` path exists in the source but represents a different operation family and must be compared separately.

The Bricks, Breakdance and Beaver Builder sources reveal distinct native data shapes, security hooks and cache lifecycles. They must be adapted individually to `OAI_Builder_Adapter` and operation lifecycle; copying their REST write handlers is not an acceptable integration.

## Media concurrency blocker discovered during CI

Native Actions run `43835535719` failed its disposable latest WordPress smoke in the existing concurrent media creation test. The error reported one winner, five denied requests, but unexpected extra files/attachments and an extra operation; losing paths raised `Media artifact outcome requires reconciliation` / `Media creation outcome requires reconciliation`. Pinned WordPress 7.0.6 smoke and PHP 8.2/8.3/8.4 lint/contract jobs passed. This is being tracked separately as issue #209; no production changes were performed. The failure is not attributed to the WPVibe adapter without evidence.

## Updated scope status

- Branch and draft PR are established.
- Public Abilities discovery/schema increment is present, but arbitrary Ability execution remains deliberately disabled.
- WP-CLI exact delta is inventoried; the delta has not yet been implemented.
- Theme/file/media operations and builder adapters are not yet fully integrated.
- Skills are not implemented in this branch.
- Latest-compat media concurrency blocker remains unresolved.
- No production package was built or deployed from this branch.
