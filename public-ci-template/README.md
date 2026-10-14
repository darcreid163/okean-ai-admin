# WP AI Admin Public Release CI

This directory is the source template for the separate public release-qualification repository.

Purpose:
- run GitHub-hosted Actions without consuming private-repository Actions minutes;
- test only an explicitly exported release-candidate snapshot;
- keep the primary commercial development repository private;
- provide independently visible CI evidence for the exact candidate commit/artifacts.

## Security boundary

The public CI repository must contain only an allowlisted release snapshot:
- native runtime files that are already intended to ship in the WordPress plugin ZIP;
- Cloud Core runtime files that are intended to ship centrally;
- release qualification tests and CI scripts;
- public release documentation required for validation.

It must NOT contain:
- the private repository .git history;
- issues, pull requests or internal project notes;
- credentials, tokens, private keys or environment files;
- production database dumps or customer data;
- non-release prototypes or abandoned historical source trees;
- private deployment configuration.

The public repository is qualification infrastructure, not the development source of truth.

## Provenance contract

Every exported snapshot must record:
- private source commit SHA;
- native version/build ID;
- Cloud Core version;
- SHA-256 of generated native ZIP;
- SHA-256 of generated Cloud ZIP.

The public CI run must fail if package metadata and provenance disagree.

## Publication rule

Never export directly from an unreviewed working tree. Export only from the dedicated public-release candidate branch and run the private release allowlist/secret scan before publishing the snapshot.
