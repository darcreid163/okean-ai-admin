# Release repository boundaries

Status: internal policy (2026-10-12)

## Authoritative development repository
- `darcreid163/okean-ai-admin` (PRIVATE), branch `main`, remains the source of truth for all production source, release engineering and product decisions.
- Implement, review, and merge feature/security changes here first.
- Never sync private repository history, entire source trees, credentials, customer information, production database exports, or private documentation into public CI.

## Public CI mirror
- `darcreid163/wp-ai-admin-release-ci` is **not** the canonical development repository.
- Intended final role: public test harness and synthetic test fixtures only, requiring no production credentials.
- Public GitHub Actions cannot freely access private repository source. Do not solve this by adding persistent private credentials or unreviewed artifacts.
- To test proprietary code without publishing it, prefer a private GitHub Actions execution path (billing or self-hosted), or a runner under our control. Public CI is appropriate only for tests whose input can be released publicly.
- A full historical copy was already imported into the public repository. Removing files from the latest commit or force-pushing a clean history DOES NOT guarantee removal from GitHub caches, forks, clones, pull requests, action logs, or existing snapshots.
- Until public exposure is assessed and the repo rebuilt appropriately, do not replicate new private commits into the public repo.

## Release gating
1. Development and sensitive integration tests in private main/PRs.
2. Explicit public-safety review for any files transferred to the test harness (license, credentials, URLs, internal architecture, customer data, provenance).
3. Release candidate packaged in private, then provenance and sha256 recorded.
4. Disposable WordPress / Cloud Core installation, tenant isolation, OAuth, quota, approval/verify/rollback acceptance.
5. Production deployment only after separate live acceptance and approval.

## Immediate incident containment
- Set public mirror to private temporarily in GitHub Settings > General > Danger Zone > Change repository visibility.
- Inventory and rotate any real secrets ever committed or contained in public artifacts.
- Review Actions logs, downloadable artifacts, releases, branches, PR diffs, and commit history.
- Prefer a freshly created public repository containing only a vetted tiny CI harness rather than trying to sanitize the existing historical mirror.
