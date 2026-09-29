# WP AI Admin 2.0.63 — QA

## Draft render inspection
1. Install 2.0.63.
2. Call wp.inspect_html for a published page: behavior must remain unchanged.
3. Call wp.inspect_html for an Elementor draft without allow_draft: must return Published readable post required.
4. Call wp.inspect_html for the same draft with allow_draft=true as administrator:
   - render_mode = authenticated_draft_server_render
   - post_status = draft
   - selector h1 returns rendered draft heading
   - selector .wai-nav returns rendered navigation
   - javascript_executed = false
5. Confirm the draft remains draft and unauthenticated public access is unchanged.

## Create-once patch-many regression
1. Enable Test Automation Mode.
2. Run system.regression_run with include_elementor_create_patch_many=true.
3. Scenario elementor_create_patch_many must:
   - create one Elementor draft;
   - patch the same HTML widget three times;
   - verify the widget occurs exactly once after every patch;
   - verify each replacement text occurs exactly once;
   - rollback patch 3, patch 2, patch 1 and then page creation;
   - finish with baseline_restored=true.
4. Confirm no created test page remains after cleanup.

## Expected contracts
- tools: 91
- self-test: 56/56
- direct_live_writes=false
