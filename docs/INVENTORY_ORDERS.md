# Inventory → Orders Integration

The optional Inventory → Orders adapter connects the public APIs of `itmosoft/laravel-inventory` and `itmosoft/laravel-orders`. It does not write directly to Inventory tables.

## Enable it

Publish the adapter configuration and set a default stock location:

```bash
php artisan vendor:publish --tag=itmosoft-commerce-adapters-config
```

```dotenv
ITMOSOFT_INVENTORY_ORDERS_ENABLED=true
ITMOSOFT_INVENTORY_LOCATION_ID=1
```

The location can instead be supplied on order metadata (`inventory_location_id`) or item metadata (`inventory_location_id`). Item metadata takes precedence over order metadata, which takes precedence over the configured default. Applications can also invoke `InventoryOrderAdapter::reserveOrder($order, $location)` explicitly.

## Lifecycle

| Order transition | Inventory action |
|---|---|
| `pending` → `processing` | Reserve each stock-tracked line |
| `processing` → `completed` | Fulfil each active reservation; on-hand and reserved quantities decrease |
| Any transition to `cancelled` | Release active reservations |

An order item is considered stock-tracked when both `product_type` and `product_id` are present. Lines without either value are treated as non-stock lines. A referenced product that cannot be resolved is an error.

Inventory quantities are integers. Fractional order quantities are rejected rather than rounded. Each reservation uses a deterministic reference derived from the order and order-item IDs. Repeated event deliveries therefore do not create duplicate reservations or fulfilments.

## Failure behavior

- Insufficient stock aborts and rolls back the `pending` → `processing` transition.
- When processing is triggered by a captured payment, the adapter catches the inventory integration exception outside the nested order transaction. The payment capture remains recorded, while the order remains `pending` for stock reconciliation.
- Completion is blocked if a stock-tracked order line has no reservation or its reservation is not active/fulfilled.
- Cancellation of a pending order is allowed when no reservation exists.
- Orders and Inventory must share the same default database connection for atomic behavior.

## Important boundaries

- Configure the location before enabling the listener. The feature is disabled by default to avoid changing existing order behavior unexpectedly.
- The adapter uses `InventoryManager::reserve`, `release`, and `fulfill`; it does not mutate stock/reservation tables directly.
- The Inventory package uses integer quantities. Fractional/unit-of-measure conversion is outside this adapter.
- Reservation expiry is not automated by this adapter.
- Warehouse allocation, split fulfillment, backorders, partial shipments, and multi-location allocation are not included.
