# Native operation risk policy

`OAI_Operations::risk(type, target)` is the canonical classification for new
operations. The operation catalog and every registered creator use this resolver.
Only title/excerpt edits are low; content, known Elementor, commerce, media and
menu edits are medium. Option/theme settings and file writes are high. Unknown
or malformed type identifiers are high until reviewed explicitly. Classification
does not grant support, permission, approval, or execution rights.

The planner label `workspace_write` describes an isolated workspace surface;
the actual `theme_file_write` operation that proposes changing a live file is
high. A filename or target string cannot lower the type's classification.

New audit events copy the stored row's risk into their details; receipts expose
the stored value. Existing operation rows, signed receipts/proofs and old audit
records are not migrated or silently relabeled. Protected-option policy and
all authorization, approval, conflict, verification and rollback guards remain
independent requirements.

Offline coverage enumerates expected classes, catalog agreement, conservative
unknowns and historical audit semantics. Disposable WordPress coverage creates
real generic/content/Gutenberg/Woo/Elementor/media/menu/file/workspace sandboxes,
checks SQL rows, public results, audit and receipts, verifies planning leaves
content/files untouched, and exercises protected rejection and a complete safe
option approval/apply/verify/rollback lifecycle. CI also retains paired historical
upgrade/restore and existing GD/Imagick lifecycle suites. No production data is
used or changed by these tests.
