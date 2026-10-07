# Native 2.1.14 consent return validation — 2026-10-07

The user reached okean.market's native consent after installing native 2.1.13 and Core 0.3.6, but clicking allow did not return to the central cabinet. Live public capabilities confirmed native 2.1.13 on both sites; authenticated central health confirmed Core 0.3.6. Native authorize() still performed a cross-origin 303 after a self-only form.

Change: after valid POST allow or deny, commit a no-store HTML handoff with automatic meta navigation and a manual link to the fixed callback. Keep administrator/HTTPS/nonce/request checks, strict CSP, no-referrer, single-use code handling and identity preservation. Core runtime remains 0.3.6. No migration or key rotation.

Local checks: PHP syntax for 111 files and 42 pairing/security assertions passed. Initial browser CI loaded the exact native 2.1.13 archive handler and reproduced the real POST code issuance followed by CSP-blocked callback (6 checks); the candidate actual native authorize() handler passed 20 allow/deny/manual/negative checks. Final test-only change additionally checks committed 200/no-Location and no-store/no-referrer/strict-CSP headers.

The native browser test exercises actual PHP handler output and real Chromium, with synthetic WordPress identity, nonce, license/options and backchannel. It intercepts the fixed HTTPS central callback, never contacts or authorizes a production account. Existing real WordPress pairing tests cover nonce/code/exchange, persistent identity and credential/OAuth retention; actual user ChatGPT/two-site acceptance remains separate.

The previous central browser test's simplified native consent fixture did not enforce the production native CSP; it must not be treated as native handler coverage. This new gate covers that missed boundary.

## Final CI and archive

Tested commit: `11d0897927baa8472b72120c5f7efe05195e7ca4`. Cloud push [37601928056](https://github.com/darcreid163/okean-ai-admin/actions/runs/37601928056) passed all PHP 8.2/8.3/8.4 SQLite/MySQL gates. Browser job 112728110737 passed 7 exact Core 0.3.5 reproduction checks, 18 central candidate checks, 6 exact native 2.1.13 reproduction checks and **22 candidate actual native handler checks**, including committed 200/no-Location/no-store/no-referrer/self-only CSP.

Native push [37601928036](https://github.com/darcreid163/okean-ai-admin/actions/runs/37601928036) passed every job including publication. Both real WordPress profiles (WP 7.0.6 and 7.1.3 / PHP 8.3.35) passed 21 pairing and 34 menu checks, persistent credentials/IDs, upgrade bootstrap and lifecycle regressions. The deliberate historical menu baseline failure is expected negative coverage.

ZIP: 85 payload files match the tested source, 245157 bytes; SHA-256 `15f44ab4fbde4c96014e72549ceb3be0a6f00a1fd8b58a78df88a80357f09b3d`. Live before update still has one registered site; production acceptance remains pending.
