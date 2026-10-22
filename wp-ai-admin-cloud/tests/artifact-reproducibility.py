#!/usr/bin/env python3
"""Qualify the real exporter using committed synthetic Git fixtures."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

SOURCE = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("cloud_candidate", SOURCE / "tools/build-candidate.py")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


class CandidateTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="wpai-build-test-", dir=os.environ.get("WPAI_ARTIFACT_TEST_TMP"))
        self.root = Path(self.temporary.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        cloud = self.repo / "wp-ai-admin-cloud"
        cloud.mkdir()
        for name in ("src", "includes", "config"):
            shutil.copytree(SOURCE / name, cloud / name)
        shutil.copy2(SOURCE / "wp-ai-admin-cloud.php", cloud)
        (cloud / "tests").mkdir()
        shutil.copy2(SOURCE / "tests/artifact-allowlist.php", cloud / "tests")
        self.git("init", "-q")
        self.git("config", "user.email", "fixture@example.invalid")
        self.git("config", "user.name", "Synthetic fixture")
        self.commit()

    def tearDown(self):
        self.temporary.cleanup()

    def git(self, *args):
        return subprocess.check_output(["git", "-C", str(self.repo), *args])

    def commit(self):
        self.git("add", ".")
        env = dict(os.environ, GIT_AUTHOR_DATE="2026-10-22T06:38:55Z", GIT_COMMITTER_DATE="2026-10-22T06:38:55Z")
        subprocess.run(["git", "-C", str(self.repo), "commit", "-qm", "synthetic candidate"], env=env, check=True)

    def build(self, name):
        return BUILDER.build(self.repo, self.root / name, php=os.environ.get("PHP_BINARY", "php"))

    def test_reproducible_archive_and_provenance(self):
        one = self.build("first")
        for path in (self.repo / "wp-ai-admin-cloud").rglob("*"):
            os.utime(path, (1000000000, 1000000000))
            if path.is_file():
                path.chmod(0o755)
        two = self.build("second")
        self.assertEqual(one, two)
        for path in (self.root / "first").iterdir():
            self.assertEqual(path.read_bytes(), (self.root / "second" / path.name).read_bytes())
        self.assertEqual(one["source_commit"], self.git("rev-parse", "HEAD").decode().strip())
        archive = self.root / "first" / one["archive"]
        self.assertEqual(one["archive_sha256"], hashlib.sha256(archive.read_bytes()).hexdigest())
        self.assertEqual(json.loads((self.root / "first/PROVENANCE.json").read_text()), one)
        self.assertEqual((self.root / "first" / (one["archive"] + ".sha256")).read_text(), f"{one['archive_sha256']}  {one['archive']}\n")
        with zipfile.ZipFile(archive) as package:
            self.assertIsNone(package.testzip())
            self.assertEqual(package.namelist(), sorted(one["files"]))
            for info in package.infolist():
                self.assertEqual(info.compress_type, zipfile.ZIP_STORED)
                self.assertEqual(info.date_time, (2026, 10, 22, 6, 38, 54))
                self.assertEqual(info.external_attr >> 16, 0o100644)
                self.assertEqual(hashlib.sha256(package.read(info)).hexdigest(), one["files"][info.filename])

    def test_rejects_modified_checkout(self):
        path = self.repo / "wp-ai-admin-cloud/src/Core.php"
        path.write_bytes(path.read_bytes() + b"\n// tamper\n")
        with self.assertRaises(subprocess.CalledProcessError) as error:
            self.build("modified")
        self.assertIn("source SHA-256 mismatch", error.exception.stdout)
        self.assertFalse((self.root / "modified").exists())

    def test_rejects_untracked_runtime_file(self):
        (self.repo / "wp-ai-admin-cloud/src/extra.php").write_text("<?php\n")
        with self.assertRaises(subprocess.CalledProcessError) as error:
            self.build("extra")
        self.assertIn("allowlist mismatch", error.exception.stdout)
        self.assertFalse((self.root / "extra").exists())

    def test_rejects_committed_symlink(self):
        (self.repo / "wp-ai-admin-cloud/src/linked.php").symlink_to("Core.php")
        self.commit()
        with self.assertRaisesRegex(ValueError, "Non-regular committed"):
            self.build("symlink")
        self.assertFalse((self.root / "symlink").exists())

    def test_never_overwrites_existing_output(self):
        existing = self.root / "existing"
        existing.mkdir()
        marker = existing / "previous.txt"
        marker.write_text("retain original")
        with self.assertRaises(FileExistsError):
            self.build("existing")
        self.assertEqual(marker.read_text(), "retain original")


if __name__ == "__main__":
    unittest.main(verbosity=2)
