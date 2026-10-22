# Cloud development candidate export

The Cloud artifact job exports only committed production files from its exact
checkout HEAD. The archive is a development candidate, not a production release
or authorization to deploy. The immutable recovered 0.3.11 manifest is unchanged.

From the repository root with Git, Python 3 and PHP 8.2+ installed:

```sh
python3 wp-ai-admin-cloud/tools/build-candidate.py --output /tmp/wpai-cloud-candidate
cd /tmp/wpai-cloud-candidate
sha256sum -c wp-ai-admin-cloud-0.3.13-dev.zip.sha256
unzip -tq wp-ai-admin-cloud-0.3.13-dev.zip
```

Use a fresh output directory for every build. The builder refuses to overwrite
existing output. It reads Git blobs from HEAD, validates the staged path set and
SHA-256 against the working source, and refuses local runtime edits, untracked
runtime additions or committed symlinks. Tests, documentation and developer tools
are outside the production payload. The source/payload validator inspects all
entries, including empty directories, directory links and broken links.

The ZIP contains sorted regular files with mode 0644 and the commit timestamp in
UTC, rounded down to ZIP's two-second precision. Entries use ZIP_STORED to avoid
compression-library differences. Local file permissions, modification times and
the host timezone do not control archive metadata. Build the same committed tree
twice to obtain identical archive, checksum and provenance bytes.

`PROVENANCE.json` beside the ZIP records the exact source commit/tree, commit
timestamp, candidate version, archive SHA-256 and every packaged file's SHA-256.
It is audit metadata, not a cryptographic signature or proof of live acceptance.
GitHub uploads it with the ZIP and checksum only after all Cloud matrix jobs pass.

Required regression gates:

```sh
php wp-ai-admin-cloud/tests/artifact-integrity.php
python3 wp-ai-admin-cloud/tests/artifact-reproducibility.py
php wp-ai-admin-cloud/tests/recovered-runtime-hashes.php
```

The PHP gate covers staged tampering, missing/extra files, directories and links.
The Python gate builds actual synthetic Git repositories to check byte-for-byte
reproducibility, archive metadata, source/provenance correspondence, dirty payload
denial, committed-link denial and preservation of existing output. Both run in
the Cloud PHP 8.2/8.3/8.4 CI matrix.
