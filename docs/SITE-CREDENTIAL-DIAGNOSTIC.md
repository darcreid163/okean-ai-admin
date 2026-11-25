# Read-only Cloud site credential diagnostic

This diagnostic is for the WP AI Admin Cloud Core operator. It does not reconnect a site, rotate keys, modify the site row, or send an HTTP request to WordPress.

## Preconditions

- Use the Cloud WordPress installation that owns the active site registration.
- Deploy this candidate code to a disposable/staging Cloud instance first; do not update production just to run the diagnostic.
- Use the numeric WordPress owner ID and the exact `reg_...` registration ID shown in the Cloud site list.
- Run WP-CLI from the Cloud WordPress root, with WP AI Admin Cloud active.

## Command

Replace `OWNER_ID` and `reg_REGISTRATION_ID` with the actual values:

```sh
wp eval '$result = \\WPAICloudGateway\\WordPress::diagnoseSiteCredential("OWNER_ID", "reg_REGISTRATION_ID"); echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;'
```

The diagnostic reads the active site row and attempts to authenticate/decrypt its sealed credential in memory. It prints only registration metadata and boolean checks. It never prints the site token, status key, ciphertext, URL, or key material. It deliberately bypasses schema migration and does not call the upstream site.

## Interpret the result

- `vault_binding_valid: true`, `site_token_format_valid: true`: the current Cloud Vault key successfully decrypts the stored site credential with the current `owner` and registration ID. Continue with the separate read-only upstream `tools/list` probe.
- `failure: secret_binding_failed`: the ciphertext is structurally present, but authenticated decryption failed for the current Vault key and associated binding `site:{owner}:{registration_id}`. Check whether the effective `WP_AI_CLOUD_VAULT_KEY` changed, whether the row's owner/ID changed, and whether the database was restored independently from the matching key configuration. Do not generate a new key or overwrite the row.
- `failure: invalid_sealed_secret` or `sealed_payload_format_valid: false`: the stored value is malformed, truncated, or not valid base64 ciphertext. Compare with a database backup before attempting recovery.
- `failure: invalid_site_credential`: decryption succeeded, but the plaintext credential document does not match the expected schema.
- `site_token_format_valid: false`: the decrypted site token does not match the accepted token format; do not print or copy it into logs.

A successful local decryption does not prove that the Native endpoint is reachable or that the existing token is accepted. It only clears the Cloud-side secret/binding stage.

## Safety

Do not paste keys, tokens, ciphertext, database rows, or `wp-config.php` into chat. Share only the JSON diagnostic output. Do not rotate `AUTH_KEY`, `AUTH_SALT`, `WP_AI_CLOUD_VAULT_KEY`, or `WP_AI_CLOUD_SIGNING_KEY` during diagnosis.
