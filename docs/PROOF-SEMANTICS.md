# WP AI Admin — Proof and Receipt Semantics

Status: release-candidate contract for native 2.1.15 / Cloud Core 0.3.6.

## Native operation proof

The native WP AI Admin operation proof is a **local integrity proof**, not a remote digital signature.

It contains a SHA-256 hash derived from the canonical operation payload, including the operation identity, target/type, before/after/applied state, metadata and verification result.

It is intended to prove consistency between:
- the operation record;
- the state that Apply/Rollback verified;
- the receipt returned by the native plugin;
- the plugin/build that generated the proof.

It does **not** claim to protect against a privileged database administrator who can arbitrarily rewrite the WordPress database and all related proof fields.

## Native receipt

The receipt is a structured summary of one local operation:
- operation ID / DB ID;
- lifecycle state/phase;
- operation type/target/risk;
- timestamps;
- rollback availability;
- stored receipt/proof;
- verified flag derived from the stored execution proof.

Receipts and proofs are recovery/audit evidence for the managed WordPress lifecycle. They are not a payment receipt and are not a commercial billing entitlement.

## Cloud Core idempotency/reconciliation evidence

Cloud Core uses sealed storage for:
- successful/failed idempotent replay responses;
- uncertain-write reconciliation evidence;
- site credentials.

The seal provides authenticated encryption bound to the expected owner/request/site context. A ciphertext copied to a different binding must fail closed.

## Cloud Foundation proof

Cloud Core's Foundation compatibility proof is different from the native SHA-256 operation proof.

Foundation uses an Ed25519 signature and contains claims such as:
- issuer/audience;
- site/installation/registration ID;
- issue/not-before/expiry time;
- policy sequence.

The current Foundation object is explicitly:
- `purpose = v1_compatibility_proof_only`;
- `commercial_entitlement_enforced = false`.

It must not be represented as proof that paid billing or commercial entitlement enforcement is complete.

## Release rule

Documentation/UI must not use ambiguous wording that implies:
- native SHA-256 proof is a centrally signed attestation;
- native operation receipt is a payment receipt;
- Foundation compatibility proof is a paid-plan entitlement.

Each proof type must be described using the semantics above.
