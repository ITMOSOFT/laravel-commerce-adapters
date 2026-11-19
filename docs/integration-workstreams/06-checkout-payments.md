# Checkout ↔ Payments orchestration

**Status:** planned; not implemented by this archive.

## Contract to settle

Define payment intent/authorization/capture lifecycle and the exact point checkout becomes completed and order creation is committed. Handle asynchronous gateways via idempotent webhooks and reconciliation.

## Minimum acceptance tests

Successful authorization does not imply capture unless policy says so; capture amount/currency must match payable order amount; decline leaves checkout retryable; duplicate webhook is idempotent; out-of-order webhook does not regress state; partial and multiple captures follow explicit rules; cancellation racing with capture reconciles safely; timeout/unknown gateway result enters a recoverable state; verify no duplicate order/payment.

## Implementation guardrails

- Inspect current public APIs, model relationships, migrations, events, and dependency constraints in each participating repository before writing adapter code.
- Use package services/public APIs; do not mutate another package's tables directly.
- Define transaction boundaries, idempotency key, event timing, and failure/retry behavior before implementation.
- Add compatibility coverage for the actual minimum supported package versions.
- Document any required core-package change separately from the optional adapter.
