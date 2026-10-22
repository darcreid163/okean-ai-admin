#!/usr/bin/env python3
"""Exercise actual tag/publish guard with synthetic artifacts and an offline gh."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]


class ReleaseGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='wpai-release-guard-')
        self.root = Path(self.temp.name)
        (self.root / 'tools').mkdir()
        shutil.copy2(ROOT / 'tools/native-release.sh', self.root / 'tools')
        (self.root / 'okean-ai-admin.php').write_text("<?php\n/**\n * Version: 2.1.17\n */\ndefine('OAI_VERSION','2.1.17');\n")
        (self.root / 'bin').mkdir()
        fake = self.root / 'bin/gh'
        fake.write_text('#!/usr/bin/env python3\nimport json,os,sys\n'
                        'with open(os.environ["GH_FIXTURE_LOG"],"a") as f: f.write(json.dumps(sys.argv[1:])+"\\n")\n'
                        'sys.exit(int(os.environ.get("GH_FIXTURE_EXIT","0")))\n')
        fake.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.root / 'bin') + os.pathsep + os.environ['PATH'],
                        GITHUB_REF='refs/tags/v2.1.17', GITHUB_REF_NAME='v2.1.17',
                        GITHUB_REPOSITORY='synthetic/fixture', GH_FIXTURE_LOG=str(self.root / 'gh.jsonl'))
        (self.root / 'dist').mkdir()
        self.archive = self.root / 'dist/wp-ai-admin-2.1.17.zip'
        with zipfile.ZipFile(self.archive, 'w') as z:
            z.writestr('okean-ai-admin/okean-ai-admin.php', '<?php // synthetic Native fixture\n')
        digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        self.archive.with_suffix('.zip.sha256').write_text(f'{digest}  {self.archive.name}\n')

    def tearDown(self):
        self.temp.cleanup()

    def run_guard(self, mode, **env):
        return subprocess.run(['bash', str(self.root / 'tools/native-release.sh'), mode],
                              env=dict(self.env, **env), text=True, capture_output=True)

    def assert_no_gh(self):
        self.assertFalse((self.root / 'gh.jsonl').exists())

    def test_matching_tag(self):
        self.assertEqual(self.run_guard('validate').returncode, 0)
        self.assert_no_gh()

    def test_branch_mismatch_and_unrelated_tags(self):
        for ref, name in [('refs/heads/main', 'main'), ('refs/tags/v9.9.9', 'v9.9.9'),
                          ('refs/heads/v2.1.17', 'v2.1.17'), ('refs/tags/v2.1.17', 'v2.1.17;touch bad')]:
            with self.subTest(ref=ref, name=name):
                self.assertNotEqual(self.run_guard('publish', GITHUB_REF=ref, GITHUB_REF_NAME=name).returncode, 0)
        self.assert_no_gh()

    def test_header_mismatch(self):
        plugin = self.root / 'okean-ai-admin.php'
        plugin.write_text(plugin.read_text().replace(' * Version: 2.1.17', ' * Version: 9.9.9'))
        self.assertNotEqual(self.run_guard('publish').returncode, 0)
        self.assert_no_gh()

    def test_publish_exact_assets_without_replacement(self):
        result = self.run_guard('publish')
        self.assertEqual(result.returncode, 0, result.stderr)
        commands = [json.loads(x) for x in (self.root / 'gh.jsonl').read_text().splitlines()]
        self.assertEqual(len(commands), 1)
        self.assertEqual(commands[0][:5], ['release', 'create', 'v2.1.17', 'dist/wp-ai-admin-2.1.17.zip', 'dist/wp-ai-admin-2.1.17.zip.sha256'])
        self.assertIn('--verify-tag', commands[0])
        self.assertNotIn('--clobber', commands[0])

    def test_existing_release_failure_is_not_retried(self):
        self.assertNotEqual(self.run_guard('publish', GH_FIXTURE_EXIT='1').returncode, 0)
        self.assertEqual(len((self.root / 'gh.jsonl').read_text().splitlines()), 1)

    def test_tampered_or_missing_archive(self):
        self.archive.write_bytes(b'changed after checksum')
        self.assertNotEqual(self.run_guard('publish').returncode, 0)
        self.archive.unlink()
        self.assertNotEqual(self.run_guard('publish').returncode, 0)
        self.assert_no_gh()


if __name__ == '__main__':
    unittest.main(verbosity=2)
