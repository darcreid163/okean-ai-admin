# Repository boundaries — current state 2026-11-24

The current authority is [REPOSITORY-RECONCILIATION-2026-11-24.md](REPOSITORY-RECONCILIATION-2026-11-24.md). This file is a short policy summary.

| Repository | Visibility | Role | Current rule |
|---|---|---|---|
| `darcreid163/okean-ai-admin` | PRIVATE | Canonical Native + Cloud development monorepo | All active WP AI Admin development and integration PRs target this repository |
| `darcreid163/wp-ai-admin` | PUBLIC | Historical Native-only publication snapshot (README reports 2.1.16) | Do not use as development source; export only after release qualification |
| `darcreid163/wp-ai-admin-cloud-core` | PRIVATE | Historical Cloud-only snapshot (README describes 0.3.6) | Do not use as development source; export only after release qualification |
| `darcreid163/universal-mcp-platform` | PRIVATE | Independent future product | Keep separate; no source synchronization |
| `darcreid163/WP-AI-ADMIN-TEST` | PRIVATE | Robokassa test-kit artifacts | Test evidence only; no production data or credentials |

## Release export boundary

- Native distribution contains only Native plugin files and its approved public documentation.
- Cloud distribution is exported separately to the private Cloud repository.
- Do not copy branches or files between repositories by name/version alone. Compare source, tests, contracts and exact commit SHAs.
- Do not delete old repositories, branches, PRs, tags or history as a cleanup shortcut.
- Do not change repository visibility as part of code reconciliation.
- Publicly disclosed Git history cannot be made private retroactively.

## Integrated release gate

The Native 2.1.18 integration and lifecycle PRs, Robokassa/licence delivery stack, tariff contract, OAuth policy, PHP file-write restrictions, both CI suites and artifact install tests must be reconciled on one exact SHA before exporting either release repository.
