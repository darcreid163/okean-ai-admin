# Repository working instructions

Before changing onboarding, MCP/OAuth authorization, site registration, credential storage, administrator navigation, or related upgrade behavior, read [the connection contract](docs/CHATGPT-CONNECTION-CONTRACT.md).

Treat contract version 1.2 (native 2.1.14 / central Core 0.3.6, compatible with prior native 2.1.12 pairing) as the compatibility baseline. Preserve the canonical gateway https://wpaiadmin.ru/mcp, two distinct consent flows, owner/site isolation, persistent identities, revocation semantics, native approval lifecycle and valid dashboard navigation.

Changes are allowed through the contract's versioned amendment/migration process. Add the relevant regression coverage and run the affected existing gates; do not bypass a failing check or silently revert browser onboarding to manual-token instructions. Keep runtime behavior and user documentation aligned.

Do not put passwords, tokens, authorization codes, verifiers or full OAuth callback URLs into commits, logs or artifacts. Report automated-test results separately from actual ChatGPT/two-site live acceptance. Never claim that public enrollment, billing, planned providers or untested OAuth behavior are complete.
