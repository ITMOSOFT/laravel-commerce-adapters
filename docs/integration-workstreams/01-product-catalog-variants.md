# Product Catalog ↔ Product Variants

**Status:** planned; not implemented by this archive.

## Contract to settle

Choose one canonical owner for variant identity, attributes, SKU, and sellable status. Catalog must not maintain a competing variant record if Product Variants is authoritative. Define a compatibility path for existing catalog variant rows before any migration.

## Minimum acceptance tests

Create a product with multiple variants and prove one canonical ID is referenced everywhere; reject duplicate option combinations and duplicate scoped SKUs; update variant attributes through the owning package and verify catalog projections stay consistent; test product/variant deletion policy; migrate legacy duplicates without silent data loss; verify rollback leaves both packages consistent.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
