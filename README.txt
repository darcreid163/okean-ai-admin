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

WP AI Admin connects WordPress to compatible AI assistants and MCP clients. It provides a structured way to inspect a site, prepare proposed changes, review a before-and-after diff, authorize an operation, apply it and verify the result.

Instead of allowing an AI assistant to write directly to the live site, WP AI Admin uses an operation lifecycle:

READ → PLAN → SANDBOX → DIFF → APPROVAL → APPLY → VERIFY → RECEIPT

Rollback is available for supported operations when the recorded before-state can be safely restored. If the site has changed since an operation was prepared, conflict checks are designed to stop the operation rather than overwrite newer content.

**Main features**

* **Model Context Protocol (MCP):** expose supported WordPress capabilities to compatible AI clients through the plugin's authenticated connection.
* **Controlled changes:** prepare changes in a sandbox and review the proposed diff before applying them.
* **Approval controls:** operations follow the site's configured approval and lifecycle policy. Manual approval is the safe default; automation modes must be explicitly enabled by an administrator.
* **Verification and rollback:** verify persisted state after supported changes and use the recorded before-state for conflict-checked rollback.
* **Operation history:** review operation status, audit information and receipts.
* **Gutenberg:** inspect and update supported block content while preserving block structure.
* **Elementor:** inspect page structure and prepare supported Elementor page and widget changes.
* **WooCommerce:** work with supported products, prices, stock, categories, tags, attributes and related product data. WooCommerce is required only for WooCommerce features.
* **Media:** upload supported media and manage selected attachment metadata.
* **Menus:** inspect and manage supported navigation menus and menu items.
* **WordPress content:** work with supported posts, pages, excerpts and content.
* **Guarded settings and files:** supported option and file operations use allowlists and security checks; executable files and sensitive paths are restricted.
* **Cloud connection (optional):** pair with WP AI Admin Cloud for centralized account and site management, server-authoritative usage information and licensing features. Cloud service availability and account terms are separate from this GPL-licensed plugin.

The exact tools available depend on the connected client, site permissions, installed plugins and the server-side policy. Not every third-party plugin or custom field is automatically supported.

**Security by design**

* Site access is authenticated and scoped.
* OAuth connections use Authorization Code with PKCE.
* Direct live writes are not the normal operation path.
* Changes are checked again when applied; stale or conflicting operations fail safely.
* Sensitive credentials are not intended to be included in prompts, logs or support requests.
* Deactivation disables plugin automation modes. Uninstall data removal is controlled separately by the administrator.

WP AI Admin does not guarantee that every AI-proposed change is correct. Review proposed changes and maintain current backups before enabling write capabilities.

== Installation ==

1. Download the WP AI Admin plugin ZIP from the official distribution source.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**.
3. Select the ZIP file, choose **Install Now**, then **Activate Plugin**.
4. Open **WP AI Admin** in the WordPress administration menu.
5. Follow the connection instructions to pair the site with WP AI Admin Cloud, or configure a compatible direct MCP OAuth client if your setup supports it.
6. Review the available tools, permissions and approval settings before allowing write operations.

**System requirements**

* WordPress 6.4 or later.
* PHP 8.2 or later. PHP 8.3 is recommended.
* Sodium extension enabled in PHP.
* WooCommerce 8.0 or later only when using WooCommerce features.
* A working HTTPS configuration is strongly recommended for authenticated remote connections.
* Elementor is required only for Elementor-specific features.

**Updating**

Update through the WordPress Plugins screen or install the newer official ZIP. Normal plugin upgrades and deactivation preserve plugin operation history and connection data. Back up your site before major updates.

== Frequently Asked Questions ==

= Does WP AI Admin let an AI assistant change my live site without review? =

The normal workflow is to prepare a sandbox operation, inspect its diff and pass it through the configured approval policy before Apply. Manual approval is the safe default. Optional automation controls are administrator-managed and should only be enabled after reviewing their scope and risks.

= Can I undo a change? =

Supported operations retain the before-state needed for conflict-checked rollback. Rollback can be blocked if the affected content has changed independently since the original operation. Keep regular WordPress backups; rollback is not a replacement for a full backup.

= Which AI assistants can connect? =

WP AI Admin exposes an MCP connection for compatible clients. The client must support the authentication and connection method configured for your site. Availability of a particular MCP integration may depend on that client's own product, plan and settings.

= Do I need WP AI Admin Cloud? =

The plugin supports a direct MCP OAuth connection where a compatible client and configuration are available. WP AI Admin Cloud is an optional service for centralized account/site management, server-authoritative usage information and licensing features. Cloud features require an active service connection and are governed by separate service terms.

= Is WooCommerce required? =

No. WooCommerce is needed only for WooCommerce-specific tools. Core WordPress functionality can be used without WooCommerce.

= Is Elementor required? =

No. Elementor is needed only for Elementor-specific tools. Other supported WordPress functionality does not require Elementor.

= Does WP AI Admin store AI conversations? =

The plugin maintains operation and audit information needed for the lifecycle, verification and rollback features. It is not designed to store full AI conversations or arbitrary tool-response transcripts. Review the plugin's data-retention information and your connected service's privacy terms for details.

= What happens when I deactivate or uninstall the plugin? =

Deactivation disables plugin functionality and automation modes but does not delete operation history or connection records. By default, uninstall preserves plugin-owned data. An administrator can explicitly enable the plugin's uninstall data-removal option before deleting it. WordPress content, products, media and data belonging to other plugins are not deleted by WP AI Admin's uninstall routine.

= Is a backup still necessary? =

Yes. Use regular database and file backups, test important changes on a staging site and review each proposed operation. Operation rollback covers supported changes and cannot replace a complete site backup.

== Privacy ==

WP AI Admin processes site information required to perform the tools and operations that an administrator authorizes. When connected to WP AI Admin Cloud or another MCP client, information needed for the requested operation is transmitted to that service/client. The plugin does not need access to unrelated personal information to perform its supported operations.

The plugin stores operation records and related audit data required for verification and rollback. Cloud account, licensing and usage information may be processed by the optional Cloud service under its separate privacy terms. Do not include passwords, API keys, access tokens, private keys or other secrets in prompts or support messages.

== Uninstall and data retention ==

By default, deleting WP AI Admin preserves its local operation history, audit records, OAuth connections, pairing identity and license settings. To remove plugin-owned data, an administrator must explicitly enable the uninstall deletion option in the WP AI Admin dashboard before deleting the plugin. Deactivation and upgrades do not delete this data. WordPress pages, products, media, users and data owned by other plugins are not removed. On Multisite, consent is checked separately for each site.

== Changelog ==

= 2.1.18 =
* Security: make request rate-limit admission atomic across concurrent requests.
* Security: fail closed when rate-counter storage is unavailable.
* Database: add the Native v25 rate-counter table with bounded expiry cleanup; preserve existing operations, OAuth records and quota data during upgrade.
* WooCommerce: declare High-Performance Order Storage (HPOS) compatibility and test the order sandbox lifecycle against the WooCommerce 11.3.0 custom order datastore.

= 2.1.17 =
* Security: pin the usage-signing key at pairing and bind usage projections to the stored account owner.
* Security: remove superseded pairing credentials when a site is paired again.
* Feature: show the signed server usage projection and display an unknown state when the projection is absent or stale.
* Feature: standardize MCP tool-call error responses and fail closed when billing evidence is incomplete.
* Fix: preserve Gutenberg block structure and byte-exact rollback baselines.

= 2.1.14–2.1.16 =
* Earlier security, compatibility, lifecycle and integration improvements.

== Upgrade Notice ==

= 2.1.18 =
Security and compatibility update. Rate-limit admission is now atomic across concurrent requests. The database upgrade adds a dedicated counter table and preserves existing operation, connection and quota records.
