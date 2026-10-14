#!/usr/bin/env bash
set -euo pipefail
TASK_REPO_DIR="$PWD"
TASK_WP_DIR="$RUNNER_TEMP/wp-upgrade-site"
TASK_CLI_FILE="$RUNNER_TEMP/wp-cli.phar"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$TASK_CLI_FILE"
mkdir -p "$TASK_WP_DIR"
task_wp() { php "$TASK_CLI_FILE" --path="$TASK_WP_DIR" "$@"; }
task_wp core download --version="${WP_TEST_VERSION:-latest}"
task_wp config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1 --skip-check
task_wp core install --url=http://wp-upgrade.test --title='Upgrade CI' --admin_user=ci-admin --admin_password=disposable-ci-password --admin_email=ci@example.test --skip-email
task_install_plugin() {
 local task_plugin="$1" task_version="$2"
 if [[ "$task_version" == latest ]]; then
  task_wp plugin install "$task_plugin" --activate
 else
  task_wp plugin install "$task_plugin" --version="$task_version" --activate
 fi
}
task_install_plugin woocommerce "${WOO_TEST_VERSION:-latest}"
task_install_plugin elementor "${ELEMENTOR_TEST_VERSION:-latest}"
task_install_plugin wordpress-seo "${YOAST_TEST_VERSION:-latest}"
mkdir -p "$TASK_WP_DIR/wp-content/mu-plugins"
cat > "$TASK_WP_DIR/wp-content/mu-plugins/upgrade-save-listener.php" <<'PHP'
<?php
// Simulate a plugin whose save listener requires initialized WP services.
add_action('save_post',function(){
 if(!did_action('wp_loaded'))throw new Error('Post saved before dependent plugins initialized');
});
PHP
unzip -q "$TASK_REPO_DIR/releases/2.1.5/wp-ai-admin-2.1.5.zip" -d "$TASK_WP_DIR/wp-content/plugins"
task_wp plugin activate okean-ai-admin
OAI_BOOT_CASE=seed task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-upgrade.php"
if task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-upgrade.php" > "$RUNNER_TEMP/wp-upgrade-baseline.log" 2>&1; then
 echo 'ERROR: 2.1.5 did not reproduce the early-save regression'; exit 1
fi
grep -F 'Post saved before dependent plugins initialized' "$RUNNER_TEMP/wp-upgrade-baseline.log"
echo 'Real WordPress 2.1.5 early-save regression reproduced'
cp -a "$TASK_REPO_DIR/okean-ai-admin.php" "$TASK_WP_DIR/wp-content/plugins/okean-ai-admin/"
cp -a "$TASK_REPO_DIR/includes/." "$TASK_WP_DIR/wp-content/plugins/okean-ai-admin/includes/"
task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-upgrade.php"
task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-upgrade.php"
task_wp plugin list --fields=name,version,status
task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-release-install.php"
task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-mcp-onboarding.php"

for task_option_phase in plan apply rollback verify yoast-cold-verify; do
 OAI_OPTION_PHASE="$task_option_phase" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-options.php"
done

# Public native plugin, version and archive pinned; this is a minimal theme API
# fixture, not a licensed commercial Woodmart theme installation.
TASK_WOODMART_ZIP="$RUNNER_TEMP/woodmart-mcp-1.0.1.zip"
TASK_WOODMART_DIR="$RUNNER_TEMP/woodmart-native"
cp "$TASK_REPO_DIR/tests/fixtures/woodmart-mcp-1.0.1.zip" "$TASK_WOODMART_ZIP"
echo "d9d8a8ac07cc189488cb30356acfec1d7b885312faab741ed40fa0bf6c640615  $TASK_WOODMART_ZIP" | sha256sum -c -
unzip -q "$TASK_WOODMART_ZIP" -d "$TASK_WOODMART_DIR"
cp "$TASK_REPO_DIR/tests/woodmart-native-fixture.php" "$TASK_WP_DIR/wp-content/mu-plugins/woodmart-native-fixture.php"
for task_woodmart_phase in plan apply rollback verify; do
 OAI_WOODMART_NATIVE_DIR="$TASK_WOODMART_DIR/woodmart-mcp" OAI_WOODMART_PHASE="$task_woodmart_phase" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-woodmart.php"
done


# On the disposable WordPress, exercise the new grant and MCP through real REST/Options APIs.
OAI_WOODMART_NATIVE_DIR="$TASK_WOODMART_DIR/woodmart-mcp" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-browser-pairing.php"

# Render actual admin links and callbacks, reproduce the reported 2.1.11 regression, then retest the update.
OAI_WOODMART_NATIVE_DIR="$TASK_WOODMART_DIR/woodmart-mcp" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-admin-navigation.php"
unzip -oq "$TASK_REPO_DIR/releases/2.1.11/wp-ai-admin-2.1.11.zip" -d "$TASK_WP_DIR/wp-content/plugins"
if OAI_WOODMART_NATIVE_DIR="$TASK_WOODMART_DIR/woodmart-mcp" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-admin-navigation.php" > "$RUNNER_TEMP/admin-navigation-baseline.log" 2>&1; then
 echo 'ERROR: 2.1.11 must reproduce the wrong main menu target'; exit 1
fi
grep -F 'WP AI Admin main menu must open its dashboard' "$RUNNER_TEMP/admin-navigation-baseline.log"
cp -a "$TASK_REPO_DIR/okean-ai-admin.php" "$TASK_WP_DIR/wp-content/plugins/okean-ai-admin/"
cp -a "$TASK_REPO_DIR/includes/." "$TASK_WP_DIR/wp-content/plugins/okean-ai-admin/includes/"
OAI_WOODMART_NATIVE_DIR="$TASK_WOODMART_DIR/woodmart-mcp" task_wp eval-file "$TASK_REPO_DIR/tests/wordpress-admin-navigation.php"
