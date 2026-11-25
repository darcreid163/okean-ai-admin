=== WP AI Admin ===
Contributors: andriyanovng
Tags: ai, mcp, woocommerce, elementor, gutenberg
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2.1.18
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants to WordPress with a controlled workflow for planning, reviewing, applying and verifying site changes.

== Description ==

WP AI Admin connects WordPress to compatible AI assistants and Model Context Protocol (MCP) clients. It helps administrators inspect a site, prepare proposed changes, review a before-and-after diff, authorize an operation, apply it and verify the result.

The normal operation lifecycle is:

READ > PLAN > SANDBOX > DIFF > APPROVAL > APPLY > VERIFY > RECEIPT

Rollback is available for supported operations when the recorded before-state can be safely restored. If the site has changed since an operation was prepared, conflict checks are designed to stop the operation rather than overwrite newer content.

**Main features**

* **MCP connection:** expose supported WordPress capabilities to compatible, authenticated AI clients.
* **Controlled changes:** prepare sandbox operations and review proposed diffs before applying them.
* **Approval controls:** use the site's configured approval policy. Manual approval is the safe default; automation modes must be explicitly enabled by an administrator.
* **Verification and rollback:** verify persisted state and use recorded before-state for conflict-checked rollback of supported operations.
* **Operation history:** review operation status, audit information and receipts.
* **Gutenberg:** inspect and update supported block content while preserving block structure.
* **Elementor:** inspect page structure and prepare supported page and widget changes.
* **WooCommerce:** work with supported products, prices, stock, categories, tags, attributes and related product data. WooCommerce is required only for these features.
* **Media and menus:** upload supported media, update selected attachment metadata, and manage supported navigation menus and items.
* **WordPress content:** work with supported posts, pages, excerpts and content.
* **Guarded settings and files:** allowlisted option and file operations use security checks; executable files and sensitive paths are restricted.
* **Optional Cloud service:** connect to WP AI Admin Cloud for centralized account/site management, server-authoritative usage information and licensing features. Cloud features require a separate service connection and are governed by the service's terms.

Available tools depend on the connected client, site permissions, installed plugins and server policy. Not every third-party plugin or custom field is automatically supported.

**Security**

* Authenticated, site-scoped access and OAuth Authorization Code with PKCE.
* Changes are checked again at Apply; stale or conflicting operations fail safely.
* Direct, unrestricted live writes are not the normal operation path.
* Deactivation disables plugin automation modes.
* Uninstall data removal is controlled separately by the administrator.

Review proposed changes and keep current backups before enabling write capabilities. AI-generated changes may be incorrect.

== Installation ==

1. Download the official WP AI Admin plugin ZIP.
2. In WordPress, open **Plugins > Add New Plugin > Upload Plugin**.
3. Select the ZIP, choose **Install Now**, then **Activate Plugin**.
4. Open **WP AI Admin** in the administration menu.
5. Follow the connection instructions to pair with WP AI Admin Cloud, or configure a compatible direct MCP OAuth client if supported by your setup.
6. Review available tools, permissions and approval settings before enabling write operations.

**Requirements**

* WordPress 6.4 or later.
* PHP 8.2 or later; PHP 8.3 is recommended.
* PHP Sodium extension.
* HTTPS is strongly recommended for authenticated remote connections.
* WooCommerce 8.0 or later is needed only for WooCommerce features.
* Elementor is needed only for Elementor-specific features.

Update through the WordPress Plugins screen or install a newer official ZIP. Back up your site before major updates.

== Frequently Asked Questions ==

= Can an AI assistant change my live site without review? =

The normal workflow prepares a sandbox operation and sends it through the configured approval policy before Apply. Manual approval is the safe default. Optional automation controls are administrator-managed; enable them only after reviewing their scope and risks.

= Can I undo a change? =

Supported operations retain a before-state for conflict-checked rollback. Rollback may be blocked if the content changed independently. It is not a replacement for a full site backup.

= Which AI assistants can connect? =

WP AI Admin exposes an MCP connection for compatible clients. The client must support the authentication and connection method configured for your site. Availability may also depend on the client's product, plan and settings.

= Do I need WP AI Admin Cloud? =

A direct MCP OAuth connection is supported where a compatible client and configuration are available. The optional Cloud service adds centralized account/site management, server-authoritative usage information and licensing features. Cloud features require an active service connection and separate service terms.

= Are WooCommerce and Elementor required? =

No. Each is required only for its own integration features. Core WordPress tools can be used without either plugin.

= Does WP AI Admin store AI conversations? =

The plugin stores operation and audit information needed for lifecycle, verification and rollback features. It is not designed to store full AI conversations or arbitrary tool-response transcripts.

= What happens when I deactivate or uninstall the plugin? =

Deactivation disables plugin functionality and automation modes but does not delete operation history or connection records. By default, uninstall preserves plugin-owned data. An administrator can explicitly enable the uninstall data-removal option before deleting the plugin. WordPress content and data belonging to other plugins are not removed.

= Is a backup still necessary? =

Yes. Keep regular database and file backups, test important changes on staging, and review each proposed operation.

== Privacy ==

WP AI Admin processes site information needed for tools and operations requested by an administrator. Depending on the tools used, this can include site content, product, order or customer data, and site metadata. When connected to WP AI Admin Cloud or another MCP client, relevant information is transmitted to that service/client to perform the requested operation.

The plugin stores operation records and audit data required for verification and rollback. Optional Cloud account, licensing and usage information is processed by the Cloud service under its separate policies. Do not include passwords, API keys, access tokens or private keys in prompts or support messages. Review the connected service's terms and privacy information before pairing.

== Uninstall and data retention ==

By default, uninstall preserves local operation history, audit records, OAuth connections, pairing identity and license settings. To remove plugin-owned data, an administrator must explicitly enable the uninstall deletion option in the dashboard before deleting the plugin. Deactivation and upgrades do not delete this data. WordPress pages, products, media, users and other plugins' data are not removed. On Multisite, consent is checked separately for each site.

== Support ==

For usage questions, use the plugin's WordPress.org support forum when available. Include the plugin version and sanitized error details. Never post passwords, tokens, private keys, customer records or unredacted callback URLs.

For security issues, follow the private reporting instructions in the project's SECURITY.md file: https://github.com/darcreid163/okean-ai-admin/blob/main/SECURITY.md

== Changelog ==

= 2.1.18 =
* Security: make request rate-limit admission atomic across concurrent requests and fail closed if counter storage is unavailable.
* Database: add the Native v25 rate-counter table with bounded expiry cleanup; preserve existing operations, OAuth records and quota data during upgrade.
* WooCommerce: declare High-Performance Order Storage (HPOS) compatibility and test the order sandbox lifecycle against WooCommerce 11.3.0.

= 2.1.17 =
* Security: pin the usage-signing key at pairing, bind usage projections to the stored account owner, and purge superseded pairing credentials.
* Feature: show signed server usage information and an unknown state when the projection is absent or stale.
* Fix: standardize MCP tool-call error responses and preserve Gutenberg block structure for rollback.

= 2.1.14-2.1.16 =
* Earlier security, compatibility and lifecycle improvements.

== Upgrade Notice ==

= 2.1.18 =
Security and compatibility update. Rate-limit admission is now atomic across concurrent requests. The database upgrade adds a dedicated counter table and preserves existing operation, connection and quota records.
