# Suppliers ↔ Purchase Orders

**Status:** planned; not implemented by this archive.

## Contract to settle

Define supplier identity, active/approved eligibility, supplier-product references, and immutable supplier details on a purchase order. Purchase Orders remains owner of PO lifecycle; Suppliers remains owner of supplier profile.

## Minimum acceptance tests

Create PO for valid supplier; reject unknown/inactive/unapproved supplier according to configured policy; snapshot supplier name/address/tax identifiers at PO creation; supplier profile edits do not mutate historical PO; duplicate supplier-to-PO conversion is idempotent; supplier deletion/archival behavior is explicit; verify line items reference supplier catalog items without copying ownership.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
