# WP AI Admin — Commercial Customer Flow

Status: **FROZEN product contract for the Commercial Beta / Cloud stage**

This document defines how WP AI Admin must look and behave for an ordinary customer. Product, plugin, Control Plane and MCP Gateway work should preserve this flow unless the contract is explicitly revised.

## Customer journey

1. Customer buys WP AI Admin.
2. Customer installs the WP AI Admin plugin on a WordPress site.
3. In WordPress the customer clicks **Connect**.
4. The customer is redirected to the WP AI Admin account website and authorizes the site.
5. The customer connects **WP AI Admin once in ChatGPT**.
6. In ChatGPT the customer asks:

   > Show my sites.

7. WP AI Admin returns the sites available to that account, for example:

   - Okean.market
   - Shop.ru
   - Company.ru

8. The customer asks:

   > On Shop.ru change the phone number in the footer.

9. WP AI Admin routes the request to the selected site and preserves the existing safe lifecycle:

   **read → plan → sandbox operation → preview/diff → approval → apply → verify → receipt/proof → rollback when supported**

## Product rule: one ChatGPT connection, many WordPress sites

The customer connects one public WP AI Admin application/MCP endpoint in ChatGPT.

The customer must **not** need to:

- create a separate ChatGPT connector for every WordPress site;
- manually paste each site's WordPress MCP endpoint;
- manage WordPress application passwords in ChatGPT;
- expose one shared secret across all sites;
- know internal site IDs for normal use.

The cloud layer resolves the account/workspace/site and routes the request to the correct WordPress installation.

## Site connection

Each WordPress installation keeps its own independent identity:

- `site_id`
- `installation_id`
- site-specific credential/key material
- workspace/account binding

No credential is shared across customer sites.

A compromised site credential must not authorize access to another site.

## Default customer safety

Commercial defaults remain:

- direct live writes: **OFF**
- human approval: **ON**
- AI Lifecycle Control: **OFF**
- Test Automation: **OFF**
- sandbox before apply: **required**
- verify after apply: **required**
- rollback when supported: **required**
- manual controls: **preserved**

Cloud routing must not weaken the local WordPress lifecycle guards.

## Multi-site UX

ChatGPT should support natural commands such as:

- “Show my sites.”
- “Use Shop.ru.”
- “Audit Company.ru.”
- “On Okean.market update product stock.”
- “Create a draft landing page on Shop.ru.”
- “Show pending operations on all my sites.”

When a request is ambiguous and more than one site could match, the system must ask the user to select a site instead of guessing.

## Disconnect behavior

From WordPress, the customer sees one simple connection state:

- **Connected**
- **Disconnected**

The customer can click one **Disconnect** action.

Disconnect must revoke the site's cloud authorization without deleting WordPress content, operation history or local plugin identity unless the customer explicitly requests a destructive reset.

## Acceptance criteria

The Commercial Cloud flow is ready when a new customer can complete the following without technical configuration:

1. install plugin;
2. click Connect;
3. sign in to the WP AI Admin account;
4. authorize the site;
5. connect WP AI Admin once in ChatGPT;
6. ask “Show my sites”;
7. select one site by name;
8. create a reversible sandbox operation;
9. preview it;
10. approve/apply it;
11. verify the result;
12. disconnect the site from WordPress.

This flow is the primary product KPI for the next development stage.
