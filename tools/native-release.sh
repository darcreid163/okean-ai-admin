#!/usr/bin/env bash
# Only an explicit matching version tag may publish; never replace release bytes.
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"
version="$(sed -n "s/^define('OAI_VERSION','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
build_id="$(sed -n "s/^define('OAI_BUILD_ID','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
release_channel="$(sed -n "s/^define('OAI_RELEASE_CHANNEL','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
release_profile="$(sed -n "s/^define('OAI_RELEASE_PROFILE','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
tag="${GITHUB_REF_NAME:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
   [[ -z "$build_id" || -z "$release_channel" || -z "$release_profile" ]] ||
   [[ "$tag" != "v$version" ]] || [[ "${GITHUB_REF:-}" != "refs/tags/$tag" ]] ||
   ! grep -Fxq " * Version: $version" okean-ai-admin.php; then
    echo 'Release requires an explicit tag matching Native constant and plugin header.' >&2
    exit 1
fi
for metadata in BUILD-SIGNATURE.txt BUILD_SIGNATURE.txt; do
    test -f "$metadata"
    grep -Fxq 'WP AI Admin Build Metadata (not a cryptographic signature)' "$metadata"
    grep -Fxq "Version: $version" "$metadata"
    grep -Fxq "Build ID: $build_id" "$metadata"
    grep -Fxq "Release channel: $release_channel" "$metadata"
    grep -Fxq "Release profile: $release_profile" "$metadata"
done
cmp -s BUILD-SIGNATURE.txt BUILD_SIGNATURE.txt || {
    echo 'Native build metadata aliases do not match.' >&2
    exit 1
}
case "${1:-}" in
    validate) echo "PASS Native tag/header version: $tag" ;;
    publish)
        test -n "${GITHUB_REPOSITORY:-}"
        archive="wp-ai-admin-$version.zip"
        checksum="$archive.sha256"
        test -f "dist/$archive"
        test -f "dist/$checksum"
        test -f "dist/PROVENANCE.json"
        (cd dist && sha256sum -c "$checksum" && unzip -tq "$archive")
        # gh refuses creation if the release already exists. There is no update,
        # upload --clobber, or reuse of a previous release on retry.
        gh release create "$tag" "dist/$archive" "dist/$checksum" "dist/PROVENANCE.json" \
            --repo "$GITHUB_REPOSITORY" --verify-tag \
            --title "WP AI Admin $version" \
            --notes 'Installable ZIP, SHA-256 checksum and source-bound provenance manifest. The provenance is not a cryptographic publisher signature. See MIGRATION-STATUS.md for the release gates.'
        ;;
    *) echo 'Usage: native-release.sh validate|publish' >&2; exit 2 ;;
esac
