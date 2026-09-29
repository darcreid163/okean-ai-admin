# WP AI Admin 2.0.61 — AI Lifecycle Control + Preview controls

## Goal
Allow an administrator to explicitly delegate sandbox lifecycle decisions to the connected AI while preserving the existing manual workflow.

## Safety model
- Disabled by default.
- Administrator must enable AI Lifecycle Control in WP Admin.
- Mode is owned by that administrator.
- First administrator MCP client that uses the mode becomes bound to it.
- A different MCP client is rejected until the administrator disables and re-enables the mode.
- AI calls reuse OAI_REST::sandbox_action.
- Existing sandbox expiry, snapshot conflict checks, Woo validation, Elementor guards, Verify, receipts/proofs and rollback conflict checks remain active.
- Manual WP Admin controls remain available at all times.
- Test Automation Mode remains a separate scoped regression-only mechanism.

## Preview controls
For a logged-in WordPress administrator, Okean AI Preview shows status-aware buttons:
- pending: Подтвердить / Отклонить
- approved: Применить / Отклонить
- applied or rollback_failed: Откатить

Preview mutation requests require:
1. active authenticated WordPress administrator cookie session;
2. a valid wp_rest nonce;
3. the ordinary sandbox lifecycle state checks.

A signed preview URL by itself cannot mutate the site.

## Live QA
1. Enable AI Lifecycle Control in WP Admin.
2. Create a normal sandbox Elementor operation on an existing page.
3. Call operation.approve from MCP and verify status=approved.
4. Call operation.apply from MCP and verify status=applied + Verify success.
5. Call operation.rollback from MCP and verify rollback restoration.
6. Repeat with manual WP Admin buttons to confirm parallel human control.
7. Open a pending operation preview while logged into WP Admin.
8. Use Подтвердить from the preview bar; confirm reload shows approved.
9. Use Применить; confirm reload shows applied and Откатить.
10. Use Откатить; verify baseline restoration.
11. Open the same signed preview in a logged-out/private browser and confirm lifecycle buttons are absent and mutation cannot be performed.
12. Confirm Test Automation regression behavior is unchanged.
