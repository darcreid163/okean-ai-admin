---
name: release-engineering
description: Reproducible separate Native and Cloud beta packages, versions, changelog, checksums and install/upgrade verification.
---
# Release Engineering
Verify exact main/head SHA, versions, tags, PR checks and repo policy. Keep Native ZIP and Cloud package separate; never bundle Cloud server code in Native. Build from clean reviewed commit; inspect archive contents and plugin headers. Verify install, upgrade, reconnect and rollback in disposable environments. Generate SHA-256, changelog, metadata, known limitations and instructions. Mark TEST/OPEN BETA until all gates pass. Attach artifacts only after CI succeeds on exact SHA. No production deployment, repo visibility changes, live account migration, public tags or live payments without explicit approval. Never reuse stale ZIPs or claim an artifact verified without checking exact file and checksum.
