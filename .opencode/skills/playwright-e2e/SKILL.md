---
name: playwright-e2e
description: Browser E2E for WP AI Admin connection, dashboard, tool workflows, approval, quota display and admin paths.
---
# Playwright E2E
Reuse existing setup. Run against disposable WP/Cloud services and synthetic accounts/data. Prefer accessible roles/labels and stable test IDs over brittle CSS or sleeps. Test connect → select site → discover → authorized read → plan/approve mutation → verify → receipt/usage → refresh/reconnect → dashboard reconciliation. Include denied permissions, expired auth, unsupported plugins/themes, quota exhaustion, partial batch and server errors. Assert server quota equals UI; never reproduce billing logic in browser. Capture traces/screenshots without secrets or customer data. Keep tests deterministic, isolated, parallel-safe and clean up synthetic entities. Report browser/environment/versions/counts/failures.
