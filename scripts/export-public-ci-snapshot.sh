#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

OUT="${1:-public-ci-snapshot}"
rm -rf "$OUT"
mkdir -p "$OUT"

copy_path() {
  local src="$1"
  if [ -d "$src" ]; then
    mkdir -p "$OUT/$src"
    cp -a "$src/." "$OUT/$src/"
  elif [ -f "$src" ]; then
    mkdir -p "$OUT/$(dirname "$src")"
    cp -a "$src" "$OUT/$src"
  else
    echo "Missing allowlisted path: $src" >&2
    exit 1
  fi
}

for path in   okean-ai-admin.php   includes   starter-themes   wp-ai-admin-cloud/wp-ai-admin-cloud.php   wp-ai-admin-cloud/includes   wp-ai-admin-cloud/src   wp-ai-admin-cloud/config   wp-ai-admin-cloud/README.md   wp-ai-admin-cloud/tests   tests   scripts/build-release-candidate.sh   README.md README.txt BUILD-SIGNATURE.txt BUILD_SIGNATURE.txt   SECURITY.md CHANGELOG.md   docs/CHATGPT-CONNECTION-CONTRACT.md   docs/PUBLIC-ONBOARDING.md docs/PUBLIC-RELEASE-READINESS.md   docs/DATA-FLOW.md docs/DATA-RETENTION.md docs/PROOF-SEMANTICS.md
do
  copy_path "$path"
done

# Release-CI repository metadata.
cp public-ci-template/README.md "$OUT/README-PUBLIC-CI.md"
cp public-ci-template/ALLOWLIST.txt "$OUT/PUBLIC-CI-ALLOWLIST.txt"

# Guard against accidental sensitive/development content.
if find "$OUT" -type f \( -name '.env' -o -name '*.pem' -o -name '*.key' \) | grep -q .; then
  echo "Sensitive file type present in public CI snapshot" >&2
  exit 1
fi
if grep -RIlE --binary-files=without-match -- '-----BEGIN (RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,}|sk-[A-Za-z0-9_-]{32,}' "$OUT"; then
  echo "Credential-like material found in public CI snapshot" >&2
  exit 1
fi

COMMIT="$(git rev-parse HEAD)"
NATIVE_VERSION="$(sed -n "s/^define('OAI_VERSION','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
BUILD_ID="$(sed -n "s/^define('OAI_BUILD_ID','\([^']*\)').*/\1/p" okean-ai-admin.php | head -n1)"
CLOUD_VERSION="$(sed -n "s/^define('WPAI_CLOUD_VERSION', '\([^']*\)').*/\1/p" wp-ai-admin-cloud/wp-ai-admin-cloud.php | head -n1)"

cat > "$OUT/PUBLIC-CI-SOURCE.txt" <<EOF
private_source_commit=$COMMIT
native_version=$NATIVE_VERSION
native_build_id=$BUILD_ID
cloud_version=$CLOUD_VERSION
history_exported=false
EOF

echo "Prepared public CI snapshot at $OUT"
