#!/usr/bin/env bash
set -euo pipefail
TASK_REPO_DIR="$PWD"
TASK_WP_DIR="$RUNNER_TEMP/wp-upgrade-site"
TASK_CLI_FILE="$RUNNER_TEMP/wp-cli.phar"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$TASK_CLI_FILE"
mkdir -p "$TASK_WP_DIR"
task_wp() { php "$TASK_CLI_FILE" --path="$TASK_WP_DIR" "$@"; }
task_wp core download
task_wp config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1 --skip-check
task_wp core install --url=http://wp-upgrade.test --title='Upgrade CI' --admin_user=ci-admin --admin_password=disposable-ci-password --admin_email=ci@example.test --skip-email
task_wp plugin install woocommerce elementor wordpress-seo --activate
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
