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

### Inventory → Orders (v0.3.0)

`InventoryOrderAdapter` connects order status transitions to stock reservations:

- `pending` → `processing`: reserve stock for each line with a product morph.
- `processing` → `completed`: fulfil reservations and decrement on-hand stock.
- Any transition to `cancelled`: release active reservations.

This listener is opt-in. It is disabled by default and requires a configured stock location. Insufficient stock rolls back the order transition; if payment capture initiated that transition, the payment remains captured and the order remains pending, with a warning logged for reconciliation. See `docs/INVENTORY_ORDERS.md`.

### Cart → Pricing (v0.4.0)

`CartPricingAdapter` resolves a price through `PriceResolver` before adding a cart line through `CartManager`. It supports currency matching, quantity breaks, scheduled prices, and trusted context-specific prices. When an existing item/options line is incremented, the adapter resolves against the resulting total line quantity and applies that unit price to the complete line.

```php
use ItmoSoft\CommerceAdapters\Services\CartPricingAdapter;
use ItmoSoft\Cart\Models\Cart;

$cart = Cart::query()->findOrFail($cartId);
$item = app(CartPricingAdapter::class)->addPricedItem(
    cart: $cart,
    item: $product,
    quantity: 2,
    options: ['size' => 'M'],
    context: ['customer_group' => 'wholesale'],
);
```

The adapter stores the Pricing record ID, original four-decimal amount, rounded Cart amount, currency, quantity threshold, context and resolution timestamp in the cart item's `price_snapshot`. Cart currently persists `unit_price` and `line_total` to two decimal places, so the adapter rounds half-up to two decimals and preserves the original Pricing amount in the snapshot. It does not perform FX conversion. Pricing context must be assembled from trusted server-side values.

This integration is invoked explicitly through the adapter; it does not automatically reprice existing cart lines when a price schedule changes. The Cart package currently has no public operation for replacing a line's price and snapshot without changing quantity.

### Checkout → Shipping (v0.6.0)

`CheckoutShippingAdapter` calculates shipping options for a pending checkout using `ShippingQuoteService`, then revalidates the selected quote against current rates before persisting it through the Checkout manager when the installed Checkout model supports its totals contract, with a compatible session-level fallback for published Checkout releases missing that model method. It uses the checkout shipping destination, subtotal, currency, and an application-supplied packed shipment weight. The selected quote snapshot is stored in `shipping_details` and carried into order metadata by `CheckoutOrderAdapter`.

```php
use ItmoSoft\CommerceAdapters\Services\CheckoutShippingAdapter;

$adapter = app(CheckoutShippingAdapter::class);
$quotes = $adapter->quoteForCheckout($checkoutSession, weight: 2.5);
$checkoutSession = $adapter->selectQuote($checkoutSession, $quotes[0], weight: 2.5);
```

The adapter rejects stale or client-modified quote amounts and only operates on pending checkout sessions. It does not create shipments or contact carriers. Pass the actual packed weight when using weight-based rates. See [Checkout → Shipping integration](docs/CHECKOUT_SHIPPING.md).

### Cart → Inventory (v0.5.0)

`CartInventoryAdapter` checks the cart's total demand for a product against available stock at a selected active Inventory location before adding a line or changing its quantity. It counts all lines for the same product, including lines with different options, and delegates all cart writes to `CartManager`.

```php
use ItmoSoft\CommerceAdapters\Services\CartInventoryAdapter;
use ItmoSoft\Cart\Models\Cart;

$cart = Cart::query()->findOrFail($cartId);
$line = app(CartInventoryAdapter::class)->addAvailableItem(
    cart: $cart,
    item: $product,
    quantity: 2,
    options: ['size' => 'M'],
    unitPrice: '19.99',
);

// Change quantity with the same stock check:
$line = app(CartInventoryAdapter::class)->setAvailableQuantity($line, 3);

// Or check availability before composing with Cart → Pricing:
app(CartInventoryAdapter::class)->assertAvailableForCart($cart, $product, 2);
```

Configure `ITMOSOFT_CART_INVENTORY_LOCATION_ID` or pass a `StockLocation` / location ID to each call. Inactive or missing locations are rejected. Inventory quantities are integers; fractional cart quantities are not supported by this adapter.

**Important:** this is an availability check, not a stock reservation. Adding an item to a cart does not change `quantity_reserved`; abandoned carts therefore do not hold stock. Two different carts can both pass an availability check for the same remaining units. The authoritative reservation still happens in Inventory → Orders when an order enters `processing`. Treat cart availability as advisory and handle reservation failure at order processing.

### Checkout → Tax (v0.7.0)

`CheckoutTaxAdapter` resolves location-specific rates through `TaxResolver`, calculates exclusive tax through `TaxCalculator`, applies it through `CheckoutManager::setTotals()` where supported, and stores a calculation snapshot under `metadata.itmosoft_tax`. A tax class must be supplied explicitly because the current Checkout cart snapshot does not define a canonical tax class per line. See [Checkout → Tax integration](docs/CHECKOUT_TAX.md).

```php
use ItmoSoft\CommerceAdapters\Services\CheckoutTaxAdapter;
use ItmoSoft\Tax\Models\TaxClass;

$class = TaxClass::query()->where('code', 'standard')->firstOrFail();
$result = app(CheckoutTaxAdapter::class)->calculateForCheckout($checkoutSession, $class);
```

Configure `ITMOSOFT_CHECKOUT_TAX_DESTINATION` as `shipping` (default) or `billing`; set `ITMOSOFT_CHECKOUT_TAX_INCLUDE_SHIPPING=true` when shipping is taxable. Pass `apply: false` for a preview. Exclusive tax only is supported because Checkout currently adds the tax field to the order total. Mixed tax-class line allocation and currency-exponent-aware arithmetic are not implemented.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `itmosoft/laravel-checkout`
- `itmosoft/laravel-orders` (v1.0.2 or later)
- `itmosoft/laravel-payments`
- `itmosoft/laravel-inventory`
- `itmosoft/laravel-cart`
- `itmosoft/laravel-pricing`
- `itmosoft/laravel-shipping`
- `itmosoft/laravel-tax`

## Installation

```bash
composer require itmosoft/laravel-commerce-adapters
php artisan vendor:publish --tag=itmosoft-commerce-adapters-config
php artisan migrate
```

Ensure the Checkout, Orders, Payments, Inventory, Cart, Pricing, Shipping and Tax migrations are installed and run. The adapters do not create or modify core package tables.

## Configuration

```php
// config/itmosoft-commerce-adapters.php
return [
    'checkout_orders' => [
        'customer_type' => 'user',
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],
    'checkout_shipping' => [
        'default_weight' => env('ITMOSOFT_CHECKOUT_SHIPPING_DEFAULT_WEIGHT', 0),
    ],
    'payments_orders' => [
        'enabled' => true,
        'process_on_full_capture' => true,
        'refund_on_full_refund' => true,
    ],
    'inventory_orders' => [
        'enabled' => false,
        'location_id' => env('ITMOSOFT_INVENTORY_LOCATION_ID'),
    ],
    'cart_inventory' => [
        'location_id' => env('ITMOSOFT_CART_INVENTORY_LOCATION_ID'),
    ],
];
```

Set `ITMOSOFT_PAYMENTS_ORDERS_ENABLED=false` to disable automatic event listeners and orchestrate the adapter manually.

## Usage

Payments → Orders event handling is enabled by default. Inventory → Orders is opt-in and requires a configured stock location.

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

- Payments, Orders and Inventory must use the application's default database connection for atomic automatic synchronization.
- Payment capture/refund events are currently dispatched synchronously inside the Payments package's database transaction. The adapter runs its order update on the same connection so database changes participate in that transaction.
- The Orders package dispatches `OrderStatusChanged` synchronously. External side effects in listeners should be deferred until after commit.
- SQLite tests verify behavior, not production row-lock concurrency. Validate the workflow against the production database engine before relying on concurrency guarantees.

## Testing

```bash
composer validate --strict
composer update --no-interaction --prefer-dist
composer test
```

The test suite includes Checkout → Orders, Checkout → Shipping, Checkout → Tax, Payments → Orders, Inventory → Orders, Cart → Pricing and Cart → Inventory integration tests. CI tests PHP 8.3/8.4 with Laravel 12/13 and lowest/stable dependency strategies.

## Scope and known limitations

- This package does not call payment gateways, authorize payments, capture funds, or initiate refunds. It reacts to state changes recorded by `laravel-payments`.
- `laravel-payments` currently stores amounts with two decimal places and uses floating-point arithmetic in its manager. This adapter follows that existing contract and rounds aggregate comparisons to two decimals; it does not solve the broader money precision issue.
- The adapter assumes payments linked to one order use the order's currency. If any linked payment has a different currency, it skips automatic order status changes.
- A fully refunded payment against an order that is still `pending` does not automatically cancel or refund the order; manual reconciliation is required.
- The current Payments package does not emit a payment-cancelled event, so cancellation is not synchronized.
- The integration does not implement deposits, installment schedules, partial-payment order statuses, chargebacks, disputes, or payment allocation across multiple orders.
- The adapter does not change either core package's schema or introduce a foreign key between payments and orders.

This package is an optional integration layer. Core packages remain independently usable.

## Inventory → Orders integration (v0.3.0)

The optional Inventory adapter reserves stock when an order enters `processing`, releases active reservations when it is cancelled, and fulfils reservations when it becomes `completed`. It uses the Inventory package's public `InventoryManager` API and deterministic per-order-line references to prevent duplicate reservations.

The integration is disabled by default. Publish the config and set `ITMOSOFT_INVENTORY_ORDERS_ENABLED=true` plus `ITMOSOFT_INVENTORY_LOCATION_ID=<location id>` before enabling it. Order or item metadata may override the default location with `inventory_location_id`. Fractional quantities are rejected because the Inventory package currently uses integer stock units. See [Inventory → Orders integration](docs/INVENTORY_ORDERS.md) for lifecycle and failure behavior.


## Cart → Pricing integration (v0.4.0)

Use `app(CartPricingAdapter::class)->addPricedItem($cart, $product, $quantity, $options, $context)` instead of calling `CartManager::add()` with a client-provided or stale price. The adapter resolves the applicable `Price` using the cart currency and resulting line quantity, then delegates persistence to `CartManager`. If no price applies, it throws `CartPricingException` and does not add the line. The Cart and Pricing models must use the application's default database connection.

**Money precision limitation:** Pricing stores four decimal places, while Cart currently stores two. The adapter uses half-up rounding to two decimal places for the cart amount and retains the original amount in `price_snapshot`. This is not currency-exponent-aware money arithmetic; applications handling zero- or three-decimal currencies should account for the Cart package's current two-decimal schema before using this integration.


## Cart → Inventory integration (v0.5.0)

The Cart → Inventory adapter checks stock before cart additions and quantity changes. It calculates demand across all cart lines for the same product, not only matching option hashes. Use `addAvailableItem()` for a checked addition, `setAvailableQuantity()` for a checked quantity change, `availableQuantity()` for a location-level read, or `assertAvailableForCart()` when composing the check with Cart → Pricing.

This adapter deliberately does not reserve inventory. Cart contents are not commitments, and the existing Inventory package has no cart-reservation lifecycle. Stock is reserved by Inventory → Orders at order processing. Availability can change between the cart check and order reservation; applications must handle an insufficient-stock result during order processing. All participating models must use the application's default database connection. See [Cart → Inventory integration](docs/CART_INVENTORY.md).
