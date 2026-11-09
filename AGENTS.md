# WP AI Admin — project rules

- This is a public integration repository containing Native and Cloud development source. Never commit API keys, OAuth secrets, signing keys, payment credentials, customer data, private backups or database exports.
- Native source: `okean-ai-admin.php` and `includes/`. Cloud candidate: `wp-ai-admin-cloud/`. Keep Native release archives separate from Cloud Core distribution; the production Cloud Core remains maintained in the private `wp-ai-admin-cloud-core` repository.
- Production sites `wpaiadmin.ru` and `okean.market` are out of scope unless the owner explicitly authorizes a specific change. No production writes, deploys, live billing/migrations, release tags, merges or visibility changes without the required explicit approval.
- Work in this order: READ → PLAN → isolated SANDBOX → DIFF → approval → APPLY → VERIFY → evidence/receipt → rollback plan. Use disposable test data; never mutate production data in tests.
- Preserve Approval Engine, mutation conflict guards, Verify, signed Receipt/Proof, Rollback, idempotency/recovery and OAuth site/owner isolation. Never weaken these controls just to make a test pass.
- Gateway Ledger is authoritative for quota. Preserve successful-operation accounting, atomic reservation/idempotency and explicit handling of uncertain outcomes.
- Use one issue and focused branch per change. Require exact-HEAD CI evidence and review before merge. Do not force-push or delete branches for unfinished PRs.
- Read `docs/OPEN-BETA-RELEASE-MISSION.md` only when a task requires the broader release scope, compatibility matrix or beta acceptance criteria. It is reference material, not an always-loaded instruction.
- Keep task context narrow: inspect only relevant files, summarize command output, avoid full-repository dumps and unnecessary agent delegation.
