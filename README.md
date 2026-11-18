# ITMOSOFT Laravel Commerce Adapters

Optional integration adapters for independent ITMOSOFT Laravel commerce packages.

## Included adapters

### Checkout → Orders

`CheckoutOrderAdapter` converts a pending `CheckoutSession` into an Order, maps cart snapshots and totals, stores an idempotency reference in checkout metadata, and completes the session in one database transaction.

### Payments → Orders

`PaymentOrderAdapter` synchronizes the payment ledger with the order lifecycle.

By default, the service provider listens to:

- `PaymentCaptured`
- `PaymentFailed`
- `PaymentRefundUpdated`

The default rules are deliberately conservative:

1. A pending order moves to `processing` only when the aggregate captured amount across all payments linked by `order_id` meets or exceeds the order's `grand_total`.
2. Partial captures do not advance the order until the full order total has been collected.
3. A failed payment attempt does not cancel the order; another payment attempt may be made.
4. Partial refunds do not change order status.
5. An order in `processing` or `completed` moves to `refunded` only when all captured amounts across its linked payments have been refunded.
6. Currency mismatches are ignored for automatic order transitions and do not throw from the payment event listener.
7. Repeated events are idempotent because each synchronization recalculates from the current payment ledger and respects the current order state.

`processing` means payment collection is complete; it does not mean fulfilment is complete.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `itmosoft/laravel-checkout`
- `itmosoft/laravel-orders` (v1.0.2 or later)
- `itmosoft/laravel-payments`

## Installation

```bash
composer require itmosoft/laravel-commerce-adapters
php artisan vendor:publish --tag=itmosoft-commerce-adapters-config
php artisan migrate
```

Ensure the Checkout, Orders and Payments migrations are installed and run. The integration does not create or modify their tables.

## Configuration

```php
// config/itmosoft-commerce-adapters.php
return [
    'checkout_orders' => [
        'customer_type' => 'user',
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],
    'payments_orders' => [
        'enabled' => true,
        'process_on_full_capture' => true,
        'refund_on_full_refund' => true,
    ],
];
```

Set `ITMOSOFT_PAYMENTS_ORDERS_ENABLED=false` to disable automatic event listeners and orchestrate the adapter manually.

## Usage

Automatic event handling is enabled by default when this package is installed.

For explicit/manual synchronization:

```php
use ItmoSoft\CommerceAdapters\Services\PaymentOrderAdapter;
use ItmoSoft\Payments\Models\Payment;

$payment = Payment::query()->findOrFail($paymentId);

$result = app(PaymentOrderAdapter::class)->handleCaptured($payment);
// or:
$result = app(PaymentOrderAdapter::class)->handleRefundUpdated($payment);

if ($result->changed()) {
    // $result->order contains the updated order.
}
```

`handleFailed()` intentionally leaves the order unchanged.

## Transaction and connection requirements

- Payments and Orders must use the application's default database connection for automatic synchronization.
- Payment capture/refund events are currently dispatched synchronously inside the Payments package's database transaction. The adapter runs its order update on the same connection so database changes participate in that transaction.
- The Orders package dispatches `OrderStatusChanged` synchronously. External side effects in listeners should be deferred until after commit.
- SQLite tests verify behavior, not production row-lock concurrency. Validate the workflow against the production database engine before relying on concurrency guarantees.

## Testing

```bash
composer validate --strict
composer update --no-interaction --prefer-dist
composer test
```

The test suite includes Checkout → Orders and Payments → Orders integration tests. CI tests PHP 8.3/8.4 with Laravel 12/13 and lowest/stable dependency strategies.

## Scope and known limitations

- This package does not call payment gateways, authorize payments, capture funds, or initiate refunds. It reacts to state changes recorded by `laravel-payments`.
- `laravel-payments` currently stores amounts with two decimal places and uses floating-point arithmetic in its manager. This adapter follows that existing contract and rounds aggregate comparisons to two decimals; it does not solve the broader money precision issue.
- The adapter assumes payments linked to one order use the order's currency. If any linked payment has a different currency, it skips automatic order status changes.
- A fully refunded payment against an order that is still `pending` does not automatically cancel or refund the order; manual reconciliation is required.
- The current Payments package does not emit a payment-cancelled event, so cancellation is not synchronized.
- The integration does not implement deposits, installment schedules, partial-payment order statuses, chargebacks, disputes, or payment allocation across multiple orders.
- The adapter does not change either core package's schema or introduce a foreign key between payments and orders.

This package is an optional integration layer. Core packages remain independently usable.
