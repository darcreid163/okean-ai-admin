# WP AI Admin 2.0.67 — Scroll-aware Visual QA

## Acceptance
1. Install 2.0.67.
2. system.self_test = 58/58.
3. visual_qa.contract includes scroll_container_aware=true.
4. Run Visual QA on Features and Pricing.
5. On mobile, tables inside overflow-x:auto/scroll containers must not produce offscreen findings for cells beyond the viewport.
6. A genuinely offscreen element outside any local horizontal scroll container must still be reported.
7. Desktop and Tablet behavior remains unchanged.
8. No horizontal page overflow, clipping, overlaps, small targets, or small text regressions.
