# WP AI Admin 2.0.68 — Unified Page QA

## Goal
One MCP tool should coordinate the existing server-rendered page inspection and Browser Visual QA without changing content.

## Tool
`system.page_qa_run`

### Phase 1
Input:
- post_id

Expected:
- render editable published/draft/private/pending page without publishing
- static DOM/a11y/link checks
- signed Browser Visual QA probe
- browser_url + probe_id
- status=pending_browser

### Phase 2
Input:
- post_id
- probe_id

Expected after browser_url has completed:
- static findings
- Visual QA desktop/tablet/mobile report
- one qa_status PASS/WARN
- no publication
- no content mutation

## Static checks
- exactly one H1
- heading level jumps
- images missing alt attributes
- form controls without accessible names
- empty links
- javascript: links
- broken same-document #fragment links
- duplicate IDs
- bounded findings (max 20 per category)

## Acceptance
1. plugin version 2.0.68 / matching build ID.
2. tool catalog = 94 with Content Navigation enabled.
3. self-test = 59/59.
4. page_qa.contract PASS.
5. system.page_qa_run exported by MCP.
6. First call on draft Home creates no page/content operation.
7. Browser probe remains signed, one hour, no publication.
8. Second call after browser completion returns unified PASS/WARN.
9. Probe/post mismatch is rejected.
10. Existing Visual QA and prior regression contracts remain green.
