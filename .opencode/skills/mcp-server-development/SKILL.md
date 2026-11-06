---
name: mcp-server-development
description: MCP tool registration, discovery, schemas, dispatch, errors, transport and WordPress-to-Cloud contracts.
---
# MCP Server Engineering
Map registration, command registry, dispatch and auth before adding tools. Treat descriptions, schemas and outputs as public API contracts; version and test changes. Discover WordPress abilities dynamically only through reviewed allowlists and strict schemas. Discovery is not authorization. Bind account, tenant, site and owner server-side; re-check capabilities at execution. Mutations follow plan/approval/apply/verify/rollback. Bound pagination, output, runtime and resource use. Normalize errors without leaking secrets, SQL, stack traces or paths. Test handshake, discovery, malformed input, auth expiry, tenant isolation, retries, partial results and contract compatibility. Never expose arbitrary shell, unrestricted filesystem or unreviewed abilities.
