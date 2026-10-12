#!/usr/bin/env python3
"""Fail-closed auditor for files proposed for a separate public CI harness.

Usage: python3 scripts/audit-public-ci-export.py DIR
No file is published by this script; it only validates a staged directory.
"""
import pathlib
import re
import sys

ALLOWED_NAMES = {"README.md", "LICENSE", ".gitignore", "requirements.txt"}
ALLOWED_DIRS = {"tests", ".github/workflows"}
ALLOWED_SUFFIXES = {".py", ".json", ".yml", ".yaml", ".md", ".txt"}
BLOCKED_SUFFIXES = {".php", ".zip", ".sql", ".env", ".pem", ".key", ".p12", ".pfx", ".sqlite", ".db"}
BLOCKED_PATH_WORDS = {"releases", "includes", "wp-ai-admin-cloud", "vendor", "node_modules", "dist", "secrets", "backups"}
SUSPICIOUS = [
    re.compile(r"-----BEGIN [^-]*PRIVATE KEY-----", re.I),
    re.compile(r"(?i)(?:ghp_|github_pat_|sk-)[A-Za-z0-9_-]{20,}"),
    re.compile(r"(?i)(?:password|secret|api[_-]?key|access[_-]?token|refresh[_-]?token)\\s*[:=]\\s*['\\\"][^'\\\"]{8,}['\\\"]"),
    re.compile(r"(?i)https?://(?:wpaiadmin\\.ru|okean\\.market)"),
]

def audit(root: pathlib.Path) -> list[str]:
    errors = []
    if not root.is_dir() or root.is_symlink():
        return ["Staging path must be a real directory"]
    files = list(root.rglob("*"))
    if not files:
        return ["Staging directory is empty"]
    for p in files:
        rel = p.relative_to(root).as_posix()
        parts = p.relative_to(root).parts
        if p.is_symlink():
            errors.append(f"{rel}: symlink rejected")
            continue
        if not p.is_file():
            continue
        if p.stat().st_size > 100_000:
            errors.append(f"{rel}: file larger than 100 KB")
        if any(part.lower() in BLOCKED_PATH_WORDS for part in parts):
            errors.append(f"{rel}: forbidden path")
        in_allowed_dir = rel.startswith("tests/") or rel.startswith(".github/workflows/")
        if not (rel in ALLOWED_NAMES or in_allowed_dir):
            errors.append(f"{rel}: not in allowlist")
        if p.suffix.lower() in BLOCKED_SUFFIXES or p.suffix.lower() not in ALLOWED_SUFFIXES:
            errors.append(f"{rel}: forbidden file type")
            continue
        try:
            content = p.read_text(encoding="utf-8")
        except UnicodeError:
            errors.append(f"{rel}: not plain UTF-8 text")
            continue
        for pattern in SUSPICIOUS:
            if pattern.search(content):
                errors.append(f"{rel}: potential secret or internal endpoint")
                break
    return errors

if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("Usage: audit-public-ci-export.py STAGING_DIRECTORY")
    issues = audit(pathlib.Path(sys.argv[1]))
    if issues:
        for issue in issues:
            print("BLOCK:", issue, file=sys.stderr)
        raise SystemExit(1)
    print("PASS: staged public test files pass preliminary allowlist guard; human review still required.")
