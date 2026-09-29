# WP AI Admin 2.0.60 — Test-created Elementor draft scope

## Base
Built strictly on top of 2.0.59. The following 2.0.59 fixes remain intact:
- Elementor draft uses _elementor_data as the authoritative source.
- generated post_content mirror is cleared for AI-created Elementor drafts.
- source_post_id can preserve Elementor tree/page template/layout settings.
- elementor.patch_fragment uses exact current SHA-256.
- whole-widget duplicate append guard remains enabled.

## Fix
When elementor.create_draft is created and successfully tagged inside an active Test Automation session:
1. preview_page_id is validated to remain draft/private;
2. the page ID is added to allowed_post_ids for that same session;
3. the page ID is recorded in session_created_post_ids;
4. subsequent elementor.patch_widget / elementor.patch_settings / elementor.patch_fragment operations can be tagged by the same MCP client and administrator;
5. the normal sandbox → approval → apply → verify → rollback guards remain unchanged.

The dynamic allowlist is session-scoped and becomes inert when the session expires, is disabled, belongs to another administrator/client, or the build changes.

## Regression
The Elementor fixture now verifies:
- single-source content;
- in-place text/image fragment edits;
- stale/ambiguous fragment rejection;
- duplicate whole-widget guard;
- layout preservation;
- manual post_content conflict protection;
- automatic scope inheritance for a created Elementor draft;
- a follow-up Elementor widget patch on that created draft is eligible in the same Test Automation session.

## Expected runtime after installation
- Version: 2.0.60
- Build: 2.0.60-test-created-draft-scope-20260929
- MCP catalog: 91 tools
- Self-test: previous 53 checks plus created_draft_scope_contract (expected 54 if all pass)
- direct_live_writes=false
- human approval remains default outside scoped Test Automation

## Live QA target
Repeat the real site scenario:
1. create an Elementor draft through elementor.create_draft;
2. approve/apply/verify creation;
3. patch only the existing widget/fragment on the same page;
4. confirm the follow-up operation receives the current session tag;
5. approve/apply/verify;
6. confirm node count does not increase unexpectedly and no duplicate page content appears;
7. rollback the patch and verify baseline restoration.
