# Release gate: PUBLICATION BLOCKED

Native candidate: **2.1.17**, schema **24**, archived Native ZIP SHA-256 `4bf534f3a30344fa94cf599d526477887ed89175e494d32b6b35e5a05f1cc1ac`.

Cloud Core private candidate: **0.3.11**, schema **4**, archived Cloud ZIP SHA-256 `b83ebc098b5ef3e22b7f083df8d26849c233839b226d516725c027984de82793`.

Installed MCP read-only manifests previously reported Native 2.1.17 / 116 tools on both wpaiadmin.ru and okean.market; installed byte identity **not verified**. Production is NEVER a CI fixture.

## Do not merge/tag/deploy before
- [ ] Integration branch current-HEAD public GitHub Actions green on PHP 8.2/8.3/8.4 and real disposable WordPress.
- [ ] Public boundary gate confirms no duplicate Cloud trees, committed ZIPs, credentials or private runtime in candidate.
- [ ] Private Cloud Core schema 3→4 migration, OAuth site isolation, refresh/revoke, shared Ledger, atomic Batch, unknown-outcome reconciliation pass on isolated databases/staging.
- [ ] Scoped Elementor/Gutenberg/Woo/Media/Menu lifecycle and signed Proof/Receipt/rollback acceptance pass.
- [ ] Staging upgrade/rollback and OAuth/identity retention pass; Beget installed-file SHA-256 read-back captured.
- [ ] Native PR #21 and private Cloud PR #12 qualified independently, with recorded commit SHAs and approvals.
- [ ] Separate human approval for production rollout and payment/tariff activation.

No automatic money debits. Never merge obsolete alternate Cloud Billing PR #9 or count telemetry as quota.
