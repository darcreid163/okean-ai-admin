---
name: docker-wordpress-testing
description: Reproducible disposable WordPress, MariaDB, PHP, WooCommerce, Elementor and Cloud test environments with Compose and WP-CLI.
---
# Disposable WordPress Test Lab
Inspect existing Compose files first. Pin image versions; avoid floating latest. Bind ports to localhost. Use synthetic credentials and fixtures; exclude local env files from Git. Health-check services. Install actual built Native ZIP into disposable WP; provision Cloud separately; never bundle Cloud files into Native. Use WP-CLI for deterministic setup and assertions. Snapshot disposable DB before migrations; test repeat and interrupted recovery. Teardown only resources created by the test. Record image tags, versions, commands, logs and artifact SHA. Never mount production directories, backups or live credentials. A local lab is not evidence of hosted CI or production behavior.
