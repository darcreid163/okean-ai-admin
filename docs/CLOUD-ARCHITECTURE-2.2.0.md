# WP AI Admin Cloud — 2.2.0 Foundation Architecture

## Goal

Turn the validated local WP AI Admin agent into a commercial multi-site product with **one ChatGPT connection for all customer sites**.

The existing WordPress plugin remains the site-local execution agent. The new stage adds a central Control Plane and one public MCP Gateway.

## High-level topology

```
ChatGPT
   |
   | one OAuth/MCP connection
   v
WP AI Admin MCP Gateway
   |
   v
WP AI Admin Control Plane
   |
   +---- Account / Workspace
   |        |
   |        +---- Site A
   |        +---- Site B
   |        +---- Site C
   |
   +---- entitlement / quota / audit / routing
            |
            v
      Site-specific secure channel
            |
            v
WP AI Admin Plugin on WordPress
            |
            v
WordPress / Elementor / Gutenberg / WooCommerce
```

## Component responsibilities

### 1. WordPress plugin

Keep the existing stable local runtime:

- WordPress reads
- Elementor
- Gutenberg
- WooCommerce
- media
- menus
- safe files
- sandbox operations
- preview/diff
- approval
- apply
- verify
- receipt/proof
- rollback
- diagnostics

The plugin remains authoritative for local WordPress state and safety checks.

### 2. Control Plane

New central service responsible for:

- customer accounts
- workspaces
- site registrations
- site ownership/binding
- installation identity
- licenses/subscriptions
- entitlements
- quotas
- site status/last seen
- revoke/freeze controls
- routing metadata
- central audit events
- future billing/partner features

The Control Plane must never turn a denied local operation into an allowed one.

### 3. MCP Gateway

One public MCP connection used by every customer.

Responsibilities:

- authenticate the ChatGPT/user session;
- resolve the customer/workspace;
- list accessible sites;
- resolve a requested site by ID/name/domain;
- reject ambiguous site targeting;
- check entitlement/quota;
- route tool calls to the correct site;
- return normalized results to ChatGPT;
- never expose another customer's sites.

## Canonical cloud identities

Minimum objects:

### Account
- `account_id`
- email/login identity
- status

### Workspace
- `workspace_id`
- `account_id`
- name
- status

### Site
- `site_id`
- `installation_id`
- `workspace_id`
- domain/display name
- WordPress/plugin version
- capabilities/catalog hash
- connection status
- last_seen_at
- revoked/frozen state

### Site credential
Per-installation only. Never shared between sites.

Store only the minimum required credential material. Secrets must not appear in logs, MCP output or wp-admin.

## Pairing flow

1. Plugin already has persistent `site_id` and `installation_id`.
2. Administrator clicks **Connect**.
3. Plugin creates a short-lived pairing request.
4. Browser opens the WP AI Admin account site.
5. User signs in.
6. User confirms which workspace owns the WordPress site.
7. Control Plane binds `site_id + installation_id` to the workspace.
8. Site-specific credential/key material is established.
9. Plugin shows **Connected**.
10. The site appears automatically in `sites.list` through the central MCP Gateway.

Pairing codes/tokens must be short-lived, single-use and bound to the installation.

## Connection model

Preferred commercial model: the WordPress plugin establishes/maintains an outbound HTTPS relationship with the Control Plane.

Do not require customers to:

- open firewall ports;
- configure static IP addresses;
- expose new non-HTTPS services;
- share a universal secret.

For the first beta, a simpler HTTPS request/response relay is acceptable as long as authentication, replay protection, site binding and local sandbox protections remain intact.

## Initial cloud MCP surface

Do not mirror all 111 local tools as separate cloud tools on day one.

Start with a compact router surface:

- `sites.list`
- `sites.get`
- `sites.status`
- `site.execute`
- `operations.list`
- `operations.get`
- `operations.preview`
- `operations.approve`
- `operations.reject`
- `operations.apply`
- `operations.rollback`

`site.execute` routes a canonical local tool plus validated arguments to one explicit `site_id`.

The local plugin remains the source of the detailed 111-tool catalog.

## Security invariants

- One customer cannot enumerate or call another customer's sites.
- Every site request is bound to account/workspace/site.
- Every mutation still creates a sandbox operation.
- Direct live writes remain disabled.
- Site credentials are independent.
- Replay protection is mandatory for signed site requests.
- Revocation must take effect without reinstalling the plugin.
- Workspace/site freeze must block new writes centrally while preserving read/diagnostic access according to policy.
- Cloud failure must fail closed for mutations.
- Local human approval remains the default.
- Test Automation is never enabled by the cloud for customer sites.

## 2.2.0 implementation order

### Phase A — Control Plane data model
Implement account/workspace/site registration and secure site identity.

Exit: a site can register, be bound to a workspace and be revoked.

### Phase B — Pairing UX
Implement **Connect** in WordPress and browser-based account authorization.

Exit: non-technical customer connects a site without copying secrets.

### Phase C — Central MCP Gateway
Expose one OAuth/MCP endpoint and `sites.list`.

Exit: ChatGPT connects once and can list only the authenticated customer's sites.

### Phase D — Site routing
Implement `site.execute(site_id, tool, args)` with strict site binding and read-only calls first.

Exit: one ChatGPT connection can read from two different test WordPress sites.

### Phase E — Safe mutation relay
Route sandbox creation and operation lifecycle while preserving local approval/verify/rollback.

Exit: customer can change one test value through sandbox → approval → apply → verify → rollback.

### Phase F — Commercial controls
Add entitlement/quota/license status and account/site freeze/revoke.

Exit: plan limits can be enforced centrally without weakening local security.

## 2.2.0 non-goals

Do not block the first cloud beta on:

- partner program;
- complex analytics;
- large billing catalog;
- every possible builder;
- autonomous live writes;
- moving WordPress execution logic out of the plugin.

The primary goal is a reliable one-connection/many-sites customer experience.
