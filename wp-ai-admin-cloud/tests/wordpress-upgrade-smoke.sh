#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${RUNNER_TEMP:=/tmp}"
WORK="$RUNNER_TEMP/wpai-cloud-upgrade-smoke"
mkdir -p "$WORK/site"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WORK/wp-cli.phar"
wp_cloud_smoke() { php "$WORK/wp-cli.phar" --path="$WORK/site" --allow-root "$@"; }
wp_cloud_smoke core download --version="${WP_TEST_VERSION:-latest}"
wp_cloud_smoke config create --dbname=wpai_cloud_upgrade_test --dbuser=root --dbpass=ci-cloud-upgrade-only --dbhost=127.0.0.1
wp_cloud_smoke core install --url=http://localhost:8080 --title=CloudUpgrade --admin_user=ci-admin --admin_password=synthetic-cloud-upgrade-password --admin_email=ci@example.invalid --skip-email
python3 wp-ai-admin-cloud/tools/build-candidate.py --output "$WORK/candidate"
OAI_DISPOSABLE_WP_TEST=1 bash wp-ai-admin-cloud/tests/wordpress-upgrade-restore.sh "$WORK/site" "$WORK/wp-cli.phar" "$WORK/candidate/wp-ai-admin-cloud-0.3.13-dev.zip"
wp_cloud_smoke eval 'if(get_option("wpai_gateway_wrapped_keys_v1",false)!==false || get_option("wpai_gateway_enabled",false)!==false)throw new RuntimeException("Cloud fixture leaked into pristine database"); echo "PASS: pristine database has no synthetic gateway keys or enabled flag\n";'
