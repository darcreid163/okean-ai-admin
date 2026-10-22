# Native historical upgrade and backup restore — issue #87

The previous smoke gate simulated DB23 using current code. The additional gate
installs Native 2.1.14 from immutable commit
`f7f02032669a38741dac4e29002ff246994f1a02` before loading Native 2.1.17.
No runtime or release version changes are included.

`tests/wordpress-native-upgrade-restore.sh` runs from the existing disposable
WordPress smoke job, after its Elementor and WooCommerce dependencies are active.
Shallow CI checkouts fetch only the required historical commit if absent. All
historical source and the temporary database dump stay outside the checkout.

The test seeds synthetic identities, a signed local license, manual token hash,
paired credential, OAuth client/code/access+refresh hashes, a revoked access token
and a pending operation under the old code. It then:

1. Exports a full pre-upgrade database backup and records the wp-config checksum.
2. Replaces Native files with the candidate and boots normal WordPress migration.
3. Compares persisted option values and complete retained rows, authenticates the
   old paired/OAuth credentials and checks revoked/unknown credentials stay denied.
4. Seeds candidate quota usage/reservation, boots again and checks it is retained.
5. Restores both historical files and the complete database; checks DB23, the exact
   pre-upgrade table list, original sentinel, credentials, rows and wp-config hash.
6. Re-upgrades to DB24 and verifies post-backup quota data did not leak into the
   restored database. Leaves the disposable site on the candidate for other gates.

Explicit opt-in, the dedicated `wp_test` / `wpai_upgrade_test` database name, a
loopback fixture home and an absent initial Native directory are mandatory. Dumps
and snapshots are private temporary files removed on exit. Never run this script
against a customer site: it resets the entire disposable database during restore.

## Observed local result

All five fixture phases (`seed`, `upgraded`, `repeat`, `restored`,
`upgraded-again`) passed on WordPress 6.8.3, PHP 8.3.35 and MySQL 8.0 in an isolated
Docker network. The database client used a shared local Unix socket. The existing
CI WordPress profiles must independently pass on the final commit; this local run
does not establish their results.

This verifies a paired **code+database backup restore**, not a files-only downgrade
of DB24, customer database compatibility, Cloud Core upgrade, production rollback,
or external ChatGPT OAuth. The reported live reconnect loop remains unresolved:
the installed server version and token/MCP HTTP failure have not been observed.
