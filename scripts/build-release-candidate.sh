#!/usr/bin/env bash
set -euo pipefail
export TZ=UTC

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

NATIVE_OUT="${NATIVE_OUT:-dist}"
CLOUD_OUT="${CLOUD_OUT:-cloud-dist}"
SOURCE_COMMIT="${GITHUB_SHA:-$(git rev-parse HEAD)}"
SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git show -s --format=%ct "$SOURCE_COMMIT")}"

NATIVE_VERSION="$(sed -n "s/^define('OAI_VERSION','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
CLOUD_VERSION="$(sed -n "s/^define('WPAI_CLOUD_VERSION', '\([^']*\)').*/\1/p" wp-ai-admin-cloud/wp-ai-admin-cloud.php | head -n1)"
BUILD_ID="$(sed -n "s/^define('OAI_BUILD_ID','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"

test -n "$NATIVE_VERSION"
test -n "$CLOUD_VERSION"
test -n "$BUILD_ID"
test -n "$SOURCE_DATE_EPOCH"

rm -rf "$NATIVE_OUT" "$CLOUD_OUT"
mkdir -p "$NATIVE_OUT/okean-ai-admin" "$CLOUD_OUT/wp-ai-admin-cloud"

cp -a okean-ai-admin.php includes starter-themes README.md README.txt BUILD-SIGNATURE.txt BUILD_SIGNATURE.txt "$NATIVE_OUT/okean-ai-admin/"
cp -a wp-ai-admin-cloud/wp-ai-admin-cloud.php wp-ai-admin-cloud/includes wp-ai-admin-cloud/src wp-ai-admin-cloud/config wp-ai-admin-cloud/README.md "$CLOUD_OUT/wp-ai-admin-cloud/"

find "$NATIVE_OUT/okean-ai-admin" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +
find "$CLOUD_OUT/wp-ai-admin-cloud" -exec touch -d "@$SOURCE_DATE_EPOCH" {} +

(
  cd "$NATIVE_OUT"
  find okean-ai-admin -type f -print | LC_ALL=C sort | zip -X -q "wp-ai-admin-$NATIVE_VERSION.zip" -@
)
(
  cd "$CLOUD_OUT"
  find wp-ai-admin-cloud -type f -print | LC_ALL=C sort | zip -X -q "wp-ai-admin-cloud-$CLOUD_VERSION.zip" -@
)

NATIVE_ZIP="$NATIVE_OUT/wp-ai-admin-$NATIVE_VERSION.zip"
CLOUD_ZIP="$CLOUD_OUT/wp-ai-admin-cloud-$CLOUD_VERSION.zip"
unzip -tq "$NATIVE_ZIP"
unzip -tq "$CLOUD_ZIP"

NATIVE_SHA="$(sha256sum "$NATIVE_ZIP" | awk '{print $1}')"
CLOUD_SHA="$(sha256sum "$CLOUD_ZIP" | awk '{print $1}')"

cat > "$NATIVE_OUT/PROVENANCE.txt" <<EOF
commit=$SOURCE_COMMIT
source_date_epoch=$SOURCE_DATE_EPOCH
version=$NATIVE_VERSION
build_id=$BUILD_ID
sha256=$NATIVE_SHA
reproducible=true
cloud_version=$CLOUD_VERSION
cloud_sha256=$CLOUD_SHA
cloud_reproducible=true
EOF

printf 'native_zip=%s\nnative_sha256=%s\ncloud_zip=%s\ncloud_sha256=%s\n' "$NATIVE_ZIP" "$NATIVE_SHA" "$CLOUD_ZIP" "$CLOUD_SHA"
