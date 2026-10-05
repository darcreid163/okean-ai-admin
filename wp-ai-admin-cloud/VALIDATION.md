# Core 0.3.0 validation, 2026-10-05

Local PHP 8.3: 125 core, 58 compatibility/HTTP/migration and 50 OAuth assertions passed. Ten concurrent token exchanges of one code produced exactly one access token. Public client: 10 offline tests passed. Original Core DB/plan seeding/REST files remain byte-identical to recovered Core0.1.0.

These fixtures use real PDO transactions and stub WordPress APIs/HTTP where necessary. WordPress login, browser consent redirect, deployment routing, DNS/TLS, native plugin end-to-end lifecycle and live Qwen3.8-Max/ChatGPT calls are not covered by fixture success. CI executes original legacy DDL on MySQL 8 and repeats on PHP 8.2/8.3/8.4.

Actual live server at inspection: Cloud Core0.2.0 active, configured=false, oauth=false. The new version is prepared for upload, not deployed.
