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

## Inventory → Orders

### Source of truth

- Inventory owns stock levels, reservations and stock movements.
- Orders owns order state and order status history.
- The adapter uses `InventoryManager::reserve()`, `release()` and `fulfill()`; it does not write directly to Inventory tables.

### Lifecycle

- `pending` → `processing`: reserve each stock-tracked order line.
- `processing` → `completed`: fulfil each active reservation, decrementing both on-hand and reserved stock.
- Any transition to `cancelled`: release active reservations.

A line is stock-tracked when both `product_type` and `product_id` are present. The product is resolved through the order item's polymorphic `product` relation. Lines without a product morph are treated as non-stock lines. Quantities must be positive whole numbers because Inventory currently models integer units.

### Idempotency and failure behavior

- Each reservation reference is deterministic: `itmosoft-order:{order_id}:item:{order_item_id}`.
- The adapter locks the order and stock row, then rechecks the reservation before creating one.
- Repeated processing events do not create duplicate reservations. Repeated completion/cancellation events do not repeat a fulfilled/released action.
- Insufficient stock rolls back the order status transition.
- If payment capture triggered processing, the inventory exception is caught after the nested order transaction rolls back. The captured payment remains persisted and the order remains pending.
- Completion is blocked when a stock-tracked line has no reservation.
- Orders and Inventory must share the same default database connection.

The integration is opt-in and disabled by default. Configure a default location ID or provide `inventory_location_id` in order/item metadata. Item metadata overrides order metadata, which overrides the configured default.
