---
name: github-actions-ci
description: Least-privilege GitHub Actions for lint, tests, compatibility, security, packaging and beta artifacts.
---
# GitHub Actions CI
Set workflow/job permissions explicitly; default contents: read. Never use privileged pull_request_target to execute untrusted PR code. Pin third-party actions to reviewed immutable SHAs where practical. Do not expose secrets to forks/untrusted PRs; use synthetic credentials. Avoid shell interpolation of untrusted event values. Pipeline: fast lint/PHPCS/static/unit/schema/secret checks; integration WP+MariaDB+PHP/Woo matrix; E2E Native artifact plus separately provisioned Cloud test service; security/tenant/idempotency/filesystem/rollback/migration tests; separate Native/Cloud packaging and ZIP inspection; scheduled regression. Do not claim CI passed until exact candidate SHA completes required jobs. No production deployment, release tags or live billing without owner approval.
