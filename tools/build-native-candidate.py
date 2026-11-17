#!/usr/bin/env python3
"""Build and verify deterministic Native ZIPs with source-bound provenance."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import stat
import subprocess
import zipfile

ROOTS = [
    "okean-ai-admin.php", "includes", "starter-themes", "README.md",
    "README.txt", "LICENSE", "THIRD-PARTY.md", "BUILD-SIGNATURE.txt",
    "BUILD_SIGNATURE.txt",
]
EXCLUDED = {"includes/class-oai-elementor-schema-save.php"}
PREFIX = "okean-ai-admin/"
FORBIDDEN_SUFFIXES = (".zip", ".sql", ".sqlite", ".pem", ".p12", ".key", ".env", ".bak")
SECRET_PATTERNS = [
    re.compile(rb"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(rb"(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{35,}"),
    re.compile(rb"(?:sk_live|rk_live)_[A-Za-z0-9]{12,}"),
    re.compile(rb"github_pat_[A-Za-z0-9_]{20,}"),
    re.compile(rb"WP_AI_CLOUD_(?:VAULT|SIGNING)_KEY\s*[:=]\s*['\"]?[A-Za-z0-9+/=]{16,}"),
]


def git(repo: Path, *args: str) -> bytes:
    return subprocess.check_output(["git", "-C", str(repo), *args])


def source_metadata(files: dict[str, bytes]) -> tuple[str, str, str, str]:
    plugin = files["okean-ai-admin.php"].decode("utf-8")
    def constant(name: str) -> str:
        match = re.search(r"define\('" + re.escape(name) + r"','([^']+)'\)", plugin)
        if not match:
            raise ValueError("Missing source constant: " + name)
        return match.group(1)
    version = constant("OAI_VERSION")
    build_id = constant("OAI_BUILD_ID")
    channel = constant("OAI_RELEASE_CHANNEL")
    profile = constant("OAI_RELEASE_PROFILE")
    if not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", version):
        raise ValueError("Native version is not semantic")
    if not re.search(r"^\s*\* Version: " + re.escape(version) + r"\s*$", plugin, re.M):
        raise ValueError("Plugin header version does not match OAI_VERSION")
    if files["BUILD-SIGNATURE.txt"] != files["BUILD_SIGNATURE.txt"]:
        raise ValueError("Build metadata aliases differ")
    metadata = files["BUILD-SIGNATURE.txt"].decode("utf-8")
    required = [
        "WP AI Admin Build Metadata (not a cryptographic signature)",
        "Version: " + version,
        "Build ID: " + build_id,
        "Release channel: " + channel,
        "Release profile: " + profile,
    ]
    if any(line not in metadata.splitlines() for line in required):
        raise ValueError("Build metadata does not match source constants")
    return version, build_id, channel, profile


def committed_files(repo: Path, commit: str) -> tuple[dict[str, bytes], dict[str, int]]:
    status = git(repo, "status", "--porcelain", "--untracked-files=all", "--", *ROOTS).decode()
    if status.strip():
        raise ValueError("Native artifact source paths are dirty or contain untracked files")
    entries = git(repo, "ls-tree", "-rz", "--full-tree", commit, "--", *ROOTS)
    files: dict[str, bytes] = {}
    modes: dict[str, int] = {}
    for entry in entries.split(b"\0"):
        if not entry:
            continue
        meta, raw_path = entry.split(b"\t", 1)
        mode, kind, oid = meta.split()
        path = raw_path.decode("utf-8")
        if path in EXCLUDED:
            continue
        if mode not in (b"100644", b"100755") or kind != b"blob":
            raise ValueError("Non-regular Native artifact entry: " + path)
        if path.lower().endswith(FORBIDDEN_SUFFIXES):
            raise ValueError("Forbidden file in Native artifact: " + path)
        data = git(repo, "cat-file", "blob", oid.decode())
        if any(pattern.search(data) for pattern in SECRET_PATTERNS):
            raise ValueError("Potential secret material in Native artifact: " + path)
        files[path] = data
        modes[path] = 0o755 if mode == b"100755" else 0o644
    required = set(ROOTS) - {"includes", "starter-themes"}
    missing = required - files.keys()
    if missing:
        raise ValueError("Missing Native artifact files: " + ", ".join(sorted(missing)))
    if not any(path.startswith("includes/") for path in files):
        raise ValueError("Native includes directory is empty")
    if not any(path.startswith("starter-themes/") for path in files):
        raise ValueError("Native starter-themes directory is empty")
    return files, modes


def build(repo: Path, output: Path) -> dict:
    repo = repo.resolve()
    output = output.absolute()
    commit = git(repo, "rev-parse", "HEAD").decode().strip()
    tree = git(repo, "rev-parse", "HEAD^{tree}").decode().strip()
    epoch = int(git(repo, "show", "-s", "--format=%ct", commit))
    timestamp = datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc)
    if not 1980 <= timestamp.year <= 2107:
        raise ValueError("Commit timestamp outside ZIP date range")
    date = timestamp.timetuple()[:5] + (timestamp.second // 2 * 2,)
    files, modes = committed_files(repo, commit)
    version, build_id, channel, profile = source_metadata(files)
    output.mkdir(parents=True, exist_ok=False)
    filename = f"wp-ai-admin-{version}.zip"
    archive = output / filename
    with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_STORED) as package:
        for path, data in sorted(files.items()):
            info = zipfile.ZipInfo(PREFIX + path, date_time=date)
            info.create_system = 3
            info.external_attr = (stat.S_IFREG | modes[path]) << 16
            info.compress_type = zipfile.ZIP_STORED
            package.writestr(info, data)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (output / (filename + ".sha256")).write_text(f"{digest}  {filename}\n", encoding="utf-8")
    provenance = {
        "schema_version": 1,
        "artifact_kind": "native-wordpress-plugin",
        "source_commit": commit,
        "source_tree": tree,
        "source_date_epoch": epoch,
        "version": version,
        "build_id": build_id,
        "release_channel": channel,
        "release_profile": profile,
        "archive": filename,
        "archive_sha256": digest,
        "zip_format": "stored; sorted paths; UTC commit time rounded to two seconds; git file modes",
        "excluded_paths": sorted(EXCLUDED),
        "files": {
            PREFIX + path: {
                "sha256": hashlib.sha256(data).hexdigest(),
                "mode": f"{modes[path]:04o}",
            }
            for path, data in sorted(files.items())
        },
    }
    (output / "PROVENANCE.json").write_text(json.dumps(provenance, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    return provenance


def verify(repo: Path, archive: Path, provenance_path: Path, checksum_path: Path) -> dict:
    repo = repo.resolve()
    provenance = json.loads(provenance_path.read_text(encoding="utf-8"))
    commit = git(repo, "rev-parse", "HEAD").decode().strip()
    tree = git(repo, "rev-parse", "HEAD^{tree}").decode().strip()
    if provenance.get("source_commit") != commit or provenance.get("source_tree") != tree:
        raise ValueError("Provenance source commit/tree does not match checkout")
    if provenance.get("archive") != archive.name:
        raise ValueError("Provenance archive name mismatch")
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    if provenance.get("archive_sha256") != digest:
        raise ValueError("Provenance archive SHA-256 mismatch")
    expected_checksum = f"{digest}  {archive.name}\n"
    if checksum_path.read_text(encoding="utf-8") != expected_checksum:
        raise ValueError("SHA-256 sidecar mismatch")
    expected_files = provenance.get("files")
    if not isinstance(expected_files, dict) or not expected_files:
        raise ValueError("Provenance file manifest missing")
    with zipfile.ZipFile(archive, "r") as package:
        names = package.namelist()
        if len(names) != len(set(names)) or set(names) != set(expected_files):
            raise ValueError("ZIP entries do not match provenance manifest")
        if names != sorted(names):
            raise ValueError("ZIP entries are not in deterministic sorted order")
        if package.comment:
            raise ValueError("Unexpected ZIP archive comment")
        if provenance.get("schema_version") != 1 or provenance.get("artifact_kind") != "native-wordpress-plugin":
            raise ValueError("Unsupported Native provenance schema")
        epoch = int(provenance.get("source_date_epoch", 0))
        timestamp = datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc)
        expected_date = timestamp.timetuple()[:5] + (timestamp.second // 2 * 2,)
        for name in names:
            path = PurePosixPath(name)
            if path.is_absolute() or ".." in path.parts or not name.startswith(PREFIX):
                raise ValueError("Unsafe ZIP path: " + name)
            info = package.getinfo(name)
            zip_mode = info.external_attr >> 16
            entry = expected_files[name]
            if stat.S_IFMT(zip_mode) != stat.S_IFREG:
                raise ValueError("Non-regular ZIP entry: " + name)
            if stat.S_IMODE(zip_mode) != int(entry.get("mode", "0"), 8):
                raise ValueError("ZIP mode mismatch: " + name)
            if (info.create_system != 3 or info.extra or info.comment or
                    info.compress_type != zipfile.ZIP_STORED or info.date_time != expected_date):
                raise ValueError("Non-deterministic ZIP metadata: " + name)
            data = package.read(name)
            if hashlib.sha256(data).hexdigest() != entry.get("sha256"):
                raise ValueError("File digest mismatch: " + name)
    files, modes = committed_files(repo, commit)
    version, build_id, channel, profile = source_metadata(files)
    for key, value in (
        ("version", version), ("build_id", build_id),
        ("release_channel", channel), ("release_profile", profile),
    ):
        if provenance.get(key) != value:
            raise ValueError("Provenance " + key + " does not match source")
    if set(expected_files) != {PREFIX + path for path in files}:
        raise ValueError("Provenance file set does not match committed Native source")
    for path, data in files.items():
        entry = expected_files[PREFIX + path]
        if entry.get("sha256") != hashlib.sha256(data).hexdigest():
            raise ValueError("Provenance source digest mismatch: " + path)
        if entry.get("mode") != f"{modes[path]:04o}":
            raise ValueError("Provenance source mode mismatch: " + path)
    return provenance


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    build_parser = sub.add_parser("build")
    build_parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[1])
    build_parser.add_argument("--output", required=True, type=Path)
    verify_parser = sub.add_parser("verify")
    verify_parser.add_argument("--repo", type=Path, default=Path(__file__).resolve().parents[1])
    verify_parser.add_argument("--archive", required=True, type=Path)
    verify_parser.add_argument("--provenance", required=True, type=Path)
    verify_parser.add_argument("--checksum", required=True, type=Path)
    args = parser.parse_args()
    if args.command == "build":
        result = build(args.repo, args.output)
        print(f"PASS Native artifact {result['source_commit']}: {result['archive_sha256']}")
    else:
        result = verify(args.repo, args.archive, args.provenance, args.checksum)
        print(f"PASS Native provenance {result['source_commit']}: {result['archive_sha256']}")


if __name__ == "__main__":
    main()
