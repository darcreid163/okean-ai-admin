# Local release qualification evidence — 2026-10-12

Scope: independent local validation from the chat's isolated Linux container, not GitHub Actions and not live WordPress.

## Executed
- Python 3 local syntax compilation of `scripts/audit-public-ci-export.py` and `tests/test_public_ci_export.py`: PASS.
- `python -m unittest discover -s tests -p test_public_ci_export.py -v`: 11 PASS, 0 FAIL. Tests cover positive generic JSON/workflow and negative PHP, ZIP, private key, endpoint, credential assignment, unauthorized paths, missing input and symlinks.
- This was an exact manually transferred working copy of the scripts from branch `codex/public-ci-minimal-export-guard`, not an authenticated checkout. This validates the observed code but is not proof of repository-wide green CI.

## Source-of-truth verification
- Private `main` currently advertises Native `2.1.14` and Cloud Core `0.3.6`.
- Both build metadata documents agree on native version `2.1.14` and build ID `2.1.14-native-consent-return-20261007`.
- Public mirror (now private) contained newer `2.1.15` / `0.3.7` qualification candidate. These are not yet promoted to the authoritative private main.
- Consequently, DO NOT claim public candidate 2.1.15 / 0.3.7 is the production version.

## Not yet validated
- Full PHP syntax across the complete private source tree (source not mounted to the local container).
- Independent PHP, WordPress, MySQL and browser suites; CI failures have missing job step/log evidence and are not proof of application test failures.
- Binary/ZIP build reproducibility and live gateway mutation/verify/receipt/rollback lifecycle.
- Absence of confidential data from historical public copies.

## Next gates
1. Confirm PR #34 scanner unit tests; merge only after code review.
2. Review and selectively port release candidate changes from the closed mirror to a PRIVATE integration branch; do not silently replace private main.
3. Obtain a clean private source checkout in an isolated runner for comprehensive PHP/WordPress testing.
4. Only deploy once release provenance, OAuth tenant isolation, state retention and rollback tests are green.
