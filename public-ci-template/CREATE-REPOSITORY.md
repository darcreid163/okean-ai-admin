# Create the public CI repository

Create exactly one empty repository under the same GitHub owner:

- Owner: `darcreid163`
- Repository name: `wp-ai-admin-release-ci`
- Visibility: **Public**
- Description: `Public release qualification for WP AI Admin release candidates`
- Initialize with README: **No**
- Add .gitignore: **No**
- Choose a license: **No**

Reason for an empty repository:
- the release snapshot will be populated from the private source-of-truth using an explicit allowlist;
- no unrelated starter commit/history is needed;
- software licensing is a separate product/legal decision and must not be chosen implicitly.

After creation, the public repository will receive:
- current release-candidate runtime snapshot only;
- release qualification tests;
- Runner Smoke / Validate / Cloud Core / Public Release Sandbox workflows;
- provenance/source manifest;
- no private Git history.

The public repository is CI evidence, not the development source of truth.
