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


## Cart → Pricing

### Source of truth

- Pricing owns price records and price resolution rules.
- Cart owns cart lines, quantities, unit-price snapshots and line totals.
- The adapter calls `PriceResolver::resolve()` and delegates cart persistence to `CartManager::add()`; it does not write directly to either package's tables.

### Add contract

- The cart currency is the requested pricing currency.
- Price resolution receives the item model, currency, trusted context, resolution time and resulting line quantity.
- For an existing item/options line, the adapter adds the requested quantity to the existing quantity before resolving, so quantity breaks apply to the final line quantity.
- A missing applicable price or negative amount throws `CartPricingException`; no cart line is added.
- Cart's own merge and validation rules remain authoritative because all writes go through `CartManager`.

### Snapshot and precision

The existing `price_snapshot` field records source package, Price ID, original Pricing amount, rounded Cart unit price, currency, context, threshold, quantity and resolution timestamp. Pricing amounts are decimal(19,4), while Cart unit prices are decimal(14,2); the adapter uses half-up rounding to two decimals. The original four-decimal amount remains in the snapshot. No FX conversion or currency-exponent-aware arithmetic is performed.

### Context and repricing

Context must be trusted server-side data (for example, a verified customer group), not unvalidated request input. This adapter handles price resolution when adding/incrementing a line. It does not automatically reprice existing lines after a scheduled price change because Cart currently exposes no public price-and-snapshot update method independent of quantity.

### Connection requirement

Cart, the priceable model and the configured Pricing model must use the application's default database connection.


## Cart → Inventory

### Source of truth

- Cart owns cart lines and requested quantities.
- Inventory owns on-hand quantities, reservations and stock movements.
- The adapter reads availability through `InventoryManager::stockFor()` and delegates cart mutations to `CartManager::add()` / `setQuantity()`. It does not write directly to either package's tables.

### Availability contract

- The adapter checks the sum of quantities across every line for the same product morph in the cart, including distinct option combinations.
- Adding a line checks current cart demand plus the incoming quantity. Updating a line checks all other lines for that product plus the proposed replacement quantity.
- The selected stock location must exist and be active. A default may be configured using `cart_inventory.location_id`; a location model or ID may be supplied per call.
- Cart, product, location and Inventory stock must share the application's default database connection.
- Inventory units are integers. Quantity values below one are rejected.

### No reservation guarantee

This adapter does not create, modify or release stock reservations. Cart contents are provisional and can be abandoned. Consequently, availability checks are advisory: separate carts can each pass against the same unreserved units, and stock can change before order processing. Inventory → Orders remains responsible for reserving stock when an order enters `processing`; the application must handle a reservation failure at that stage.

## Shipping quote boundary

- Checkout → Shipping: adapter recalculates the quote at selection time and persists the shipping snapshot and totals through the Checkout manager or a compatibility fallback for Checkout releases missing the session totals method; it does not create shipments or call carriers.
