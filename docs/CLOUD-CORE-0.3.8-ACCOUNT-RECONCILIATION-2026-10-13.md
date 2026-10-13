# Cloud Core 0.3.8 account dashboard — reconciliation status (2026-10-13)

## Change set reported from Work
Prepared in a separate Work workspace `wp-ai-admin-release-ci`:
- Account overview dashboard
- Connected websites list with disconnect control
- User action history
- Plan/tariff display
- ChatGPT connection section
- Desktop and mobile previews (test data)

Reported Work validation: dashboard behavior, cross-account isolation, OAuth and website connection. **These are reported results, not independently rerun in this repository checkout.**

## Verified in GitHub connector session
- Main branch Cloud Core plugin header: **0.3.6**.
- Draft PR #35 integration branch: Cloud Core **0.3.7**, Native **2.1.15**.
- Draft PR #37 has the QA38 Native changes; report lists remaining skips and external acceptance gates.
- Cloud Core 0.3.8 source/archive cannot be accessed from this session at `/workspace/wp-ai-admin-release-ci/cloud-dist-account-candidate/wp-ai-admin-cloud-0.3.8.zip`; the path belongs to a separate Work container.
- The archive was not present in the current container and was not found in available conversation/Library file search.
- No 0.3.8 PHP lint, runtime/WordPress, security or upgrade checks were independently executed in this session.
- No 0.3.8 binary was built or deployed. **Do not advertise a 0.3.8 release from the current main tree.**

## Next release gate
1. Obtain the original Cloud Core 0.3.8 ZIP AND corresponding source diff/commit from Work.
2. Audit source provenance, archive entrypoints and upgrade compatibility with 0.3.7.
3. Re-run OAuth/tenant isolation/site disconnect/WordPress upgrade checks (including failed flows).
4. Integrate with latest Native QA38 without overwriting newer changes and close external acceptance gaps.
5. Build deterministic ZIP, record SHA-256 and verified source SHA; only then merge code and publish release artifact.

See [tracking issue #38](https://github.com/darcreid163/okean-ai-admin/issues/38), [PR #35](https://github.com/darcreid163/okean-ai-admin/pull/35), [PR #37](https://github.com/darcreid163/okean-ai-admin/pull/37).
