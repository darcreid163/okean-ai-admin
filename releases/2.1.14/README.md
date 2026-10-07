# WP AI Admin 2.1.14 — Native consent return

Install on each managed WordPress site, including okean.market and wpaiadmin.ru. Cloud Core remains 0.3.6 on wpaiadmin.ru.

Fixes the actual administrator consent allow/deny POST returning to the fixed central callback. Native 2.1.13 issued a code but Chromium blocked its cross-origin 303 under form-action self. Native 2.1.14 commits a no-store handoff document with automatic meta navigation and a manual continuation link. Strict CSP/no-referrer and nonce/admin/HTTPS/descriptor checks remain; no credentials or installation identities are reset. Contract 1.2.

CI tested commit 11d0897927baa8472b72120c5f7efe05195e7ca4. Cloud run 37601928056 passed Chromium (6 exact-native-baseline failure checks, 22 candidate actual-native checks plus inherited central tests) and PHP 8.2/8.3/8.4 SQLite/MySQL. Native run 37601928036 passed syntax, concurrency/schema, and two real WordPress profiles (21 pairing and 34 admin-menu assertions each).

This ZIP is the exact native CI archive; all 85 file payloads match the tested source.
Bytes: 245157
SHA-256: 15f44ab4fbde4c96014e72549ceb3be0a6f00a1fd8b58a78df88a80357f09b3d

After update, start a new automatic pairing from the central cabinet; do not reuse the expired old consent URL. If automatic return does not occur, select Continue in cabinet. Then reauthorize ChatGPT for both registered sites and test reads by distinct registration IDs. Actual production pairing/ChatGPT acceptance is pending installation.
