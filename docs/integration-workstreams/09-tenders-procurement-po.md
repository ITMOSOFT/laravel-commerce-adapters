# Tenders ↔ Procurement ↔ Purchase Orders

**Status:** planned; not implemented by this archive.

## Contract to settle

Define tender award and procurement approval gates, then a traceable conversion into one or more purchase orders. Preserve tender/bid source references and award snapshots.

## Minimum acceptance tests

Only awarded bid can enter procurement; rejected/withdrawn/expired bid cannot convert; approval requirements are enforced; repeated conversion cannot create duplicate procurement/PO; awarded quantities and prices are conserved; split award across suppliers is supported only if explicit; source tender changes do not mutate issued PO; audit who approved and converted.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
