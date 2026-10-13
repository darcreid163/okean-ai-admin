#!/usr/bin/env python3
"""Fail closed unless the Cloud ZIP exactly matches tracked plugin bytes.

Run from clean checkout of intended release commit:
  python3 scripts/verify-cloud-zip-parity.py path/to/wp-ai-admin-cloud.zip
"""
import hashlib
import pathlib
import subprocess
import sys
import zipfile

ROOT = pathlib.Path(__file__).resolve().parents[1]
PREFIX = "wp-ai-admin-cloud/"
if len(sys.argv) != 2:
    sys.exit("usage: verify-cloud-zip-parity.py ARCHIVE.zip")
archive = pathlib.Path(sys.argv[1])
tracked = subprocess.check_output(
    ["git", "-C", str(ROOT), "ls-files", "-z", "--", "wp-ai-admin-cloud"],
).split(b"\0")
expected = {p.decode() for p in tracked if p and p.decode().startswith(PREFIX)}
if not expected:
    sys.exit("FAIL: no tracked Cloud Core sources in Git checkout")
with zipfile.ZipFile(archive) as z:
    members = [info for info in z.infolist() if not info.is_dir()]
    actual = [info.filename for info in members]
    if any(not name.startswith(PREFIX) or ".." in pathlib.PurePosixPath(name).parts for name in actual):
        sys.exit("FAIL: unexpected or unsafe archive path")
    if len(actual) != len(set(actual)):
        sys.exit("FAIL: duplicate archive members")
    if set(actual) != expected:
        print("Missing:", sorted(expected - set(actual)))
        print("Unexpected:", sorted(set(actual) - expected))
        sys.exit("FAIL: archive contents differ from Git tracked files")
    errors = []
    for name in sorted(expected):
        disk = (ROOT / name).read_bytes()
        zipped = z.read(name)
        if disk != zipped:
            errors.append(name)
    if errors:
        sys.exit("FAIL: byte mismatch in " + ", ".join(errors))
print("PASS: Cloud Core archive matches tracked Git source byte-for-byte")
print("Archive SHA-256:", hashlib.sha256(archive.read_bytes()).hexdigest())
