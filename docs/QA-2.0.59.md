# 2.0.59: Elementor revision without page duplication

## Incident evidence (okean.market, 2026-09-29)

- Operation **817** created draft page **36457**, status `pending`, template `default`. It stored a large `post_content` mirror alongside one Elementor HTML widget. The new root container differs from the source front page's Elementor root and the draft does not inherit any front-page-specific theme context.
- Operation **818** is a separate `pending` `elementor_widget_html` operation for widget `e9b7c31` on page 36457. Its before/after snapshots each have one `okean-dark-page` wrapper; the diff replaces an SVG map with an image. The operation itself has not been applied and did not create the duplicate page content.
- On the draft, `post_content` is nonempty and the Elementor tree has one HTML widget. Do not approve 818 expecting it to repair the duplicate source. The old pending page is left intact for review; no live page was published or edited during this investigation.

## Contract

1. Initial `elementor.create_draft` accepts `title` plus `data`, or `title` plus `source_post_id` and no `data` to clone an existing Elementor page. With a source, copy its template, Elementor page settings and selected Woodmart layout metadata; preserve its widget tree when cloning. New content may be supplied as `data` while the layout settings inherit.
2. For newly created AI Admin pages only, `_elementor_data` is authoritative and `post_content` is empty after save. Repeated builder saves may regenerate the WordPress mirror, which is cleared again. If someone manually writes `post_content`, a later builder save returns a conflict.
3. For an existing HTML widget, read its outline/range and SHA-256, then call `elementor.patch_fragment` with `post_id`, `widget_id`, `expected_sha256`, `old_fragment`, `new_fragment`. The old fragment must occur exactly once; a changed widget hash or ambiguous match returns 409. The result is a normal sandbox operation. One letter, text, image tag and entire block use the same path.
4. Existing Elementor pages are not silently migrated. Their `post_content` and builder data remain untouched until an explicit operation is approved.
5. Managed Elementor pages reject `content.patch`, Gutenberg patch, generic `post_content` sandbox writes and direct lifecycle writes to `post_content`. The legacy whole-widget edit rejects an accidental double append of a large previous widget.

## Candidate verification

- CI: PHP syntax on 8.2, 8.3, 8.4; unit fixture for sequential image/text edits, one root/layout preserved, generated mirror cleanup, manual-content conflict, stale/ambiguous rejection; MySQL OAuth and batch mutex; ZIP integrity.
- After install: expect 91 MCP tools and 53 self-test checks. Use a new test draft cloned from page 31499, inspect its template, root structure and full-width preview. Run `elementor.patch_fragment` on the new draft with a small unique text fragment; verify exactly one replacement, then approve/apply/verify/rollback only within the scoped test session. Run Regression Matrix and confirm baseline restored.
- Pending operations 817 and 818 require separate cleanup after review. New code does not change their stored snapshots.
