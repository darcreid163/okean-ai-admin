#!/usr/bin/env bash
# Only an explicit matching version tag may publish; never replace release bytes.
set -euo pipefail
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"
version="$(sed -n "s/^define('OAI_VERSION','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
tag="${GITHUB_REF_NAME:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
   [[ "$tag" != "v$version" ]] || [[ "${GITHUB_REF:-}" != "refs/tags/$tag" ]] ||
   ! grep -Fxq " * Version: $version" okean-ai-admin.php; then
    echo 'Release requires an explicit tag matching Native constant and plugin header.' >&2
    exit 1
fi
case "${1:-}" in
    validate) echo "PASS Native tag/header version: $tag" ;;
    publish)
        test -n "${GITHUB_REPOSITORY:-}"
        archive="wp-ai-admin-$version.zip"
        checksum="$archive.sha256"
        test -f "dist/$archive"
        test -f "dist/$checksum"
        (cd dist && sha256sum -c "$checksum" && unzip -tq "$archive")
        # gh refuses creation if the release already exists. There is no update,
        # upload --clobber, or reuse of a previous release on retry.
        gh release create "$tag" "dist/$archive" "dist/$checksum" \
            --repo "$GITHUB_REPOSITORY" --verify-tag \
            --title "WP AI Admin $version" \
            --notes 'Installable ZIP and SHA-256 checksum. See MIGRATION-STATUS.md for the release gates.'
        ;;
    *) echo 'Usage: native-release.sh validate|publish' >&2; exit 2 ;;
esac
