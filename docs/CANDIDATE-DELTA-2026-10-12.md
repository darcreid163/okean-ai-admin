# Release candidate reconciliation — completed in private integration

Starting integration: `59ac0c1c6e8cdef7403577bb53301073b97f34bc`, Native 2.1.14 / Cloud 0.3.6. Compared every path against private qualification candidate `3d5ecab2fceb233a3ab7a736e60795bde0518ae9`.

134 files were byte-identical; 25 common paths differed; 30 candidate-only files were imported; all 34 integration-only files were retained. Source ZIP commit: `64bf281411163a4b1f9de1bee0722c8b89308b07`.

Integrated Native 2.1.15 / Cloud Core 0.3.7, then fixed reproduced native lease/recovery races and test fixture defects. Native build is `2.1.15-release-reconciled-20261012`. Current immutable candidate archives and SHA-256 provenance are under `releases/2.1.15` and `releases/wp-ai-cloud-0.3.7` in this private branch.

See [Work qualification report](WORK-QUALIFICATION-2026-10-12.md) and `reports/work-2026-10-12/results.json` for observed PASS/FAIL history, final results and SKIP gates. Work automated qualification does not close Chromium or external ChatGPT/two-owner live acceptance. `main` and production sites were not changed; PR #35 remains draft.
