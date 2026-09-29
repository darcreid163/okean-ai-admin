# WP AI Admin 2.0.65 — Responsive Visual QA

## Goals
- Remove unused grey area from the Visual QA shell.
- Make every preview use the full available browser width.
- Preserve exact internal test widths: 1440 / 768 / 390.
- Scale preview only visually; browser measurements inside the iframe remain authoritative.
- Ignore Elementor service helpers such as #elementor-device-mode.

## Acceptance
1. Install 2.0.65.
2. system.self_test = 58/58.
3. wp.visual_qa_start on a draft page.
4. Open the signed URL on desktop, tablet-size window and mobile browser.
5. Each preview card fills its available width without a grey unused block.
6. The label shows Fit %, while report widths remain 1440, 768 and 390.
7. Resize the outer browser window: previews re-fit automatically.
8. Reports no longer include #elementor-device-mode as clipping.
9. Source page status and content remain unchanged.
