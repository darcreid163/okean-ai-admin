#!/usr/bin/env python3
"""Executable fail-closed contract tests for the public CI export auditor."""
import importlib.util
import pathlib
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parents[1] / "scripts" / "audit-public-ci-export.py"
spec = importlib.util.spec_from_file_location("public_ci_audit", SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

class PublicCIAuditTests(unittest.TestCase):
    def check(self, filename, payload, should_pass):
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory) / filename
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(payload, encoding="utf-8")
            actual = module.audit(pathlib.Path(directory))
            self.assertEqual(not actual, should_pass, (filename, actual))

    def test_generic_fixture(self):
        self.check("tests/protocol.json", '{"jsonrpc":"2.0","method":"ping"}', True)

    def test_generic_workflow(self):
        self.check(".github/workflows/test.yml", "name: generic tests\non: workflow_dispatch\n", True)

    def test_private_php_source(self):
        self.check("tests/plugin.php", "<?php echo 'secret';", False)

    def test_release_archive(self):
        self.check("tests/plugin.zip", "not actually a zip", False)

    def test_unlisted_source_tree(self):
        self.check("includes/README.md", "internal implementation", False)

    def test_private_key(self):
        self.check("tests/key.txt", "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----", False)

    def test_real_site_url(self):
        self.check("tests/config.json", '{"url":"https://wpaiadmin.ru/mcp"}', False)

    def test_secret_assignment(self):
        self.check("tests/settings.py", 'api_key = "really-secret-value"\n', False)

    def test_unlisted_file(self):
        self.check("internal.md", "internal", False)

    def test_empty_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertTrue(module.audit(pathlib.Path(directory)))

    def test_symlink(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / "README.md").write_text("safe", encoding="utf-8")
            (root / "tests").mkdir()
            (root / "tests" / "link.txt").symlink_to(root / "README.md")
            self.assertTrue(module.audit(root))

if __name__ == "__main__":
    unittest.main()
