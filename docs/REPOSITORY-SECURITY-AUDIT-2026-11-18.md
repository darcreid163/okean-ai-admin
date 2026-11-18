# Repository security and hygiene audit — 2026-11-18

## Executive status

**Repository:** `darcreid163/okean-ai-admin`  
**Visibility confirmed from GitHub API:** PUBLIC  
**Candidate branch:** `release/native-2.1.18-integration-20261118`  
**Candidate source at audit start:** `8b5e3c944f2e5fb56b1d7bbb0494f025a8742000`  
**Disposition:** do not delete/recreate the repository as a substitute for secret remediation. Do not claim the repository is proven secret-free.

The public repository contains both Native and Cloud Core source. Cloud implementation details are therefore already publicly disclosed. This is a source/IP exposure decision, not by itself evidence that production credentials were leaked.

## What was checked in this pass

1. Queried repository metadata. GitHub reports visibility `public`.
2. Inspected root contents, Native/Cloud layout, `.gitignore`, security policy, repository-boundary policy, data-retention policy, README, release control and connection-contract documents.
3. Inspected the candidate PR #232 and branch inventory. The repository has approximately 185 branches across two pages. Branches were not mass-deleted because their unique work and PR reachability have not been individually reconciled.
4. Ran indexed default-branch code searches for selected common credential indicators, including private-key headers, GitHub token prefixes, Slack bot-token prefix, database-password names, OAuth client-secret/API-key names, `wp-config.php` and bearer-header strings. Those searches returned no indexed matches.
5. Confirmed `.gitignore` already excluded `.env`, key/certificate bundles, SQL/database files, backups, logs, ZIP/tar archives, vendor, node_modules and generated output. This pass expanded local ignore rules for additional key stores, Terraform state, common credential files and WordPress config.
6. Reviewed the published security model and candidate code documentation: OAuth Authorization Code + PKCE S256, per-owner/per-site/per-scope authorization, distinct Native pairing and ChatGPT OAuth credentials, hashed/sealed credential storage, approval-gated sandbox lifecycle, fail-closed validation, revocation and audit evidence are documented and have automated regression coverage.

## Important limitations

- GitHub code-search excerpts are not a full forensic scan of every blob and every historical commit. This pass did not download and scan all 185 branch histories locally.
- The GitHub connector does not expose repository Security/Advanced Security settings or secret-scanning alert inventory. The enabled state of user secret-scanning alerts and push protection is therefore **not verified**.
- GitHub Actions logs, expired/current artifacts, release assets, issue/PR comments, forks, local clones and third-party mirrors were not comprehensively scanned.
- No secret was confirmed by the limited searches, but this is not proof that no secret exists.
- No credentials were rotated or revoked in this audit. If any real credential is discovered in history/logs/artifacts, revoke or rotate it first, then consider history cleanup.
- Historical docs and branches are evidence, not current product instructions. Old dated reports must not override the canonical release tracker and current release-control document.

## Public exposure and repository deletion

Changing a public repository to private does not make public forks private and does not erase local clones or copies already downloaded. GitHub detaches public forks into a separate public network when a repository becomes private. Deleting a public repository can leave a public fork as the upstream repository. Cached commit views and PR references may also preserve access to sensitive historical content.

Deleting this repository and creating a new private repository would make the new repository private, but would not recall old public copies. A new repository also starts with a separate history and requires careful transfer of issues, PRs, workflows, secrets, releases, collaborators and evidence. Do not delete the current repository as a cleanup shortcut.

## Current project protections (documented and CI-tested, not a guarantee against all attacks)

- OAuth Authorization Code + PKCE S256; exact redirect and resource/audience checks.
- Site-pairing credential is separate from ChatGPT OAuth credentials.
- Sensitive credentials are hashed or sealed at rest where applicable; raw secrets must not enter logs.
- Owner, site selection, scopes, audience, expiry and revocation are checked on tool calls.
- Cross-tenant and unselected-site denial tests exist; denied cross-tenant calls must not consume billable quota.
- Direct live writes are disabled by default; mutations use Sandbox → Approval → Apply → Verify/Receipt → Rollback.
- Idempotency and single-winner admission guards prevent blind duplicate writes.
- Upgrade/restore tests preserve identity and key material; key-preservation export does not rotate keys.
- CI checks PHP 8.2/8.3/8.4, WordPress compatibility, OAuth/tenant isolation, database migrations, browser pairing and package provenance.

These controls protect application behavior. They do not protect the confidentiality of source code in a public repository.

## Required next actions

1. In GitHub repository Settings → Security & analysis, verify/enable secret scanning alerts for users and push protection where available; review every alert, including closed/resolved alerts.
2. Run a complete history scan with a dedicated scanner (for example, Gitleaks) against all refs and the full Git history, not only the current default branch. Scan candidate ZIPs, Cloud artifacts, workflow logs and release assets separately.
3. If a credential is found, revoke/rotate it immediately at its issuer. Do not paste the value into an issue or chat. Only then decide whether history rewrite and GitHub Support cache/PR-reference removal are warranted.
4. Decide whether Cloud Core source is intended to remain public. If not, move its authoritative source to the existing private Cloud repository and remove it from future public Native release artifacts; changing visibility alone does not retract existing public copies.
5. Reconcile the 185 branches against PR state and unique commits. Delete only branches proven merged/superseded with no unique unrecovered work; preserve audit evidence and the current Draft PR #232.
6. Keep PR #232 Draft/unmerged until owner-run two-account OAuth/expiry/isolation and staging acceptance are complete.

## Safe cleanup performed

- Expanded `.gitignore` patterns on the candidate branch to prevent accidental addition of common credential files, key stores, database backups, Terraform state and WordPress configuration.
- Refreshed candidate/release documentation on the candidate branch and added a versioned connection-contract addendum.
- No Git history rewrite, branch deletion, issue deletion, PR closure, repository deletion, visibility change, deployment, or credential rotation was performed.
