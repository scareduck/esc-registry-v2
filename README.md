# ESCR Registry v2

Prototype repository for exploring a modern, read-only ESCR Registry Explorer.

The goal is to validate search, pedigree, litter, dashboard, and registrar-facing discovery workflows against a local MariaDB copy of the legacy registry before committing to either a custom replacement or a COTS product.

## Current mandate

See [`AGENTS.md`](AGENTS.md) for the Codex operating mandate, project boundaries, schema assumptions, V1 scope, and future replacement requirements.

## V1 boundaries

- Read-only prototype.
- Local MariaDB copy only.
- No production writes.
- No registrar editing, payments, authentication, or account management in V1.
- Do not commit real database dumps, credentials, private member data, or generated exports containing real registry data.

## Production schema note

For `RegistryDB`, only uppercase-initial table names are production schema tables. Lowercase tables, including `coi` and `coi_old`, are local-only/non-production and must not be treated as authoritative registry schema.
