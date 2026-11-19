# Standardized money precision

**Status:** planned; not implemented by this archive.

## Contract to settle

Define a shared Money contract: integer minor units or exact decimal representation plus ISO currency and exponent. Specify rounding at calculation boundaries and serialization. Do not use float as a financial source of truth.

## Minimum acceptance tests

Test zero-, two-, and three-decimal currencies; multiplication/division and percentage rounding; tax/discount/commission aggregation; conversion between package decimal scales; reject NaN/infinity/negative where invalid; JSON/API serialization round-trips exactly; compare totals before and after order/payment/refund; detect legacy float values and define migration/compatibility policy.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
