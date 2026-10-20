# WPVibe comparison: Elementor Schema/Save stage 1

Branch: `feature/wpvibe-gpl-elementor-adapter`. This stage must not be deployed.

## Existing baseline
- `OAI_Elementor::widget_schema()`, `OAI_Elementor::save_document()` and `OAI_Elementor_Adapter` already exist; avoid duplicating them.
- `OAI_Lifecycle` and operation/database/approval pathways remain the only authorized production mutation pipeline.
- Existing fixture `tests/elementor-single-source.php` covers precise edits and duplicated WordPress/Elementor content.

## New staged module
`includes/class-oai-elementor-schema-save.php` provides read-only schema/snapshot, snapshot-checked change preparation, explicitly gated apply, authoritative tree verification, and conflict-checked rollback. It is deliberately not included by the plugin bootstrap and not advertised in MCP tools. The boolean approval parameter is defense in depth for fixture tests, **not an authorization token**; do not wire the method to a user-callable route.

## Required integration next
1. Attach schema read methods to existing authorized MCP read dispatcher.
2. For mutations persist immutable before/after snapshots in OAI_DB operations, bind actor/site/client, and require existing server-validated human approval.
3. Invoke save only via existing approved apply pipeline; compare optimistic snapshot immediately before writes and lock operation against concurrent apply/rollback.
4. Record Verify/receipt/proof in the existing lifecycle engine. Fail closed on mismatched tree, even if saving returned success.
5. Authorize rollback independently via the existing engine and use its authoritative original snapshot. Never trust client-supplied before/after or a boolean flag.
6. Add WordPress+Elementor integration tests including CSS regeneration, dynamic controls, template/document types, protected metadata, concurrent editor changes and permissions.
7. Review upstream WPVibe code and licensing separately before copying any GPL implementation. This stage is original code and copies no WPVibe source.

## Test
`php tests/elementor-schema-save-stage1.php` — pure PHP isolated fixture, not a WordPress or live test.

No production install, changes to `main`, OAuth, quota, endpoint, or runtime tool registry are intended.
