# Commercial main-merge gate — Native 2.1.17 (2026-10-21)

This is a source recovery and qualification PR, **not authorization to deploy or merge**.

## Verified
- Original Library archive: `wp-ai-admin-2.1.17.zip`, SHA-256 `4bf534f3a30344fa94cf599d526477887ed89175e494d32b6b35e5a05f1cc1ac`, 86/86 archived paths; immutable recovery commit `5b34f332a8debba048061a8496c95ee722bc3452`.
- 66/66 archived PHP files pass local PHP 8.4.24 syntax lint.
- PHP 8.2, 8.3, 8.4, real WordPress/Elementor smoke, actual disposable DB schema 23 -> 24 fixture with retained OAuth client, MCP credential, site/installation IDs, pending operation, and repeat upgrade: successful GitHub run [39572823976](https://github.com/darcreid163/wp-ai-admin/actions/runs/39572823976).
- ZIP build is **now gated by both PHP and real WordPress smoke**, not just lint; release stays tag-gated.
- Remote live read-only manifests confirm **both** registered sites `wpaiadmin.ru` and `okean.market` are Native 2.1.17, build `2.1.17-usage-batch-v1-20261019`. 116 tools are advertised per site. Separate persistent site and installation identities were returned.
- Historical main-only CLI, QA and staged unregistered Elementor documents remain in Git but are **not implicitly original archive members**. Exclude unregistered full-document Elementor writer from installable distribution.

## Cannot yet mark complete
- [ ] Run the saved read-only Beget file-hash verification kit against **installed** Native plugin files on each site; matching version/build does not prove SHA-256 parity.
- [ ] Execute independent disposable staging upgrade (from previously installed 2.1.15/2.1.17) **and actual rollback**, with DB backups and OAuth token/client preservation; an isolated fresh WordPress CI test does not prove the customer's upgrade/rollback.
- [ ] Test signed Proof/Receipt and API permissions under real production-equivalent OAuth and client isolation. Keep experimental full Elementor document writes unregistered.
- [ ] Obtain the maintainer's go/no-go after reviewing combined Cloud Core gate.

## Safe merge sequence
1. Check exact runtime file hashes/allowlisted release package and complete staging upgrade/rollback; save logs without credentials.
2. Require a green HEAD CI and ensure no new unreviewed runtime modifications or regressions relative to original source.
3. Qualify private Cloud Core 0.3.11 recovery/migration/OAuth/usage **independently** before enabling connected tariff behavior.
4. Merge this PR only when every checkbox is closed. Production upgrade, tag and rollout require separate explicit approval.

No live site, subscription, balance or `main` change was made as part of this qualification.
