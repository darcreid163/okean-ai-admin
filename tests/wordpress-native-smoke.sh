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
install_ci_plugin() {
  local plugin="$1" version="$2"
  if [[ "$version" == "latest" ]]; then
    wp_test plugin install "$plugin" --activate
  else
    wp_test plugin install "$plugin" --version="$version" --activate
  fi
}
install_ci_plugin elementor "${ELEMENTOR_TEST_VERSION:-latest}"
install_ci_plugin woocommerce "${WOO_TEST_VERSION:-latest}"
# Install the historical Native first, then exercise real code upgrade and a
# complete pre-upgrade code/database restore. Restore the clean fixture before
# the remaining smoke suites, including their unlicensed-denial assertions.
OAI_DISPOSABLE_WP_TEST=1 bash tests/wordpress-native-upgrade-restore.sh "$WORK/site" "$WORK/wp-cli.phar"
mkdir -p "$WORK/site/wp-content/plugins/okean-ai-admin"
cp -R okean-ai-admin.php includes starter-themes "$WORK/site/wp-content/plugins/okean-ai-admin/"
wp_test plugin activate okean-ai-admin
# Disposable site only: exercise the additive Native 23 -> 24 schema migration,
# preserving paired identity, registered OAuth client and pending operations.
wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-quota-upgrade-23-24.php"
wp_test eval 'if(!class_exists("OAI_Elementor")) { throw new Exception("Missing Elementor adapter"); } echo "PASS: Elementor adapter loaded\\n";'
wp_test eval 'if(!class_exists("OAI_MCP")) { throw new Exception("Missing MCP"); } echo "PASS: MCP loaded\\n";'
wp_test plugin status okean-ai-admin

# Draft-only disposable WooCommerce/Gutenberg regression imported after its
# original Native PR #20 passed both WP profiles. No site publishing.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-commerce-blocks-matrix.php"
# Normal Native license/approval/rollback lifecycle, temporary product only.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-woocommerce-lifecycle.php"
# Current target policy also applies to historical pending/approved options.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-option-policy.php"
# Decode real images before upload, then exercise approval/verify/rollback.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-media-upload.php"
# Durable media admission precedes persistent artifacts and survives ambiguity.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-media-creation.php"
# Stored operation risk agrees across real sandbox creators and audit/receipt.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-operation-risk.php"
# Atomic duplicate rejection for new existing-target intents (six WP workers).
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-pending-intent.php"
# Historical evidence is projected without rewriting raw records or proof hashes.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-public-evidence.php"

# Exercise actual WP Abilities registration and Native read-only discovery.
mkdir -p "$WORK/site/wp-content/mu-plugins"
cp "$GITHUB_WORKSPACE/tests/fixtures/oai-abilities-mu.php" "$WORK/site/wp-content/mu-plugins/oai-abilities-mu.php"
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-abilities-smoke.php"

# Disposable workspace creation: durable admission before draft filesystem artifacts.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-workspace-creation.php"

# WooCommerce new draft products: atomic pre-save intent admission and six workers.
OAI_DISPOSABLE_WP_TEST=1 wp_test eval-file "$GITHUB_WORKSPACE/tests/wordpress-product-creation.php"
