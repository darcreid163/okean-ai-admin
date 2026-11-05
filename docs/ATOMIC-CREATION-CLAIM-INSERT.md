# Artifact creation admission must be a true single-winner SQL insert

## Reproduced CI failure

During the closed-beta readiness workflow for docs PR #118, the latest
WordPress/Imagick smoke profile reported **two successful** simultaneous
`menu.create_sandbox` workers and four duplicate denials for one nominally
identical request. Earlier six-worker passes were insufficient to exclude
the race. This is a release-blocking runtime defect, not a documentation or
caching problem. See Native workflow 39934540704; pinned WordPress passed
that run and latest failed.

## Fixed contract

`OAI_DB::insert_creation_claim(name, record)` now performs a strict
`INSERT` into the **uniquely indexed** WordPress `option_name` key. It
returns true only if the MySQL driver confirms **one newly inserted row**.
A duplicate key returns false only after a fresh authoritative SELECT proves
the option exists. Storage/transaction errors fail closed. Expected
duplicate-key SQL errors are suppressed from public WordPress error logs,
option caches are invalidated, and autoload is disabled. The method refuses
unexpected claim-key prefixes and requires an autocommit connection.

Creators for workspace, menu, menu item, WooCommerce draft and Elementor
page all call the shared helper **before** their first persistent artifact
write. Existing caller-specific 409/changed-payload semantics and privacy
boundaries remain. Claims are not automatically deleted on timeout, failed
creation, age, or lost HTTP reply. Media has its own journal and claim
ownership validation; it is not silently migrated by this change.

The disposable WordPress/MySQL acceptance directly confirms a second strict
INSERT cannot claim the same option key, then exercises **three rounds of
six independent processes** racing to create one menu per round. Every round
must yield exactly one successful menu plus one operation, with five denied
duplicate requests. The prior creation suites for other artifact families,
Approve/Verify/Receipt/Rollback and Cloud quota/tenant CI continue running.

## Limits

A green CI run is not a production-equivalent demonstration of Beget's
MySQL driver/isolation and object-cache behavior. Before enabling beta
creation writes, repeat multi-process tests on the disposable staging
configuration, verify InnoDB UNIQUE option_name and autocommit and correlate
historical/unknown claims with artifacts/operations independently. No
production changes, plan/account migrations or automatic reconciliation are
part of this patch. Parent #103 and release #40 remain open.
