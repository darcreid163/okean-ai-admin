# CI acceptance lab (manual GitHub Actions)

`Native acceptance lab` is an on-demand workflow for focused real-WordPress
checks. It runs on a disposable `localhost` site with MySQL 8 and the Native
plugin activated, and it never publishes content or touches production.

Workflow: `.github/workflows/native-acceptance-lab.yml`
Runner: `tests/wordpress-ci-lab.sh`

## When to use it

- a single suite needs a real WordPress + MySQL run without waiting for the full
  matrix (for example the classic-theme suite);
- a regression must be reproduced on a pinned WordPress/Elementor/WooCommerce
  combination;
- the Woodmart lifecycle needs to run against the real hash-pinned Woodmart MCP
  source, which is not committed to this repository.

It does **not** replace the full `Native validation` / `Cloud Core validation`
matrix, the production-like stand, a real external ChatGPT OAuth run, or the
release artifact freeze.

## Run it

```bash
gh workflow run native-acceptance-lab.yml --ref main \
  -f tests=tests/wordpress-classic-theme.php \
  -f theme=twentytwentyone \
  -f run_woodmart=no
```

Or dispatch it from the Actions tab. Inputs:

| Input | Meaning |
|---|---|
| `tests` | comma-separated paths under `tests/wordpress-*.php` |
| `full_suite` | run `tests/wordpress-native-smoke.sh` instead of focused tests |
| `theme` | install and activate `twentytwentyone` or leave the default theme |
| `image_editor` | `WP_Image_Editor_Imagick` or `WP_Image_Editor_GD` |
| `wp`, `elementor`, `woo` | `latest` or a pinned version |
| `run_woodmart` | provision the Woodmart source from the repository secret |

## Woodmart source secret

The Woodmart MCP source is deliberately **not** committed. To run
`tests/wordpress-woodmart.php`:

1. Use the owner-provided `woodmart-mcp-1.0.1.zip` with SHA-256
   `d9d8a8ac07cc189488cb30356acfec1d7b885312faab741ed40fa0bf6c640615`.
2. Base64-encode the ZIP locally and store it as the repository secret
   `WOODMART_MCP_ZIP_BASE64`. Do not commit the ZIP, the base64 text or any
   derived file.
3. Dispatch the lab with `tests=tests/wordpress-woodmart.php` and
   `run_woodmart=yes`.

The workflow verifies the pinned SHA-256 before unzipping, uses a temporary
directory, removes the ZIP after extraction and never prints the secret. The
secret is only referenced by a `workflow_dispatch` job with
`permissions: contents: read`; it is not available to pull-request code.

## Boundaries

- No repository write permissions, no `pull_request_target`, no production
  credentials, no billing or tariff changes.
- The lab is disposable evidence, not release approval. Record the run URL and
  the exact commit when citing it.
- Real proxy/TLS/cache behaviour, live ChatGPT token expiry (#92), two-owner
  acceptance and a private backup/restore still require the stand and owner
  action.
