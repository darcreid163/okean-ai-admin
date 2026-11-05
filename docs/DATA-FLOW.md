# WP AI Admin — Technical Data Flow

Status: technical disclosure for the unreleased candidate **Native 2.1.17 (DB24)** / **Cloud Core 0.3.13-dev (schema4)**.
This document describes implemented data paths. It is not a substitute for the final Privacy Policy or Terms of Service.

## Actors

1. WordPress administrator and managed WordPress site.
2. WP AI Admin native plugin installed on the managed site.
3. WP AI Admin Cloud Core hosted at wpaiadmin.ru.
4. MCP client/provider such as ChatGPT.
5. Database/storage used by the managed WordPress site and by Cloud Core.

## Site registration

During browser pairing/site registration:

- the user provides the canonical HTTPS WordPress site URL;
- the native plugin proves that WP AI Admin is installed and exposes its site/installation identity;
- a native site credential is issued for Cloud Core access;
- Cloud Core stores the site credential **sealed**, bound to the central owner and registration ID;
- the raw site credential is not intended to be stored in plaintext;
- the canonical site URL, site identity, installation identity, registration status and timestamps are stored centrally;
- one canonical site URL can have only one active central owner.

The normal onboarding flow does not require a WordPress password or Application Password to be copied into Cloud Core.

## ChatGPT / MCP OAuth

Cloud Core acts as the OAuth authorization server for the public MCP endpoint.

Stored centrally:
- OAuth client metadata;
- explicit selected-site grant;
- granted scopes;
- access-token hash;
- refresh-token hash / rotation state;
- authorization-code hash;
- expiry/revocation metadata.

Not stored in plaintext by design:
- access tokens;
- refresh tokens;
- authorization codes;
- PKCE verifier;
- OAuth state.

OAuth site consent is separate from WordPress site pairing. Adding a new WordPress site does not silently add it to an old OAuth grant. Access tokens are short-lived; token and grant lifecycle is enforced on every request, not only at issue time.

## MCP tool requests

A client sends:
- selected central registration/site ID;
- tool name;
- tool arguments;
- a stable idempotency key for logical mutations.

Cloud Core:
1. authenticates the bearer;
2. re-checks owner/site/scope binding;
3. enforces rate/quota/idempotency policy;
4. opens the sealed site credential;
5. forwards the bounded MCP request to that WordPress site over HTTPS;
6. returns the tool result to the client.

Cloud Core does **not** provide arbitrary shell/network proxy access.

## Request and response retention

For quota/idempotency/reconciliation, Cloud Core stores a request ledger containing metadata such as:
- owner;
- selected site registration;
- tool;
- request fingerprint;
- idempotency-derived request key;
- state;
- policy version;
- reserved/charged units;
- timestamps.

The full request arguments are represented by a cryptographic fingerprint for idempotency rather than intentionally stored as plaintext request payloads.

Completed tool results used for idempotent replay are stored in **sealed response storage**. Because a tool result may contain WordPress content, metadata, product information or other site data, users should assume that the encrypted/sealed idempotency response can contain the data returned by the invoked tool.

Uncertain-write reconciliation evidence is stored sealed.

Audit records use sanitized event names, request keys/hashes and identifiers rather than raw bearer/site credentials.

## Creation claims and uncertain outcomes

Native create tools (workspace, navigation menu, menu item, WooCommerce draft product, Elementor page, media upload) reserve the intent before creating the object:

- one explicit request key identifies one payload; the same key with different content is rejected;
- the reservation is a strict single-winner SQL insert, not a read-then-write option check;
- the claim records actor, payload hash and creation time;
- a claim is not proof that the object exists, and an old timestamp or missing reply is not proof that it does not;
- retries after an uncertain outcome require explicit reconciliation of claim → artifact → operation → Verify, not blind replay;
- legacy claim rows without a payload hash, or from older entry points, remain held until an operator reconciles them.

The read-only privileged claim inventory exposes counts and identifiers; it does not release or edit claims.

## WordPress content

WordPress content remains primarily on the managed WordPress site.

When a user invokes a read or mutation tool, relevant WordPress data necessarily travels:
managed site → Cloud Core → authorized MCP client.

Depending on the tool and idempotency lifecycle, the returned result may also exist in sealed Cloud Core response storage as described above.

The current release candidate does not claim that Cloud Core is a zero-retention relay.

## Mutation lifecycle

The native plugin stores local operation/sandbox information required for:
- diff;
- approval;
- apply;
- verify;
- receipt/proof;
- conflict-safe rollback;
- audit/history.

Direct live writes are disabled by production default.

AI Lifecycle Control and Test Automation are explicit administrator opt-ins and are disabled when the native plugin is deactivated.

Elementor page creation, WooCommerce drafts and menu/workspace artifacts are created as private/sandbox artifacts first. Verification reads authoritative WordPress state, not the request payload.

## Quota and billing metadata

Cloud Core stores:
- current plan identifier;
- billing period key;
- used/reserved action units;
- request ledger entries required for idempotency/accounting;
- operator reconciliation evidence in sealed form.

The Gateway Ledger is the sole authority for quota/charging decisions; the native plugin does not keep a second local ledger for central-mode charging. Failed calls, rollback and idempotent replay are not charged again; every successful Batch object is one action when Batch is enabled.

For the current beta:
- automatic payment activation is not complete;
- commercial client entitlement enforcement is not complete;
- Batch receipt accounting is not enabled;
- the public health contract intentionally reports those capabilities as disabled.

## Logs and diagnostics

Sensitive values must not be intentionally written to logs:
- WordPress passwords;
- Application Passwords;
- site pairing credentials;
- OAuth access/refresh tokens;
- authorization codes;
- PKCE verifiers;
- private keys;
- database credentials;
- full OAuth callback URLs containing sensitive query parameters.

Sanitized error codes, hashes, operation IDs, registration IDs and timing information may be retained for security and diagnostics.

## Deactivation and deletion

Native plugin deactivation:
- disables autonomous AI/Test modes;
- does not erase identities, credentials, operation history or audit evidence.

The current release candidate has no automatic destructive uninstall/data-erasure routine. See `docs/DATA-RETENTION.md`.

A final public Privacy Policy must define:
- legal basis/purpose;
- retention periods;
- account/site deletion;
- subprocessors/hosting;
- support/security contact;
- cross-border processing if applicable;
- user rights and request process.

## Security boundaries

Implemented release gates cover:
- tenant/site/scope isolation;
- token expiry/revoke;
- canonical site ownership;
- SSRF/public-DNS restrictions;
- PKCE and refresh rotation;
- sealed credentials/responses/evidence;
- stale apply/rollback conflicts;
- request-size and JSON-RPC boundaries;
- no-store/nosniff response headers;
- release artifact secret scanning;
- single-winner creation admission for new artifacts.

## Central account suspension

Cloud Core implements a revoke-first account suspension flow separate from permanent deletion.

The owner can first request a closure preview containing only counts of active sites/tokens/OAuth state and retained evidence. A confirmed suspension immediately disables the Core account, revokes bearer/OAuth access, revokes site registrations and clears sealed site credentials.

Request ledger, audit and balance evidence are intentionally retained at this stage. The current release candidate therefore supports access revocation/account suspension, but still does not claim complete destructive account-data erasure.
