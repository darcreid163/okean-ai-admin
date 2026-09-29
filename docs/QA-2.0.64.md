# WP AI Admin 2.0.64 — QA

## Visual QA probe
1. Install 2.0.64.
2. Run system.self_test.
   - Expected: 58/58.
   - visual_qa.available = PASS.
   - visual_qa.contract = PASS.
3. Run wp.visual_qa_start for an editable published or draft page.
4. Open the returned signed URL in a normal browser.
5. The page must render three isolated viewports:
   - desktop 1440px
   - tablet 768px
   - mobile 390px
6. The browser must measure and report:
   - horizontal overflow
   - offscreen elements
   - clipped content
   - small interactive targets
   - text below 12px
   - sticky/fixed overlap with interactive controls
7. Run wp.visual_qa_result with probe_id.
   - Expected status: complete.
   - javascript_executed = true.
   - browser_measurements = true.
8. Confirm source post status is unchanged and no content is modified.
9. Confirm signed probe expires after one hour and report retention is bounded.

## Security
- Probe is bound to probe_id + post_id + expiry using HMAC.
- Frames and report POST require the same signed token.
- No public post publication is performed.
- No external headless-browser service is required.
- Report payload is limited to 200 KB.

## Expected release contracts
- MCP tools: 93
- self-test: 58
- direct_live_writes=false
