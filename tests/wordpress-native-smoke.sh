#!/usr/bin/env bash
set -euo pipefail
: "${RUNNER_TEMP:=/tmp}"
WORK="$RUNNER_TEMP/wpai-native-smoke"
mkdir -p "$WORK/site"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WORK/wp-cli.phar"
wp_test() { php "$WORK/wp-cli.phar" --path="$WORK/site" --allow-root "$@"; }
wp_test core download --version="${WP_TEST_VERSION:-latest}"
wp_test config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1
wp_test core install --url=http://localhost:8080 --title=Smoke --admin_user=ci-admin --admin_password=test-only-ci-password --admin_email=ci@example.invalid --skip-email
wp_test plugin install elementor --activate
mkdir -p "$WORK/site/wp-content/plugins/okean-ai-admin"
cp -R okean-ai-admin.php includes starter-themes "$WORK/site/wp-content/plugins/okean-ai-admin/"
wp_test plugin activate okean-ai-admin
# Disposable site only: exercise the additive Native 23 -> 24 schema migration,
# preserving paired identity, registered OAuth client and pending operations.
wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-quota-upgrade-23-24.php"
wp_test eval 'if(!class_exists("OAI_Elementor")) { throw new Exception("Missing Elementor adapter"); } echo "PASS: Elementor adapter loaded\\n";'
wp_test eval 'if(!class_exists("OAI_MCP")) { throw new Exception("Missing MCP"); } echo "PASS: MCP loaded\\n";'
wp_test plugin status okean-ai-admin
