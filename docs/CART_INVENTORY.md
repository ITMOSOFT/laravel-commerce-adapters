# Cart → Inventory

## Purpose

This optional adapter checks whether a cart's requested quantity fits the available stock at one Inventory location. It does not make a reservation and does not create a cart-specific stock hold.

## Configuration

Set a default location in `config/itmosoft-commerce-adapters.php`:

```php
'cart_inventory' => [
    'location_id' => env('ITMOSOFT_CART_INVENTORY_LOCATION_ID'),
],
```

Or pass a `StockLocation` model / location ID to each adapter call. The selected location must exist and be active.

## Add an item with an availability check

```php
use ItmoSoft\CommerceAdapters\Services\CartInventoryAdapter;

$line = app(CartInventoryAdapter::class)->addAvailableItem(
    cart: $cart,
    item: $product,
    quantity: 2,
    options: ['size' => 'M'],
    unitPrice: '19.99',
);
```

The adapter sums the quantities of every existing cart line with the same product morph, regardless of options. It adds the requested quantity to that demand, checks the resulting total against Inventory's `available_quantity`, and delegates persistence to `CartManager`.

## Change a line quantity

```php
$line = app(CartInventoryAdapter::class)->setAvailableQuantity($line, 3);
```

The adapter checks the proposed quantity plus the same product's quantities in all other cart lines before delegating to `CartManager::setQuantity()`.

## Compose with Cart → Pricing

If the application wants the Pricing adapter to own price resolution, it can first call `assertAvailableForCart($cart, $product, $quantity)` and then call `CartPricingAdapter::addPricedItem(...)`. This remains an advisory check; stock is not reserved between the two calls.

## Lifecycle and concurrency limitation

Cart contents are provisional. This adapter does not call `InventoryManager::reserve()`, does not increment `quantity_reserved`, and does not release stock when a cart is cleared or abandoned. Multiple carts can pass availability checks for the same remaining units. Stock may also change after a cart check. The Inventory → Orders adapter remains the authoritative reservation step when an order enters `processing`; applications must handle insufficient stock at that point.

The adapter requires Cart, product, Inventory location and stock records to use the application's default database connection. Inventory quantities are integers, so fractional quantities are rejected/not supported.
