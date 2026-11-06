---
name: phpunit-testing
description: PHPUnit unit, WordPress integration, WooCommerce, Cloud contract, billing, migration and regression tests.
---
# PHPUnit Testing
Inspect composer, PHPUnit version, bootstrap and conventions. Keep unit tests deterministic; integration tests use real WP/Woo APIs in disposable environments. Use synthetic fixtures only. Assert observable outcomes, permissions, side effects, error shapes and cleanup. Cover positive/negative, boundaries, partial success, retry, duplicate, concurrency and interrupted operations. Quota tests: 0, 1, limit-1, exact limit, over-limit, parallel reservations, duplicate idempotency key, failed item, partial batch, timeout and ledger failure. Mutation tests: approval, denial, conflict, verify failure and rollback. Never weaken assertions or skip failures to make CI green. Report exact commands and pass/fail/skip counts.
