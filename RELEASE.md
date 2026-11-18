# Release v0.4.0

## Summary
Adds the Cart → Pricing adapter while retaining Checkout → Orders, Payments → Orders and Inventory → Orders.

## Before release

```bash
composer update --no-interaction --prefer-dist --prefer-lowest
composer test
composer update --no-interaction --prefer-dist --prefer-stable
composer test
composer validate --no-check-publish
```

## GitHub release

**Tag:** `v0.4.0`

**Title:** `v0.4.0 – Cart to Pricing Integration`

## Release notes

### What's new
- Resolve applicable prices through `PriceResolver` before adding cart lines.
- Respect cart currency, quantity breaks, active schedules and trusted context-specific pricing.
- Resolve quantity breaks against the resulting merged cart-line quantity.
- Persist price provenance in the Cart package's existing `price_snapshot` field.
- Delegate all cart writes to the Cart package's public `CartManager` API.

### Compatibility
- PHP 8.3+
- Laravel 12 and 13
- `itmosoft/laravel-cart`
- `itmosoft/laravel-pricing`
- Existing Checkout, Orders, Payments and Inventory dependencies.

### Important limitations
- Cart stores money at two decimal places; Pricing stores four. The adapter rounds half-up to two decimals and retains the original amount in the snapshot.
- No FX conversion or currency-exponent-aware money arithmetic is performed.
- Existing cart lines are not automatically repriced when schedules change; the Cart package currently has no public price-update operation independent of quantity.
- Pricing context must be supplied from trusted server-side values.
- Cart, item and Pricing models must use the default database connection.

### Testing
Run both lowest and stable dependency strategies and ensure GitHub Actions is green. Do not claim test success until the commands pass locally.

## Local Git commands

Run these commands yourself after tests pass:

```bash
git status
git add .
git commit -m "feat: add cart to pricing integration"
git push origin main

git tag -a v0.4.0 -m "Release v0.4.0"
git push origin v0.4.0
```

Create the GitHub Release from tag `v0.4.0` using the title and release notes above. If Packagist is connected to the repository webhook, it should discover the tag automatically; otherwise trigger an update from the package page.
