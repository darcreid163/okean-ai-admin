#!/usr/bin/env python3
"""Export committed Cloud runtime with deterministic ZIP metadata and provenance."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess
import tempfile
import zipfile


def git(repo, *args):
    return subprocess.check_output(["git", "-C", str(repo), *args])


def build(repo, output, php="php"):
    repo = Path(repo).resolve()
    output = Path(output).absolute()
    prefix = "wp-ai-admin-cloud/"
    commit = git(repo, "rev-parse", "HEAD").decode().strip()
    tree = git(repo, "rev-parse", "HEAD^{tree}").decode().strip()
    epoch = int(git(repo, "show", "-s", "--format=%ct", commit))
    timestamp = datetime.datetime.fromtimestamp(epoch, datetime.timezone.utc)
    if not 1980 <= timestamp.year <= 2107:
        raise ValueError("Commit timestamp outside ZIP range")
    date = timestamp.timetuple()[:5] + (timestamp.second // 2 * 2,)
    roots = [prefix + part for part in ("wp-ai-admin-cloud.php", "src", "includes", "config")]
    entries = git(repo, "ls-tree", "-rz", "--full-tree", commit, "--", *roots)
    files = {}
    for entry in entries.split(b"\0"):
        if not entry:
            continue
        meta, path = entry.split(b"\t", 1)
        mode, kind, oid = meta.split()
        relative = path.decode()[len(prefix):]
        if mode not in (b"100644", b"100755") or kind != b"blob":
            raise ValueError("Non-regular committed runtime entry: " + relative)
        files[relative] = git(repo, "cat-file", "blob", oid.decode())
    bootstrap = files["wp-ai-admin-cloud.php"].decode()
    version = re.search(r"define\('WPAI_CLOUD_VERSION', '([0-9]+\.[0-9]+\.[0-9]+-dev)'\);", bootstrap)
    if not version or not re.search(r"^ \* Version: " + re.escape(version[1]) + r"\s*$", bootstrap, re.M):
        raise ValueError("Expected matching development-candidate version/header")
    # Check committed payload against the checkout too: local runtime edits and
    # untracked payload additions must not produce misleading provenance.
    with tempfile.TemporaryDirectory(prefix="wpai-cloud-stage-") as temporary:
        stage = Path(temporary)
        for name, data in files.items():
            target = stage / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
        validation = subprocess.run([php, str(repo / prefix / "tests/artifact-allowlist.php"), str(stage)], check=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        print(validation.stdout, end="")
        # Fresh output only. Do not overwrite a previous candidate or checksum.
        output.mkdir(parents=True, exist_ok=False)
        filename = f"wp-ai-admin-cloud-{version[1]}.zip"
        archive = output / filename
        with zipfile.ZipFile(archive, "x", compression=zipfile.ZIP_STORED) as package:
            for name, data in sorted(files.items()):
                info = zipfile.ZipInfo(prefix + name, date_time=date)
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                # Stored ZIP avoids compressor-version-dependent output.
                package.writestr(info, data)
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        (output / (filename + ".sha256")).write_text(f"{digest}  {filename}\n", encoding="utf-8")
        provenance = {
            "schema_version": 1,
            "candidate_only": True,
            "source_commit": commit,
            "source_tree": tree,
            "source_date_epoch": epoch,
            "version": version[1],
            "archive": filename,
            "archive_sha256": digest,
            "zip_format": "stored; sorted paths; UTC commit time rounded to two seconds; regular 0644",
            "files": {prefix + name: hashlib.sha256(data).hexdigest() for name, data in sorted(files.items())},
        }
        (output / "PROVENANCE.json").write_text(json.dumps(provenance, sort_keys=True, indent=2) + "\n", encoding="utf-8")
    return provenance


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path, help="New output directory; must not already exist")
    args = parser.parse_args()
    result = build(Path(__file__).resolve().parents[2], args.output)
    print(f"PASS committed Cloud candidate {result['source_commit']}: {result['archive_sha256']}")
