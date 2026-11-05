#!/usr/bin/env bash
# Manual GitHub Actions acceptance lab for the Native plugin.
#
# This is an on-demand runner for focused WordPress regressions. It is not a
# substitute for the full smoke matrix or for acceptance on a production-like
# stand. It exists so individual suites can be exercised through GitHub Actions
# with a real WordPress + MySQL service instead of a local database.
#
# Safety: disposable localhost site only, no publishing, no production data,
# no credentials in logs. The optional Woodmart source is read from a repository
# secret, hash-pinned, used from a temporary directory and never printed.
set -euo pipefail
: "${OAI_LAB_TESTS:?OAI_LAB_TESTS is required (comma-separated tests/wordpress-*.php)}"
: "${GITHUB_WORKSPACE:=$(pwd)}"
: "${RUNNER_TEMP:=/tmp}"

WORK="$RUNNER_TEMP/wpai-native-lab"
SITE="$WORK/site"
mkdir -p "$SITE"

IFS=',' read -r -a RAW_TESTS <<< "$OAI_LAB_TESTS"
TESTS=()
for raw in "${RAW_TESTS[@]}"; do
  t="$(printf '%s' "$raw" | tr -d '[:space:]')"
  [[ -z "$t" ]] && continue
  if [[ ! "$t" =~ ^tests/wordpress-[a-z0-9-]+\.php$ ]]; then
    echo "ERROR: invalid test path (expected tests/wordpress-*.php): $t"
    exit 2
  fi
  if [[ ! -f "$GITHUB_WORKSPACE/$t" ]]; then
    echo "ERROR: missing test file: $t"
    exit 2
  fi
  TESTS+=("$t")
done
if [[ ${#TESTS[@]} -eq 0 ]]; then
  echo "ERROR: no tests selected"
  exit 2
fi

curl -fsSL --retry 3 https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o "$WORK/wp-cli.phar"
wp_lab() { php "$WORK/wp-cli.phar" --path="$SITE" --allow-root "$@"; }

wp_lab core download --version="${WP_TEST_VERSION:-latest}"
wp_lab config create --dbname=wp_test --dbuser=root --dbpass=test-password --dbhost=127.0.0.1
wp_lab core install --url=http://localhost:8080 --title="WP AI Lab" --admin_user=ci-admin --admin_password=test-only-ci-password --admin_email=ci@example.invalid --skip-email

install_ci_plugin() {
  local plugin="$1" version="$2"
  if [[ "$version" == "latest" ]]; then
    wp_lab plugin install "$plugin" --activate
  else
    wp_lab plugin install "$plugin" --version="$version" --activate
  fi
}
install_ci_plugin elementor "${ELEMENTOR_TEST_VERSION:-latest}"
install_ci_plugin woocommerce "${WOO_TEST_VERSION:-latest}"

mkdir -p "$SITE/wp-content/plugins/okean-ai-admin"
cp -R "$GITHUB_WORKSPACE/okean-ai-admin.php" "$GITHUB_WORKSPACE/includes" "$GITHUB_WORKSPACE/starter-themes" "$SITE/wp-content/plugins/okean-ai-admin/"
wp_lab plugin activate okean-ai-admin

LAB_THEME="${OAI_LAB_THEME:-none}"
if [[ "$LAB_THEME" != "none" ]]; then
  wp_lab theme install "$LAB_THEME" || true
  wp_lab theme activate "$LAB_THEME"
fi

NEEDS_WOODMART=0
for t in "${TESTS[@]}"; do
  [[ "$t" == "tests/wordpress-woodmart.php" ]] && NEEDS_WOODMART=1
done
if [[ "$NEEDS_WOODMART" == "1" ]]; then
  if [[ "${OAI_LAB_WOODMART:-0}" != "1" ]]; then
    echo "ERROR: tests/wordpress-woodmart.php requires OAI_LAB_WOODMART=1 and the WOODMART_MCP_ZIP_BASE64 repository secret"
    exit 2
  fi
  : "${WOODMART_MCP_ZIP_BASE64:?WOODMART_MCP_ZIP_BASE64 secret is required for the Woodmart suite}"
  WOODMART_ZIP="$WORK/woodmart-mcp-1.0.1.zip"
  mkdir -p "$WORK/woodmart" "$SITE/wp-content/mu-plugins"
  printf '%s' "$WOODMART_MCP_ZIP_BASE64" | base64 -d > "$WOODMART_ZIP"
  echo "d9d8a8ac07cc189488cb30356acfec1d7b885312faab741ed40fa0bf6c640615  $WOODMART_ZIP" | sha256sum -c -
  unzip -q "$WOODMART_ZIP" -d "$WORK/woodmart"
  rm -f "$WOODMART_ZIP"
  export OAI_WOODMART_NATIVE_DIR="$WORK/woodmart/woodmart-mcp"
  cp "$GITHUB_WORKSPACE/tests/woodmart-native-fixture.php" "$SITE/wp-content/mu-plugins/woodmart-native-fixture.php"
fi

for t in "${TESTS[@]}"; do
  if [[ "$t" == "tests/wordpress-woodmart.php" ]]; then
    for phase in plan apply rollback verify; do
      echo "=== $t ($phase) ==="
      OAI_WOODMART_PHASE="$phase" OAI_DISPOSABLE_WP_TEST=1 wp_lab eval-file "$GITHUB_WORKSPACE/$t"
    done
  else
    echo "=== $t ==="
    OAI_DISPOSABLE_WP_TEST=1 wp_lab eval-file "$GITHUB_WORKSPACE/$t"
  fi
done

echo "PASS lab: ${#TESTS[@]} suite(s) completed on WordPress ${WP_TEST_VERSION:-latest}, Elementor ${ELEMENTOR_TEST_VERSION:-latest}, WooCommerce ${WOO_TEST_VERSION:-latest}, theme ${LAB_THEME}"
