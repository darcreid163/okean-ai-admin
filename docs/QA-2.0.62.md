# WP AI Admin 2.0.62 — QA

## Scope
1. Verify plugin version 2.0.62 and build id `2.0.62-selftest-security-status-20260929`.
2. Run `system.self_test`.
   - Expected: 54 total checks.
   - Expected: `runtime.version = PASS`.
   - Expected: `security.ai_lifecycle_status_contract = PASS`.
3. Run `system.security_status`.
   - Must include `ai_lifecycle_control`.
   - Must include guard flags:
     - `ai_lifecycle_active`
     - `ai_lifecycle_opt_in`
     - `manual_lifecycle_controls_preserved`
4. With AI Lifecycle Control enabled:
   - `ai_lifecycle_control.active = true`
   - `guards.ai_lifecycle_active = true`
   - `guards.local_human_approval_enforced = false`
   - `human_approval_default = true`
   - manual controls remain available.
5. With AI Lifecycle Control disabled:
   - `ai_lifecycle_control.active = false`
   - `guards.ai_lifecycle_active = false`
   - normal human lifecycle remains required.
6. Confirm MCP catalog remains 91 tools.
7. Re-run Elementor single-source/fragment regression.
8. Re-run signed entitlement contract, MySQL mutex concurrency, and OAuth schema retention.
9. Build ZIP and verify archive integrity.
