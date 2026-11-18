# Release documentation consistency audit — 18 November 2026

Scope: read-only review of the current PR #232 integration branch. This document records documentation drift found during release qualification. It does not authorize a merge, release, deployment, license change or production-site operation.

## Current verified source state

- PR #232 remains Draft/open/unmerged.
- Current branch head at the time of this audit: `51701f0ee645a96def5220f5b8168f9835acf16f`.
- Native exact-head CI: [44289273815](https://github.com/darcreid163/okean-ai-admin/actions/runs/44289273815), all required jobs successful.
- Cloud exact-head CI: [44289273073](https://github.com/darcreid163/okean-ai-admin/actions/runs/44289273073), all jobs successful.
- Native artifact SHA-256: `be87b1097f4c47109bf9ee1c2250ee263e805a16ea89af53b739ab56320cfdf3`.
- Cloud artifact SHA-256: `c9363773c1459b281387ed3d3396bdc3f83c4f6c993393028fb40ab82a77f3ab`.

These are workflow artifacts, not signed publisher releases. Any subsequent commit requires exact-head CI again.

## Confirmed documentation drift

1. `docs/RELEASE-CONTROL-2026-11-18.md` and `docs/PUBLIC-RELEASE-READINESS.md` still describe the older code SHA `fea7f8d2...` and older workflow runs as the latest evidence. Replace those statements with the exact-head evidence above before freezing the release docs.
2. `SECURITY.md` also cites `fea7f8d2...` as the exact-head result. It is historical evidence, not current-head evidence.
3. `docs/DATA-FLOW.md` and `docs/DATA-RETENTION.md` identify Native 2.1.17 / DB24 as their current technical baseline. The integrated candidate is Native 2.1.18 / DB25. Review these documents for schema/version-specific claims and update only those that are confirmed by source/tests.
4. `docs/CHATGPT-CONNECTION-CONTRACT.md` still describes Native 2.1.14 / Cloud 0.3.6 as its supported baseline. It may be intentionally retained as a compatibility contract; do not blindly rewrite historical protocol amendments. Add a current implementation mapping only after checking the exact OAuth/pairing behavior in source.
5. `docs/PUBLIC-RELEASE-READINESS.md` contains a pinned compatibility tuple written as WordPress 7.0.6, WooCommerce 4.3.3, Elementor 11.1.2. Those product versions appear assigned to the wrong products; inspect `.github/workflows/native-validate.yml` and report the exact matrix rather than copying this tuple.
6. `docs/PUBLIC-RELEASE-READINESS.md` says root LICENSE is absent and license selection remains pending. The repository contains a root `LICENSE` file (GNU GPL version 2). The README and THIRD-PARTY file state GPL-2.0-or-later. This is a material license-expression inconsistency. Do not alter the license or headers automatically; owner/legal review must choose and consistently document the intended expression.
7. `docs/REPOSITORY-BOUNDARIES.md` and `AGENTS.md` refer to Native 2.1.17 in their temporary-monorepo description. These may be historical boundary notes, but must not be mistaken for the current candidate version.
8. `BUILD-SIGNATURE.txt` explicitly says it is build metadata, not a cryptographic signature. Keep that distinction in public release copy; do not describe provenance metadata as a publisher signature.

## Manual gates that documentation cannot close

- Real ChatGPT token expiry/renewal/revoke with the external client, per [#92](https://github.com/darcreid163/okean-ai-admin/issues/92).
- Two independently owned central accounts performing manual negative tenant/site selection and revoke/replay acceptance.
- Disposable staging lifecycle: Read → Sandbox → Diff → Approval → Apply → Verify → Receipt/Proof → Rollback → Verify, plus paired backup/restore and identity verification.
- Final Native-only and Cloud-only artifacts from the approved exact commit, reproducibility/provenance/checksum checks and secret/dependency/license scans.
- Privacy Policy, Terms, data retention/deletion, subprocessors/hosting and support/security disclosures.
- Paid self-service checkout-to-license fulfillment remains blocked by [#203](https://github.com/darcreid163/okean-ai-admin/issues/203). A closed/free beta is a separate owner decision and requires manual license provisioning with no automatic debits.

## Safe next sequence

1. Correct the stale exact-head references in release control, public readiness and SECURITY documents.
2. Verify the workflow's actual pinned compatibility tuple and correct the documentation.
3. Resolve the GPL-2.0 versus GPL-2.0-or-later expression with the owner/legal reviewer; keep the existing license file unchanged until that decision.
4. Review DB25 references in data-flow/retention docs and update only proven version-specific statements.
5. Re-run exact-head Native and Cloud CI after documentation commits.
6. Continue external OAuth and disposable staging acceptance only with the owner-controlled client and staging environment.

No site, account, production database, credentials, payment gateway, license record or live configuration was accessed or modified during this audit.
