#!/usr/bin/env python3
"""Regression tests for deterministic Native ZIP and source-bound provenance."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
BUILDER = ROOT / "tools/build-native-candidate.py"


class NativeArtifactTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="wpai-native-artifact-")
        self.repo = Path(self.temp.name) / "repo"
        self.repo.mkdir()
        (self.repo / "includes").mkdir()
        (self.repo / "starter-themes").mkdir()
        (self.repo / "tools").mkdir()
        files = {
            "okean-ai-admin.php": "<?php\n/**\n * Version: 2.1.17\n */\ndefine('OAI_VERSION','2.1.17');\ndefine('OAI_BUILD_ID','2.1.17-test-build');\ndefine('OAI_RELEASE_CHANNEL','commercial_beta');\ndefine('OAI_RELEASE_PROFILE','commercial_beta_candidate');\n",
            "includes/runtime.php": "<?php // runtime fixture\n",
            "includes/class-oai-elementor-schema-save.php": "<?php // deliberately excluded historical QA file\n",
            "starter-themes/fixture.php": "<?php // starter theme fixture\n",
            "README.md": "Native fixture\n",
            "README.txt": "Native fixture\n",
            "LICENSE": "GNU GENERAL PUBLIC LICENSE\nVersion 2, June 1991\n",
            "THIRD-PARTY.md": "GPL-2.0-or-later\n",
            "BUILD-SIGNATURE.txt": "WP AI Admin Build Metadata (not a cryptographic signature)\nVersion: 2.1.17\nBuild ID: 2.1.17-test-build\nRelease channel: commercial_beta\nRelease profile: commercial_beta_candidate\n",
        }
        for name, content in files.items():
            path = self.repo / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content, encoding="utf-8")
        shutil.copy2(self.repo / "BUILD-SIGNATURE.txt", self.repo / "BUILD_SIGNATURE.txt")
        subprocess.run(["git", "init", "-q", str(self.repo)], check=True)
        subprocess.run(["git", "-C", str(self.repo), "config", "user.email", "ci@example.invalid"], check=True)
        subprocess.run(["git", "-C", str(self.repo), "config", "user.name", "CI Fixture"], check=True)
        subprocess.run(["git", "-C", str(self.repo), "add", "."], check=True)
        subprocess.run(["git", "-C", str(self.repo), "commit", "-qm", "fixture"], check=True)
        self.out1 = Path(self.temp.name) / "out-one"
        self.out2 = Path(self.temp.name) / "out-two"

    def tearDown(self):
        self.temp.cleanup()

    def run_builder(self, *args, check=True):
        return subprocess.run([sys.executable, str(BUILDER), *args], cwd=ROOT,
                              text=True, capture_output=True, check=check)

    def build_to(self, output):
        return self.run_builder("build", "--repo", str(self.repo), "--output", str(output))

    def verify(self, output):
        archive = output / "wp-ai-admin-2.1.17.zip"
        return self.run_builder("verify", "--repo", str(self.repo),
                                "--archive", str(archive),
                                "--provenance", str(output / "PROVENANCE.json"),
                                "--checksum", str(output / (archive.name + ".sha256")))

    def test_reproducible_archive_and_source_bound_provenance(self):
        self.build_to(self.out1)
        self.build_to(self.out2)
        a = self.out1 / "wp-ai-admin-2.1.17.zip"
        b = self.out2 / "wp-ai-admin-2.1.17.zip"
        self.assertEqual(a.read_bytes(), b.read_bytes())
        self.assertEqual((self.out1 / "PROVENANCE.json").read_bytes(),
                         (self.out2 / "PROVENANCE.json").read_bytes())
        result = self.verify(self.out1)
        self.assertIn("PASS Native provenance", result.stdout)
        provenance = json.loads((self.out1 / "PROVENANCE.json").read_text())
        self.assertEqual(provenance["version"], "2.1.17")
        self.assertEqual(provenance["build_id"], "2.1.17-test-build")
        with zipfile.ZipFile(a) as z:
            names = z.namelist()
            self.assertEqual(names, sorted(names))
            self.assertNotIn("okean-ai-admin/includes/class-oai-elementor-schema-save.php", names)
            self.assertIn("okean-ai-admin/BUILD-SIGNATURE.txt", names)

    def test_rejects_dirty_or_untracked_artifact_paths(self):
        (self.repo / "includes/untracked.php").write_text("<?php\n")
        result = self.run_builder("build", "--repo", str(self.repo),
                                  "--output", str(self.out1), check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("dirty or contain untracked", result.stderr)

    def test_rejects_tampered_archive(self):
        self.build_to(self.out1)
        archive = self.out1 / "wp-ai-admin-2.1.17.zip"
        archive.write_bytes(archive.read_bytes() + b"tampered")
        result = self.verify(self.out1)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("SHA-256 mismatch", result.stderr)

    def test_rejects_metadata_that_does_not_match_source(self):
        path = self.repo / "BUILD-SIGNATURE.txt"
        path.write_text(path.read_text().replace("Build ID: 2.1.17-test-build", "Build ID: stale"))
        subprocess.run(["git", "-C", str(self.repo), "add", "."], check=True)
        subprocess.run(["git", "-C", str(self.repo), "commit", "-qm", "bad metadata"], check=True)
        result = self.run_builder("build", "--repo", str(self.repo),
                                  "--output", str(self.out1), check=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Build metadata aliases differ", result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)
