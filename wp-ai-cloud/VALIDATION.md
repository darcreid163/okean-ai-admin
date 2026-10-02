# Validation — 2026-10-02

Source commit: `eafac96a0b653f5897407078e916aefb334c64fb`.

- PHP 8.3 local lint: all 12 PHP source/test/tool files passed.
- Local SQLite: 107 assertions passed.
- 10 independent processes / 3 monthly action slots: exactly 3 upstream executions, 3 used units, no remaining reservation.
- 10 independent retries of one logical action: exactly 1 upstream execution and 1 used unit.
- Signed envelope accepted by the unchanged client V1 verifier from WP AI Admin 2.1.10.
- GitHub Actions [run 37030428591](https://github.com/darcreid163/okean-ai-admin/actions/runs/37030428591): PHP 8.2, 8.3 and 8.4 jobs passed lint, SQLite and MySQL tests. Jobs: 110915572544, 110915572927, 110915573040.
- [Second run 37030424035](https://github.com/darcreid163/okean-ai-admin/actions/runs/37030424035): success.
- Installer ZIP: 10 files, CRC verification passed; no tests, GitHub configuration, runtime dependencies or private keys included. SHA-256: `acc4794b77b6505cf183685e9afbeaaf84534c74ad95f73ffb2b58cb5d8bc56e`.

These tests exercise the core and real database transaction models, with a controlled upstream WordPress transport fixture. They do not prove public WordPress endpoint interoperability, browser OAuth login, live token provisioning, real site ownership transfers, sandbox write/rollback, public DNS/TLS or production deployment. No changes were made to the public wpaiadmin.ru website during this task.
