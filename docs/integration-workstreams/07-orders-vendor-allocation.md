# Orders ↔ Marketplace vendor allocation

**Status:** planned; not implemented by this archive.

## Contract to settle

Specify line-level vendor ownership and split rules. Allocation must conserve quantities and monetary totals and support partial fulfillment/cancellation without duplicating or losing units.

## Minimum acceptance tests

Single-vendor order allocation; multi-vendor split; sum of allocated quantities equals ordered quantity; vendor unavailable/ineligible path; reject over-allocation; partial cancellation reduces only affected allocation; refund follows allocation basis; repeated allocation request returns same allocations; preserve original customer order and snapshot each vendor allocation.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
