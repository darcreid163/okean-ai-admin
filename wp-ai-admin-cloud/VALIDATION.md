# Core 0.3.4 validation — 2026-10-07

Live diagnosis: Core 0.3.3 is active on wpaiadmin.ru. wpai_gateway_enabled returns string "1"; database schema version is "2". Health reports configured=false/oauth=false; authenticated site enumeration returns 503 cloud_not_configured. WordPress core diagnostics are healthy.

Root cause: Settings::enabled compared the persisted option strictly to boolean true. Official WordPress get_option documentation specifies that non-serialized scalar options read from the database are strings, while the freshly saved cached value may retain its original type. Accept true, 1 and "1"; retain strict disabled/noncanonical values and the existing WP_AI_CLOUD_ENABLED override.

Local PHP 8.3: all syntax passed; 125 core assertions, 76 compatibility assertions, 50 OAuth assertions and eight HTTP frontend-session tests passed. Compatibility now models cold scalar Options API reads, verifies enabled/off values and confirms existing keys/Core data remain unchanged. Substituting 0.3.3 Settings makes the cold-read assertion fail before unrelated checks. CI repeats PHP 8.2/8.3/8.4 and MySQL 8.

No live installation was performed through the native connector, which does not expose plugin installation. Existing enablement should be recognized after upgrade; configured=true must still be confirmed live. Multi-site dispatch and ChatGPT/Qwen OAuth remain pending site registration and client authorization.
