# Cloud Core key management and WordPress salt rotation

Cloud Core uses two long-lived secrets:

- **Vault key** — encrypts stored site credentials, cached response payloads and evidence.
- **Signing key** — signs entitlements and usage receipts.

## How keys are stored

On first gateway enable, `Settings::prepareKeys()` generates both keys. The pair is encrypted with XChaCha20-Poly1305 and stored in the non-autoloaded WordPress option `wpai_gateway_wrapped_keys_v1`. The wrapping key is derived with HKDF-SHA-256 from `AUTH_KEY` and `AUTH_SALT` in `wp-config.php`.

Both explicit constants are also supported:

- `WP_AI_CLOUD_VAULT_KEY` — base64-encoded 32-byte key.
- `WP_AI_CLOUD_SIGNING_KEY` — base64-encoded 64-byte Sodium signing secret key.

Define both together, before WordPress loads. When present, they are the effective keys instead of the wrapped option.

## Existing installation: preserve keys before rotating WordPress salts

**Do not change `AUTH_KEY` or `AUTH_SALT` until the current Cloud keys have been preserved.**

1. Back up the database and `wp-config.php`.
2. From a shell, export the exact keys currently used by the installation. Supply the WordPress root and a new private destination outside the web root:

   ```sh
php /path/to/wordpress/wp-content/plugins/okean-ai-admin/wp-ai-admin-cloud/tools/export-keys.php \
  /path/to/wordpress \
  /root/private/wpai-cloud-keys.php
```

   The command is CLI-only, refuses paths inside the WordPress root, never overwrites an existing file, creates the output with mode `0600`, and does not print key values.
3. Copy the two `define()` statements from that private snippet into `wp-config.php`, above the line that loads `wp-settings.php`. Keep the snippet outside the web root and do not commit it.
4. Verify the gateway status page and test an existing site connection before changing salts.
5. Only after the explicit constants are active and verified may you rotate `AUTH_KEY` and `AUTH_SALT`. The vault and signing keys remain unchanged.

The exported constants must be the exact current keys. Generating new values and placing them into an existing installation does **not** preserve access to existing encrypted data.

## Fresh installation

For a new installation with no existing encrypted data, `tools/generate-keys.php` can create a new private configuration snippet. Use a new absolute path outside the web root, add both definitions to `wp-config.php`, and keep the file private.

Do not use `generate-keys.php` as a recovery or rotation tool for an existing installation: it creates new random keys.

## Vault/signing-key rotation is different

The export utility preserves the current keys while decoupling them from WordPress salts. It does **not** rotate the vault or signing keys.

A true vault-key rotation requires decrypting and re-encrypting every sealed record, verifying all records, and retaining rollback capability. A signing-key rotation also changes the public key/key ID and requires a coordinated trust/public-key publication procedure. There is no automatic in-place rotation workflow in this release candidate. Do not replace either explicit key with a newly generated value until a dedicated rotation procedure is available.

## If the salts have already changed

- If the old `AUTH_KEY` and `AUTH_SALT` are available, restore them temporarily, export the current keys, add the explicit constants, verify the gateway, and then rotate salts.
- If an exported key snippet already exists, restore those exact constants in `wp-config.php` and verify the gateway.
- If neither the old salts nor the exported keys are available, the wrapped keys may no longer be decryptable. Restore the matching `wp-config.php` and database backup if available. Do not generate new keys or delete `wpai_gateway_wrapped_keys_v1`; those actions do not recover existing ciphertext.

Never place key values in tickets, chat, screenshots, logs, HTML, REST responses, source control or ordinary backups without encryption.
