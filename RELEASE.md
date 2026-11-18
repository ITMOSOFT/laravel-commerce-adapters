# Release v0.3.0

## Summary
Adds an optional Inventory → Orders lifecycle adapter while retaining Checkout → Orders and Payments → Orders.

## Before release

```bash
composer update --no-interaction --prefer-dist --prefer-lowest
composer test
composer update --no-interaction --prefer-dist --prefer-stable
composer test
composer validate --no-check-publish
```

## GitHub release

**Tag:** `v0.3.0`

**Title:** `v0.3.0 – Inventory to Orders Integration`

## Release notes

### What's new
- Reserve stock when an order enters processing.
- Release active reservations when an order is cancelled.
- Fulfil reservations and decrement on-hand stock when an order is completed.
- Prevent duplicate reservations/fulfilments using deterministic order-line references.
- Block processing when stock is insufficient.
- Preserve captured payment records when inventory prevents order processing.
- Configure a default stock location or override it in order/item metadata.

### Compatibility
- PHP 8.3+
- Laravel 12 and 13
- `itmosoft/laravel-checkout`
- `itmosoft/laravel-orders`
- `itmosoft/laravel-payments`
- `itmosoft/laravel-inventory`

### Configuration
Inventory automation is disabled by default. Set `ITMOSOFT_INVENTORY_ORDERS_ENABLED=true` and configure `ITMOSOFT_INVENTORY_LOCATION_ID` before enabling it. See `docs/INVENTORY_ORDERS.md`.

### Important limitations
- Inventory quantities must be whole numbers.
- Orders and Inventory must share the same default database connection.
- No automatic reservation expiry, split shipments, backorders, or multi-warehouse allocation.

### Testing
Run the complete PHPUnit suite against both lowest and stable dependency sets. Do not claim test success until both commands pass locally and GitHub Actions is green.

## Local Git commands

Run these commands yourself after both dependency strategies pass:

```bash
git status
git add .
git commit -m "feat: add inventory to orders integration"
git push origin main

git tag -a v0.3.0 -m "Release v0.3.0"
git push origin v0.3.0
```

Create the GitHub Release from tag `v0.3.0` using the title and release notes above. If Packagist is connected to the repository webhook, it should discover the tag automatically; otherwise trigger an update from the package page.
