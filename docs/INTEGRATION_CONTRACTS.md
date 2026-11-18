# Integration contracts

## Payments → Orders

### Source of truth

- Payments owns payment intents, amounts, gateway references, transactions and webhook records.
- Orders owns order state and order status history.
- The adapter reads payment ledger records and requests transitions through `OrderService`; it never writes directly to another package's tables.

### Capture contract

A `PaymentCaptured` event triggers an aggregate query of all payment rows where `order_id` matches the order. The order moves from `pending` to `processing` only when:

- every linked payment currency matches the order currency;
- aggregate `captured_amount` is at least `grand_total`;
- the order is still `pending`.

Partial capture events are safe and do not advance the order prematurely.

### Failure contract

A `PaymentFailed` event does not cancel or reject the order. Payment failures can be retryable, and the order remains pending unless the host application applies a separate business policy.

### Refund contract

A `PaymentRefundUpdated` event triggers aggregate reconciliation. The order moves from `processing` or `completed` to `refunded` only when aggregate refunded amount is at least aggregate captured amount and at least one amount has been captured.

Partial refunds do not change the order status.

### Idempotency and locking

- The adapter locks the order row and linked payment rows using `lockForUpdate()`.
- It derives the transition from current persisted amounts, not event payload deltas.
- Repeated events do not repeat an already-completed order transition.
- The integration requires a shared default database connection.

### Event timing

Payments currently dispatches capture/refund events synchronously from inside its transaction. Orders dispatches status-change events synchronously. Database writes share the transaction, but listeners must defer external side effects until after commit.

## Checkout → Orders

See `CheckoutOrderAdapter` and README for checkout snapshot mapping, metadata-based idempotency and same-connection transaction requirements.
