#!/usr/bin/env bash
# Real historical-code upgrade and paired code/database restore. Disposable CI only.
set -euo pipefail
umask 077
[[ "${OAI_DISPOSABLE_WP_TEST:-}" == 1 ]] || { echo 'Disposable WordPress opt-in required' >&2; exit 1; }
[[ $# == 2 ]] || { echo 'Usage: wordpress-native-upgrade-restore.sh SITE WP_CLI_PHAR' >&2; exit 1; }
SITE="$(realpath "$1")"
CLI="$(realpath "$2")"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BASELINE=f7f02032669a38741dac4e29002ff246994f1a02
wp_upgrade() { php "$CLI" --path="$SITE" --allow-root "$@"; }
# Never reset a customer database. Both a disposable DB name and loopback URL
# are mandatory, in addition to the explicit opt-in above.
case "$(wp_upgrade config get DB_NAME)" in wp_test|wpai_upgrade_test) ;; *) echo 'Disposable database name required' >&2; exit 1;; esac
case "$(wp_upgrade option get home)" in http://localhost:8080|http://localhost:8080/site) ;; *) echo 'Disposable loopback home required' >&2; exit 1;; esac
PLUGIN="$SITE/wp-content/plugins/okean-ai-admin"
[[ ! -e "$PLUGIN" && ! -L "$SITE/wp-content/plugins" ]] || { echo 'Fresh disposable Native install required' >&2; exit 1; }
BACKUP="$(mktemp -d "${RUNNER_TEMP:-/tmp}/wpai-upgrade-restore.XXXXXX")"
trap 'rm -rf "$BACKUP"' EXIT
export WPAI_UPGRADE_SNAPSHOT="$BACKUP/expected.json"
if ! git -C "$ROOT" cat-file -e "$BASELINE^{commit}" 2>/dev/null; then
  git -C "$ROOT" fetch --no-tags --depth=1 origin "$BASELINE"
fi
mkdir -p "$BACKUP/native" "$PLUGIN"
git -C "$ROOT" archive "$BASELINE" okean-ai-admin.php includes starter-themes | tar -x -C "$BACKUP/native"
cp -a "$BACKUP/native/." "$PLUGIN/"
wp_upgrade plugin activate okean-ai-admin
WPAI_UPGRADE_PHASE=seed wp_upgrade eval-file "$ROOT/tests/wordpress-native-upgrade-restore.php"
wp_upgrade db export "$BACKUP/before.sql" --single-transaction
CONFIG_HASH="$(sha256sum "$SITE/wp-config.php")"
install_candidate() {
  rm -rf "$PLUGIN"
  mkdir -p "$PLUGIN"
  cp -a "$ROOT/okean-ai-admin.php" "$ROOT/includes" "$ROOT/starter-themes" "$PLUGIN/"
}
install_candidate
WPAI_UPGRADE_PHASE=upgraded wp_upgrade eval-file "$ROOT/tests/wordpress-native-upgrade-restore.php"
WPAI_UPGRADE_PHASE=repeat wp_upgrade eval-file "$ROOT/tests/wordpress-native-upgrade-restore.php"
# A complete backup restore is tested here; a files-only downgrade is not claimed.
rm -rf "$PLUGIN"
mkdir -p "$PLUGIN"
cp -a "$BACKUP/native/." "$PLUGIN/"
wp_upgrade db reset --yes
wp_upgrade db import "$BACKUP/before.sql"
[[ "$(sha256sum "$SITE/wp-config.php")" == "$CONFIG_HASH" ]] || { echo 'WordPress configuration changed' >&2; exit 1; }
WPAI_UPGRADE_PHASE=restored wp_upgrade eval-file "$ROOT/tests/wordpress-native-upgrade-restore.php"
# Leave the disposable site on the candidate for the remaining smoke gates.
install_candidate
WPAI_UPGRADE_PHASE=upgraded-again wp_upgrade eval-file "$ROOT/tests/wordpress-native-upgrade-restore.php"
echo 'PASS: historical Native 2.1.14 -> 2.1.17, repeated boot, full code+DB restore, and re-upgrade'
