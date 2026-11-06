---
name: php-quality
description: PHP correctness, compatibility, coding standards, static analysis and secure error handling.
---
# PHP Quality
Identify supported PHP versions and repository tooling first. Follow WordPress coding standards. Run PHP lint, PHPCS and configured PHPStan/Psalm without weakening baselines. Prefer cohesive functions, explicit compatible types and predictable return shapes. Avoid hidden global state, broad catch-and-ignore, suppressed errors, unsafe unserialization and dynamic includes. Treat API, DB, filesystem and WP values as untrusted. Use prepared SQL, bounded queries, safe paths, allowlists and output escaping. Avoid unrelated refactors. Add regression tests. Report exact commands, versions, counts and unresolved warnings; unavailable tooling is BLOCKED, not PASS.
