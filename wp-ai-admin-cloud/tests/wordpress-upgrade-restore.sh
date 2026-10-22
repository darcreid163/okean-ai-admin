#!/usr/bin/env bash
# Disposable WordPress only: exact historical Cloud source -> candidate ZIP -> restore.
set -euo pipefail
umask 077
[[ "${OAI_DISPOSABLE_WP_TEST:-}" == 1 ]] || { echo 'Disposable WordPress opt-in required' >&2; exit 1; }
[[ $# == 3 ]] || { echo 'Usage: wordpress-upgrade-restore.sh SITE WP_CLI_PHAR CANDIDATE_ZIP' >&2; exit 1; }
SITE="$(realpath "$1")"
CLI="$(realpath "$2")"
ZIP="$(realpath "$3")"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
BASELINE=12c2852a973eba7988debc758e66159033abcbc8
wp_cloud_upgrade() { php "$CLI" --path="$SITE" --allow-root "$@"; }
case "$(wp_cloud_upgrade config get DB_NAME)" in wpai_cloud_upgrade_test|wpai_upgrade_test) ;; *) echo 'Disposable Cloud database required' >&2; exit 1;; esac
case "$(wp_cloud_upgrade option get home)" in http://localhost:8080|http://localhost:8080/site) ;; *) echo 'Disposable loopback home required' >&2; exit 1;; esac
PLUGIN="$SITE/wp-content/plugins/wp-ai-admin-cloud"
[[ ! -e "$PLUGIN" && ! -L "$SITE/wp-content/plugins" ]] || { echo 'Fresh disposable Cloud install required' >&2; exit 1; }
export WPAI_UPGRADE_EXPECT_COMMIT="$(git -C "$ROOT" rev-parse HEAD)"
php -r '$p=json_decode(file_get_contents(dirname($argv[1])."/PROVENANCE.json"),true,512,JSON_THROW_ON_ERROR); if($p["source_commit"]!==getenv("WPAI_UPGRADE_EXPECT_COMMIT") || $p["archive_sha256"]!==hash_file("sha256",$argv[1]))throw new RuntimeException("Candidate provenance mismatch");' "$ZIP"
BACKUP="$(mktemp -d "${RUNNER_TEMP:-/tmp}/wpai-cloud-upgrade.XXXXXX")"
trap 'rm -rf "$BACKUP"' EXIT
export WPAI_CLOUD_UPGRADE_SNAPSHOT="$BACKUP/expected.json"
wp_cloud_upgrade db export "$BACKUP/pristine.sql" --single-transaction
CONFIG_HASH="$(sha256sum "$SITE/wp-config.php")"
if ! git -C "$ROOT" cat-file -e "$BASELINE^{commit}" 2>/dev/null; then
  git -C "$ROOT" fetch --no-tags --depth=1 origin "$BASELINE"
fi
mkdir -p "$BACKUP/historical" "$PLUGIN"
git -C "$ROOT" archive "$BASELINE" wp-ai-admin-cloud | tar -x -C "$BACKUP/historical"
(cd "$BACKUP/historical/wp-ai-admin-cloud" && sha256sum -c "$ROOT/wp-ai-admin-cloud/docs/RECOVERED-CLOUD-RUNTIME.sha256")
# Install only the 20 verified historical runtime/config files.
while read -r digest name; do
  mkdir -p "$PLUGIN/$(dirname "$name")"
  cp "$BACKUP/historical/wp-ai-admin-cloud/$name" "$PLUGIN/$name"
done < "$ROOT/wp-ai-admin-cloud/docs/RECOVERED-CLOUD-RUNTIME.sha256"
wp_cloud_upgrade plugin activate wp-ai-admin-cloud
WPAI_CLOUD_UPGRADE_PHASE=seed wp_cloud_upgrade eval-file "$ROOT/wp-ai-admin-cloud/tests/wordpress-upgrade-restore.php"
wp_cloud_upgrade db export "$BACKUP/before.sql" --single-transaction
install_candidate() {
  rm -rf "$PLUGIN"
  unzip -q "$ZIP" -d "$SITE/wp-content/plugins"
  php "$ROOT/wp-ai-admin-cloud/tests/artifact-allowlist.php" "$PLUGIN"
}
install_candidate
WPAI_CLOUD_UPGRADE_PHASE=upgraded wp_cloud_upgrade eval-file "$ROOT/wp-ai-admin-cloud/tests/wordpress-upgrade-restore.php"
WPAI_CLOUD_UPGRADE_PHASE=repeat wp_cloud_upgrade eval-file "$ROOT/wp-ai-admin-cloud/tests/wordpress-upgrade-restore.php"
rm -rf "$PLUGIN"
mkdir -p "$PLUGIN"
while read -r digest name; do
  mkdir -p "$PLUGIN/$(dirname "$name")"
  cp "$BACKUP/historical/wp-ai-admin-cloud/$name" "$PLUGIN/$name"
done < "$ROOT/wp-ai-admin-cloud/docs/RECOVERED-CLOUD-RUNTIME.sha256"
wp_cloud_upgrade db reset --yes
wp_cloud_upgrade db import "$BACKUP/before.sql"
[[ "$(sha256sum "$SITE/wp-config.php")" == "$CONFIG_HASH" ]] || { echo 'WordPress configuration changed' >&2; exit 1; }
WPAI_CLOUD_UPGRADE_PHASE=restored wp_cloud_upgrade eval-file "$ROOT/wp-ai-admin-cloud/tests/wordpress-upgrade-restore.php"
install_candidate
WPAI_CLOUD_UPGRADE_PHASE=upgraded-again wp_cloud_upgrade eval-file "$ROOT/wp-ai-admin-cloud/tests/wordpress-upgrade-restore.php"
rm -rf "$PLUGIN"
wp_cloud_upgrade db reset --yes
wp_cloud_upgrade db import "$BACKUP/pristine.sql"
[[ "$(sha256sum "$SITE/wp-config.php")" == "$CONFIG_HASH" ]] || { echo 'WordPress configuration changed' >&2; exit 1; }
echo 'PASS: Cloud historical 0.3.11 -> candidate ZIP, repeat, full code+DB restore, re-upgrade and pristine cleanup'
