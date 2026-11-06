---
name: database-migrations
description: Safe repeatable Native and Cloud schema/data migrations with upgrade, rollback and recovery tests.
---
# Database Migrations
Identify data owner (Native site plugin vs Cloud Core); do not cross boundaries. Inspect schema, registry, version markers and prior supported versions. Prefer additive backward-compatible migrations. Use transactions where supported; otherwise checkpoints, idempotent steps and recovery markers. Test repeat execution and interrupted recovery. Validate row counts, constraints, indexes and integrity. Never silently coerce/discard/duplicate customer data. Test clean install, every declared upgrade baseline, rerun, interruption, rollback/restore and mixed-version behavior using synthetic disposable DB snapshots only. Document order, locks, downtime, backup and rollback limitations. Never run migration tests against production.
