# WP AI Admin 2.1.5 — QA
Date: 2026-10-01.

## Trigger and change
Installed 2.1.4 operation 1689 exposed Yoast normalization: WordPress dropped the obsolete display-metabox-tax-product_shipping_class flag. Authoritative verification rejected the requested unnormalized array; recovery preserved the differing live state rather than overwriting it.

2.1.5 runs sanitize_option before generating the sandbox diff, rejects non-idempotent sanitization, and rejects an existing baseline that cannot round-trip exactly. Strict after-write verification and stale/rollback conflict guards remain unchanged. Expected state is never learned from a post-write read.

On 2.1.4, explicit supported-baseline restoration 1692 and corrected normalized apply 1693 both verified successfully. Historical 1689 remains rollback_failed as evidence, not a successful test.

## CI evidence
Code commit: e5651bc176d13452d94f241486a6bad5e48279be.
Run: https://github.com/darcreid163/okean-ai-admin/actions/runs/36874199309
All four jobs SUCCESS:
- PHP 8.2, 8.3, 8.4: 70-file syntax check, option planning/normalization tests, Elementor single source/fragment tests, stock integer rounding, signed entitlement contract, ZIP integrity.
- MySQL batch mutex concurrency and OAuth schema upgrade/client retention.
The new test printed "Option normalization planning tests passed".

Published ZIP commit: 07fd812a122d63538607982388e3dca80a66f2d2.
ZIP blob: 2bbe43b4d393b54940ef5ec1dc6d80b33b93e6ba.
Installable archive: releases/2.1.5/wp-ai-admin-2.1.5.zip.

## Live installed baseline
2.1.4 manifest: 112 tools, self-test 78/78, security fuzz 220/220.
Draft Elementor create/fragment edits, stale hash/ambiguous fragment rejection, verified rollback and deletion of draft 37860 passed. Browser Visual QA completed for 1440/768/390 with no overflow/clipping/overlap; a small Woo notice dismissal target warned in each viewport. CSS correction applied as operation 1699; browser rerun remains outstanding.

Selective SEO changes were applied and verified through normal operations 1679–1702, including page/product descriptions, link corrections, archive H1, native WooCommerce news rendering, noindex for the old maintenance landing and one-time WP Rocket invalidation. Public HTML after cache clearing confirmed the key outcomes.

## Remaining release gates
2.1.5 is not installed on the site yet; run live option apply/rollback after installation.
Test Automation was disabled on 2.1.4, so current full regression matrix/mutation runner results are not claimed.
Nested option read redaction for IndexNow key and logo filesystem paths remains a separate hardening item; no values are included here.
Plain robots and XML sitemaps require a suitable bounded read capability or an independent source; HTML inspector rejection is not a site-failure finding.
