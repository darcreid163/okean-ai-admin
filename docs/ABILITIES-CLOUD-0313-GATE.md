# Cloud Abilities integration gate — 0.3.13-dev candidate

**Source status, 22 October 2026:** integrated in current `main`; not a production
release or deployment claim. `Core::writes()` uses `AbilitiesPolicy` to classify
`ability.list` and `ability.get` as metadata reads. Native 2.1.17 advertises these
tools; arbitrary Ability execution remains outside this discovery contract.

The recovered 0.3.11 SHA-256 manifest remains immutable. The candidate integrity
gate explicitly permits changes to historical Core, Gateway and bootstrap, while
17 other historical files remain SHA-identical. New candidate modules are not
members of the historical archive and must be reviewed separately.

## Automated acceptance present in main

- `abilities-read-contract.php` checks the real `Core::writes()` classification
  together with the isolated metadata policy. `ability.run`, `ability.execute`
  and unlisted names are not granted read access.
- `abilities-cloud-acceptance.php` exercises Core and public Gateway JSON-RPC with
  a synthetic transport: selected-site/owner/read-scope isolation, one-unit
  charging, cached retry, uncertain reservation/no redispatch, evidence-required
  reconciliation, exactly-once successful settlement and zero-charge failure.
- `abilities-transport-boundary.php` checks malformed JSON-RPC/tool results and
  rejection of insecure/local upstream addresses. It does not perform a live
  public HTTPS call.
- Cloud CI runs database acceptance on SQLite and MySQL under PHP 8.2/8.3/8.4.
  Exact audited source SHA `1fb3f98c1ec87013990e4f35c0c02e8e39c65556` passed
  [Cloud run 39735258143](https://github.com/darcreid163/okean-ai-admin/actions/runs/39735258143).

## Remaining release acceptance

Use a production-equivalent disposable staging environment to validate the full
HTTPS path, real Native Abilities metadata, fresh external OAuth consent,
selected/unselected sites, tenant A/B denial and revocation. Preserve identities,
credentials, quota state, approval/verify/receipt/rollback semantics and explicit
site selection. Automated fake-transport acceptance is not proof of these live
flows. No arbitrary Ability execution, quota reset, paid migration or production
installation is authorized by this document.
