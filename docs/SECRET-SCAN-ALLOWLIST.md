# Secret-scan allowlist — reviewed false positives

This file documents the narrowly scoped Gitleaks fingerprints in `.gitleaksignore`.
Entries are exact commit/file/rule/line fingerprints or exact current-tree fingerprints;
there are no directory-wide or rule-wide exclusions.

| Finding | Classification | Reason |
|---|---|---|
| `tests/wordpress-creation-claim-audit.php:8` | Synthetic test fixture | Fixed dummy value is deliberately used only to test that private workspace evidence does not leak into public projections. |
| `wp-ai-admin-cloud/tests/unsplash-http-auth.php:27` | Synthetic test fixture | Dummy Unsplash key is passed to a mocked HTTP transport to verify Authorization header construction; it is not a live service credential. |
| `wp-ai-admin-cloud/tests/suspension-token-concurrency.php:61` in two historical commits | Test-only false positive | The flagged line is the test's PASS summary output; the file creates only disposable fixture identities/tokens and does not contain a live credential at the reported line. |
| `wp-ai-cloud/VALIDATION.md:12` | Integrity checksum | SHA-256 digest of an installer ZIP; a public content checksum is not an authentication secret. |

The fingerprints are allowlisted individually rather than suppressing the generic-api-key rule.
If any fixture value is ever replaced with a real credential, remove its allowlist entry and rotate the credential immediately.

The all-ref scanner still scans every other finding in every fetched branch, tag and pull-request head.
No secret-scanning report is uploaded as an artifact; CI prints only rule, file, line, commit and fingerprint metadata, never matched values.
