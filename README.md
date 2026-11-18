# ITMOSOFT Laravel Commerce Adapters

Optional integration adapters for independent ITMOSOFT Laravel commerce packages.

## Current adapter

### Checkout → Orders

`CheckoutOrderAdapter` converts an `ItmoSoft\Checkout\Models\CheckoutSession` into an `ItmoSoft\Orders\Models\Order`.

It maps:

- `cart_snapshot` entries to order items, including product morph identifiers, SKU, quantity, unit price, line total, original item snapshot and options.
- Checkout totals (`subtotal`, `discount`, `tax`, `shipping`, `grand_total`) to order totals.
- Billing and shipping address snapshots.
- Checkout user ID to the order customer morph only when `customer_type` is configured.
- Shipping method/details and original checkout metadata into order metadata.

The adapter then stores the resulting order reference in checkout metadata and completes the checkout session.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- `itmosoft/laravel-checkout`
- `itmosoft/laravel-orders` (v1.0.2 or later)

## Installation

```bash
composer require itmosoft/laravel-commerce-adapters
php artisan vendor:publish --tag=itmosoft-commerce-adapters-config
```

Publish and run the underlying packages' migrations as documented by those packages.

Configure the customer morph type if checkout `user_id` should become an order customer:

```php
// config/itmosoft-commerce-adapters.php
return [
    'checkout_orders' => [
        'customer_type' => 'user', // or the configured morph-map alias / model class
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],
];
```

The morph type must match your application's Eloquent morph-map convention. If it is not configured, the adapter deliberately leaves the order customer unset rather than guessing the user model.

## Usage

```php
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter;

$session = CheckoutSession::query()->findOrFail($checkoutSessionId);

$order = app(CheckoutOrderAdapter::class)->createOrder($session);
```

Optional order-field overrides can be supplied as the second argument:

```php
$order = app(CheckoutOrderAdapter::class)->createOrder($session, [
    'notes' => 'Created from website checkout',
]);
```

## Transaction and idempotency behavior

- The adapter locks the checkout row with `lockForUpdate()`.
- Order creation, storing the order reference in checkout metadata, and checkout completion run inside one database transaction.
- A repeated call returns the linked order instead of creating a duplicate.
- A completed checkout without this adapter's order reference is rejected.
- Both packages must use the application's default database connection. The adapter checks the checkout model's connection before proceeding.
- The adapter does not reserve or decrement inventory and does not authorize/capture payment. Those are separate workflows.

## Important event caveat

The current Orders package dispatches `OrderCreated` synchronously from inside its own transaction. This adapter's outer transaction keeps database writes atomic, but a synchronous listener may run before the outer transaction commits. Event listeners that perform external side effects should be queued after commit (or otherwise defer side effects until commit). This adapter does not alter the Orders package's event implementation.

## Testing

The test suite uses SQLite in-memory and loads the Checkout and Orders service providers.

```bash
composer validate --strict
composer update --no-interaction --prefer-dist
composer test
```

The Composer VCS repositories are included so the dependent packages can resolve their public default branches when their stable Packagist releases are unavailable. Stable releases are preferred when available.

## Scope

This package is an optional adapter layer. It does not make Checkout depend on Orders, and neither core package depends on this adapter.

Not included:
- Payment authorization/capture.
- Inventory reservation/fulfilment.
- Tax/shipping provider calculation.
- Customer model discovery.
- Cross-database distributed transactions.
- A new checkout/order foreign-key migration.
