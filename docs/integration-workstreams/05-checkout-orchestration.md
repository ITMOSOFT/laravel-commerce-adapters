# Checkout Shipping + Tax + Promotions orchestration

**Status:** planned; not implemented by this archive.

## Contract to settle

Compose existing adapters with a deterministic calculation sequence. Define which subtotal promotions use, whether shipping is taxable, whether discounts reduce taxable base, and when quotes are refreshed. Avoid double-applying totals.

## Minimum acceptance tests

Repeated orchestration yields identical totals; apply/remove promotion then re-run does not stack discounts; shipping quote is refreshed against current destination/cart; tax calculation uses documented discounted or undiscounted base; shipping taxability follows explicit policy; currency mismatch fails closed; a failure midway rolls back all checkout totals/snapshots; test quote expiry and changed cart contents.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
