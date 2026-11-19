# Multitenancy across commerce packages

**Status:** planned; not implemented by this archive.

## Contract to settle

Define a tenant-context contract shared by products, carts, checkout, orders, inventory, payments, suppliers, marketplace, and background jobs. Tenant ownership must be explicit and enforced at query and write boundaries.

## Minimum acceptance tests

Tenant A cannot read/update Tenant B records by guessed IDs; missing tenant context fails closed for tenant-owned operations; queued jobs retain and validate tenant context; event listeners cannot cross tenant boundaries; relationship IDs from another tenant are rejected; switching tenant context within a long-lived worker does not leak scopes; privileged cross-tenant operations require explicit authorization and audit.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
