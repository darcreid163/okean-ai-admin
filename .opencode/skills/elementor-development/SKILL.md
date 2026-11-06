---
name: elementor-development
description: Safe Elementor documents, containers, widgets, templates, global settings, revisions and serialized page data.
---
# Elementor Compatibility
Detect Elementor and Pro independently. Inspect installed version and document schema. Preserve unknown widgets, dynamic tags, custom fields, global references, responsive settings, nested containers and third-party data. Prefer supported document APIs; avoid blind JSON replacement. Validate structure before write; create revision/backup and reversible diff. Never flatten pages or discard unsupported data. Mutations require approval lifecycle and read-back verification. Test legacy sections/columns and containers, templates, global settings, responsive values, dynamic content and Pro-absent behavior. Round-trip tests must prove untouched data is preserved. Report unsupported widgets without altering their data.
