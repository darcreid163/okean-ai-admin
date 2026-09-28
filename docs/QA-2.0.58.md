# 2.0.58 candidate: signed entitlement verification

Build ID: `2.0.58-signed-entitlement-verifier-20260928`.

The build introduces `OAI_Control_Plane::verify()` as a pure, verification-only function. It does not contact a server, store entitlements, grant paid access or alter the current local license/quota behavior. No private key is packaged.

The envelope is `{alg:"Ed25519", key_id, payload, signature}`. `payload` is unpadded base64url of UTF-8 JSON; `signature` is unpadded base64url of an Ed25519 detached signature over the exact ASCII bytes `OAI-ENTITLEMENT-V1\n` followed by the encoded payload. `key_id` is the first 16 lowercase hex characters of SHA-256 of the raw public key. The public key input is unpadded base64url of 32 raw bytes. Envelopes are limited to 8192 bytes; a token validity interval is at most 3600 seconds.

Required signed claims: `contract_version=1`, `issuer=okean-control-plane`, `audience=wp-ai-admin`, nonempty `site_id`, `installation_id` and `registration_id` equal to the caller's expected binding, integer `issued_at`, `not_before`, `expires_at`, positive integer `policy_sequence`, `plan` in `free/daily/pro/business`, booleans `active` and `write_frozen`, an allowlisted unique feature array, and `quota={limit:0..10000000,period:day}`. Time uses Unix seconds; issuance and start permit at most 60 seconds of clock skew; expiry is strict. The caller supplies its last accepted sequence; lower sequences are rejected.

The CI cryptographic suite covers a valid signature, changed plan without resigning, wrong public key, cross-site/install/registration replay, expired and future tokens, excessive TTL, policy downgrade, invalid features/quota/version and wrong integer type. PHP lint and ZIP integrity run on 8.2–8.4, plus the existing MySQL mutex and OAuth migration tests.

After installation, verify 90/90 catalog, 51/51 self-test and unchanged installation identity; run reversible regression matrix and inspect baseline. A real external Control Plane, signed policy caching, revocation polling and quota reservation must pass separate tests before commercial activation.
