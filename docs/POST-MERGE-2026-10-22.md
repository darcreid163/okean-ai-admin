# Post-merge verification — 22 October 2026

PRs #78, #79, #80, #83 and #84 were merged after their exact-head Native/Cloud
checks passed. Before #84, its prerequisite #83 was merged, the PR was retargeted
to main and main was merged into its branch; updated head b91f8ad again passed
both workflows before merge. Source main a9574e9 then passed:

- Native: https://github.com/darcreid163/okean-ai-admin/actions/runs/39740415030
- Cloud: https://github.com/darcreid163/okean-ai-admin/actions/runs/39740414979

## Additional gap found after merging

The existing frontend HTTP and browser navigation tests were absent from the
consolidated Cloud workflow. Running `frontend-session.py` exposed three failing
cases out of 15: the HTTP fixture's fake usage response lacked current period
fields, the fixture did not load QuotaPolicy and its Settings stub lacked the
canonical endpoint. Actual Surface requires those interfaces and correctly failed
closed. This is stale fixture/coverage evidence, not proof of a production outage.

The corrective change for #85 makes the fixture use actual QuotaPolicy terms and
UTC windows from the checked-in plan policy, supplies the canonical endpoint, and
checks current daily Pro, monthly Free and retained legacy monthly terms. HTTP
responses are also checked for PHP warnings/fatal errors. Runtime classes and the
immutable recovered 0.3.11 manifest remain unchanged.

## Restored gates

- 18 HTTP session/account/nonce/OAuth/period cases in PHP 8.2/8.3/8.4 jobs.
- 18 real Chromium Cloud pairing/OAuth/shared-account navigation assertions.
- 22 real Chromium actual Native consent allow/deny/manual/denial assertions.
- Browser dependencies are pinned outside the repository working tree.
- Cloud candidate packaging depends on both database contracts and browser gates.

All three suites passed locally using PHP 8.3.35 and Playwright 1.62.1 with the
installed Chromium. The browsers handle real response headers, cookies and
cross-origin navigation; WordPress session/back-channel/consent storage remain
synthetic fixtures. This does not establish live public HTTPS, external ChatGPT
acceptance, customer database migration or production deployment. The corrected
commit must pass hosted exact-HEAD CI and then main's post-merge CI.
