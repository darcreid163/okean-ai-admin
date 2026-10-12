# Release candidate reconciliation

Private main currently reports Native 2.1.14 and Cloud Core 0.3.6. The separate qualification repository reports candidate Native 2.1.15 and Cloud Core 0.3.7.

Directly compared entry points differ. Native lifecycle control also differs. Cloud security and outbound transport module blobs were identical in sampled comparisons. Candidate-only files include SECURITY.md, CHANGELOG.md and scripts/build-release-candidate.sh.

These are sampled comparisons, not a complete release diff. Do not copy the entire candidate into main without reviewing all changes. Before promotion, validate credential retention, deactivation safety, tenant isolation, token revocation, build reproducibility and installation/upgrade plus approved rollback cycles in an isolated WordPress environment.

No production deployment is approved by this note.
