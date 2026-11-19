# Seller Verification ↔ Suppliers/Vendors

**Status:** planned; not implemented by this archive.

## Contract to settle

Map verification lifecycle to supplier/vendor eligibility without conflating verification evidence with the supplier/vendor profile. Define pending, approved, rejected, suspended, expired, and re-review states.

## Minimum acceptance tests

Each verification state maps deterministically; approval enables only configured capabilities; rejection/suspension blocks new activity while preserving historical records; repeated events do not duplicate status changes; evidence remains auditable; re-verification restores eligibility only after approval; test vendor and supplier records linked to same legal entity without accidental identity merge.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
