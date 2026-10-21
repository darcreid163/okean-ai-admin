# Third-party code and attribution

Owner and copyright holder of this plugin: **Андриянов Н.Г.**
This repository is distributed under **GPL-2.0-or-later** (see `LICENSE`).

This file is the authoritative list of third-party code included in, or derived into, this
repository. It must be updated in the same pull request that adds or changes such code.

## Current status

As of **2026-10-20** this repository ships **no third-party source code**. Every file in
`includes/`, `tests/`, `public-client/` and `starter-themes/` is original work of the owner,
except WordPress APIs, which are called but not copied.

## Reviewed upstream projects

| Project | License | Upstream | Reviewed | Status |
|---|---|---|---|---|
| WPVibe (WordPress MCP plugin, wordpress.org slug `vibe-ai`) | GPL-2.0-or-later | https://github.com/awesomemotive/wpvibe-ai-mcp | `1.20.5`, commit `62bc77c` | **reference only, nothing copied** |

WPVibe is published by Awesome Motive under GPL-2.0-or-later, the same license as this
plugin, so code may be ported. No WPVibe file has been copied into this repository yet.
The `includes/class-oai-elementor-schema-save.php` adapter is original work and copies no
WPVibe source.

## Obligations when code is ported

1. Keep the upstream copyright notice and the GPL-2.0-or-later header in every ported file.
2. Add a row to the table above with: upstream file path, upstream commit, and the local
   path that contains the port.
3. State clearly what was modified. A port that changes behaviour is a derivative work and
   must say so in the file header.
4. Do not relicense, sublicense or move ported code into a non-GPL component. The private
   gateway repository must not contain WPVibe-derived code.
5. Ship the complete corresponding source of this plugin to anyone who receives a build.
   A compiled or obfuscated distribution of GPL code is not permitted.
6. Keep `LICENSE` unchanged and never remove the plugin header license fields.
7. Record the licence review in the pull request that introduces the port, and have the
   owner approve that PR before merge.

## Planned ports, if and when approved

Candidates are tracked in the public issue list; at the time of writing only read-only
components are considered:

- `load_skill` progressive instructions (server-side, not in this repository);
- media search proxying (server-side, not in this repository);
- WP-CLI command coverage and builder adapters — **not started**, requires an explicit
  licensing review per file.

## Deliberately excluded

The following WPVibe capabilities are not planned for porting, because they conflict with
the lifecycle of this plugin (`Read → Sandbox → Human approval → Apply → Verify → Receipt →
Rollback`, `direct_live_writes = false`):

- a generic REST passthrough that performs arbitrary `POST`/`PUT`/`DELETE`;
- a code-snippet executor that runs arbitrary PHP;
- a third-party self-update mechanism that bypasses the release process.

## Non-code attributions

- Unsplash images are provided by photographers on Unsplash. When a photo is offered by the
  hosted gateway, the photographer credit returned with the result must be shown wherever
  the image is published.

## Change procedure

Any pull request that adds third-party code must tick the attribution checkbox in the pull
request template and update this file. A pull request that carries unlisted third-party
code is rejected by review, independently of whether tests pass.
