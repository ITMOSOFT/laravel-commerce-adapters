# Marketplace ↔ Commissions ↔ Payouts

**Status:** planned; not implemented by this archive.

## Contract to settle

Define commission basis, fees, taxes, adjustments, holds, payout eligibility, and reversals. Financial postings need immutable references and idempotent ledger semantics.

## Minimum acceptance tests

Commission calculated from documented gross/net basis; exclude or include shipping/tax explicitly; duplicate order event creates one commission; refund/chargeback reverses correct commission proportion; payout excludes held/unverified vendors; payout creation is idempotent; failed payout can retry without duplicate debit; reconcile commission ledger to payout totals; prohibit negative/overpaid balances unless policy explicitly supports them.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
