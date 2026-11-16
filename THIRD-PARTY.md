# Third-party code and attribution

Owner and copyright holder of this plugin: **Андриянов Н.Г.**
This repository is distributed under **GPL-2.0-or-later** (see `LICENSE`).

This file is the authoritative list of third-party code included in, or derived into, this repository. Update it in the same pull request that adds or changes such code.

## Reviewed upstream projects

| Project | License | Upstream | Reviewed ref | Status |
|---|---|---|---|---|
| WPVibe (Awesome Motive) | GPL-2.0 | https://github.com/awesomemotive/wpvibe-ai-mcp | `main`, tree `f6824a089c20bd8e52b2c2bad32f931333e17739` (2026-11-16) | reference reviewed; implementation ports must be recorded per file |

WPVibe is GPL-2.0 and this Native plugin is GPL-2.0-or-later. Code ports are possible subject to GPL-2.0 obligations and preservation of upstream notices. The integration plan is `docs/WPVIBE-INTEGRATION-PLAN.md`.

## Per-file port register

No WPVibe source files have been copied or adapted by this initial planning commit. For every later port, add a row containing the exact upstream file, upstream blob SHA, local destination, change summary and tests. Keep upstream copyright and licence notices in each derived file and disclose the complete corresponding source with distributed builds.

| Upstream file | Upstream blob SHA | Local file | Modification | Status |
|---|---|---|---|---|
| — | — | — | No source copied in planning baseline | pending implementation |

## Mandatory obligations

1. Preserve upstream copyright and GPL-2.0 notices in every copied or adapted file.
2. Record exact upstream commit/tree and file blob SHA, local path and modifications in this table and in the source header.
3. Do not relicense or place WPVibe-derived code in the private Cloud Core repository.
4. Distribute the complete corresponding source for recipients of a build containing GPL-derived material.
5. Do not imply endorsement or affiliation with Awesome Motive.
6. Review this file in every PR carrying third-party code; obtain owner approval before merge.

## Integration constraints

All code must follow WP AI Admin's lifecycle:
`Read → Sandbox/Plan → Human approval → Apply → Verify → Receipt/Proof → Rollback`.

Do not port or expose functionality that bypasses this lifecycle, including:
- arbitrary REST write passthrough;
- arbitrary PHP/code execution;
- shell or WP-CLI binary execution;
- self-update paths that bypass this repository's release process;
- a parallel authentication, approval, audit or quota implementation.

WPVibe-derived classes must be integrated into Native only. Cloud Core remains independently maintained and must not receive WPVibe-derived code.

## External assets and attribution

- Unsplash content must retain photographer/source credit wherever published; validate licensing and source attribution for every provider.
- Do not assume that an integration adapter exists merely because a builder is mentioned in marketing text. Record upstream source and prove compatibility with fixtures before declaring support.
