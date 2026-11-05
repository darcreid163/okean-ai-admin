# Historical Cloud update and backup restore — issue #89

The Cloud workflow now requires a real WordPress/MySQL upgrade gate before it
publishes the candidate artifact. It runs on the pinned WordPress profile and
latest WordPress with PHP 8.3, independently of the existing PHP/database/browser
matrix. Runtime files, release version, tariffs and production remain unchanged.

The old runtime comes from public immutable commit
`12c2852a973eba7988debc758e66159033abcbc8`. All 20 files must match
`RECOVERED-CLOUD-RUNTIME.sha256` before installation. No private repository access
or committed historical ZIP is needed. The new runtime is installed from the
candidate ZIP produced by the deterministic builder; its provenance must match
the current commit and ZIP hash, and its extracted 25 files pass the allowlist.

## Scenario and retained state

The disposable test installs actual Cloud 0.3.11 and enables its real WordPress
gateway. It seeds two synthetic WordPress owners/site registrations, an existing
legacy monthly Pro account, used quota, a successful sealed receipt and an
uncertain operation with a reserved unit. DCR and PKCE code exchange use actual
WordPress REST callbacks. Gateway keys are generated and wrapped by Settings,
not injected as constant fixture keys.

The five phases are `seed`, `upgraded`, `repeat`, `restored`, `upgraded-again`.
Every phase checks:

- The gateway configures itself on normal boot, stays on schema4 and keeps Batch
  disabled. Candidate updates do not require pressing Enable again.
- Wrapped key option bytes/autoload policy, Vault/signing key fingerprint,
  signing public identity and verification of a pre-upgrade signature survive.
- Complete retained account/balance/site/token/request/batch/audit/OAuth rows,
  gateway settings, table set and legacy monthly terms remain equivalent.
  Authentication rate counters are excluded because real requests increment them.
- Existing access tokens authenticate through the actual WordPress gateway;
  revoked and unknown tokens fail; both owners remain isolated.
- An allowed site's stored credential decrypts correctly with the original
  registration binding. Upstream requests use an in-process synthetic transport.
- Cached successful receipts decrypt without re-executing upstream. An uncertain
  replay remains blocked without executing or releasing/charging its reservation.
- Actual REST health, MCP initialize and token-scoped site listing succeed.

The repeat and re-upgrade phases rotate the retained refresh token via the token
REST handler, preserve selected sites, reject its reuse and invalidate the rotated
grant on replay. They deliberately alter only synthetic post-backup data; restoring
the complete pre-upgrade database must recover the original credential rows,
balance and sentinel. The wp-config checksum must remain unchanged.

Finally, the script removes Cloud files and restores the pristine pre-test
database so generated accounts, keys, licenses and quota fixtures cannot leak into
other suites. Disposable opt-in, dedicated DB name, loopback home and an initially
absent Cloud plugin directory guard every destructive reset. Dumps and credential
snapshots are mode-private temporary files, removed on exit and never uploaded.

## Evidence and limits

All five phases passed locally on actual WordPress 6.8.3 / PHP 8.3.35 / MySQL8.0
with a shared local Unix socket and an isolated Docker network. Both historical
20-file verification and candidate 25-file validation passed. Hosted final-head CI
must separately establish the pinned/latest WordPress results.

The test dispatches real WordPress REST handlers with an explicit fixture TLS
context. WordPress users, persistence, OAuth, key wrapping and gateway logic are
real; browser consent, public HTTPS, deployment proxy/header forwarding, external
upstream WordPress sites and ChatGPT are not exercised. This does not close #90.

This is a **paired code+database backup restore** on disposable data. It does not
prove a files-only downgrade, preservation of activity after the backup, customer
database compatibility or safe production recovery. Restoring a backup also rolls
back nonce/refresh/revocation history; live recovery needs a separate operational
plan. No production deployment, plan assignment, key rotation or account reset is
authorized by passing this gate.
