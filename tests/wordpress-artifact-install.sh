#!/usr/bin/env bash
# Install the Native artifact produced by this CI run into a disposable
# WordPress and boot it. This is deliberately not the smoke suite: the smoke
# suite runs from the checkout, while this script proves that the published
# ZIP is installable, activates without a fatal error and contains only Native
# files.
#
# Usage: bash tests/wordpress-artifact-install.sh <path-to-wp-ai-admin-*.zip>
set -euo pipefail
: "${GITHUB_WORKSPACE:=$(pwd)}"
: "${RUNNER_TEMP:=/tmp}"

ARTIFACT="${1:?usage: wordpress-artifact-install.sh <wp-ai-admin-*.zip>}"
test -f "$ARTIFACT" || { echo "ERROR: artifact not found: $ARTIFACT" >&2; exit 2; }

FILE_VERSION="$(basename "$ARTIFACT" | sed -n 's/^wp-ai-admin-\([0-9][0-9.]*\)\.zip$/\1/p')"
test -n "$FILE_VERSION" || { echo "ERROR: cannot read version from artifact name: $ARTIFACT" >&2; exit 2; }

WORK="$RUNNER_TEMP/wpai-artifact-install"
SITE="$WORK/site"
rm -rf "$WORK"; mkdir -p "$SITE"
curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WORK/wp-cli.phar"
wp_t() { php "$WORK/wp-cli.phar" --path="$SITE" --allow-root "$@"; }

wp_t core download --version="${WP_TEST_VERSION:-latest}"
wp_t config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1
wp_t core install --url=http://localhost:8080 --title="Artifact Smoke" \
  --admin_user=ci-admin --admin_password=test-only-ci-password \
  --admin_email=ci@example.invalid --skip-email

# Install the artifact, not the checkout. A ZIP shipped to testers must be the
# thing that activates.
wp_t plugin install "$ARTIFACT" --activate
STATUS="$(wp_t plugin list --name=okean-ai-admin --field=status)"
test "$STATUS" = 'active' \
  || { echo "ERROR: plugin is not active after installing the artifact (status: $STATUS)" >&2; exit 1; }

INSTALLED_VERSION="$(wp_t plugin list --name=okean-ai-admin --field=version)"
test "$INSTALLED_VERSION" = "$FILE_VERSION" \
  || { echo "ERROR: artifact name version $FILE_VERSION != plugin version $INSTALLED_VERSION" >&2; exit 1; }

# Boot assertions run inside WordPress after the plugin has loaded.
OAI_EXPECT_VERSION="$FILE_VERSION" wp_t eval '
$fail = static function (string $message): void { fwrite(STDERR, "FAIL: ".$message."\n"); exit(1); };
if (!defined("OAI_VERSION") || !defined("OAI_DB_VERSION")) $fail("plugin constants are not defined");
if (OAI_VERSION !== getenv("OAI_EXPECT_VERSION")) $fail("OAI_VERSION does not match the artifact version");
if ((int) OAI_DB_VERSION < 1) $fail("invalid database schema version");
foreach (["OAI_MCP","OAI_Elementor","OAI_Quota","OAI_Creation_Intent","OAI_REST","OAI_WooCommerce","OAI_Woodmart","OAI_License"] as $class) {
  if (!class_exists($class)) $fail("missing class ".$class);
}
$status = OAI_REST::status();
if (!is_array($status) || empty($status["ok"])) $fail("read-only status call failed");
echo "PASS boot: OAI_VERSION=".OAI_VERSION." OAI_DB_VERSION=".OAI_DB_VERSION."\n";
' || exit 1

PLUGIN_DIR="$SITE/wp-content/plugins/okean-ai-admin"
for forbidden in wp-ai-admin-cloud wp-ai-cloud tests dist releases; do
  test ! -e "$PLUGIN_DIR/$forbidden" || { echo "ERROR: artifact contains $forbidden" >&2; exit 1; }
done
# The verified 2.1.17 artifact intentionally excludes this historical Elementor
# QA implementation; booting without it is part of the contract.
test ! -e "$PLUGIN_DIR/includes/class-oai-elementor-schema-save.php" \
  || { echo "ERROR: artifact unexpectedly contains the excluded Elementor QA file" >&2; exit 1; }
FORBIDDEN_FILE="$(find "$PLUGIN_DIR" -type f \( -name '*.zip' -o -name '*.sql' -o -name '*.sqlite' -o -name '*.env' -o -name '*.pem' -o -name '*.key' \) -print -quit)"
test -z "$FORBIDDEN_FILE" \
  || { echo "ERROR: artifact contains a forbidden file: $FORBIDDEN_FILE" >&2; exit 1; }

wp_t plugin deactivate okean-ai-admin
wp_t plugin activate okean-ai-admin
STATUS="$(wp_t plugin list --name=okean-ai-admin --field=status)"
test "$STATUS" = 'active' \
  || { echo "ERROR: plugin did not reactivate cleanly (status: $STATUS)" >&2; exit 1; }

echo "PASS artifact install: wp-ai-admin-$FILE_VERSION.zip activated, booted and reactivated on WordPress ${WP_TEST_VERSION:-latest}"
