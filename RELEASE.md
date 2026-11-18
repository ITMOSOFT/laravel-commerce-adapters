# Release v0.6.0

## Summary
Adds Checkout → Shipping quote integration, including compatibility with Checkout releases missing the session grand-total method, while retaining Checkout → Orders, Payments → Orders, Inventory → Orders, Cart → Pricing and Cart → Inventory.

## Before release

Run both dependency strategies and verify the complete suite:

```bash
composer update --no-interaction --prefer-dist --prefer-lowest
composer test
composer update --no-interaction --prefer-dist --prefer-stable
composer test
composer validate --no-check-publish
```

Do not tag or publish until both local test runs pass and GitHub Actions is green.

## GitHub repository

- **Repository:** `ITMOSOFT/laravel-commerce-adapters`
- **Composer package:** `itmosoft/laravel-commerce-adapters`
- **Description:** `Optional integration adapters connecting independent ITMOSOFT Laravel commerce packages.`

## GitHub release

- **Tag:** `v0.6.0`
- **Title:** `v0.6.0 – Checkout to Shipping Quote Integration`

### Release notes

#### What's new
- Generate shipping quotes for pending Checkout sessions using the Shipping package.
- Use the checkout shipping destination, subtotal, currency and supplied packed weight.
- Recalculate quotes during selection and reject stale or client-modified amounts.
- Persist the selected method and quote snapshot in Checkout `shipping_details`.
- Preserve quote details when `CheckoutOrderAdapter` converts the checkout into an order.

#### Important behavior
- Only pending checkout sessions can be quoted or updated.
- A shipping country and valid subtotal are required.
- Pass actual packed weight when using weight-based rates; the adapter does not infer weight from cart snapshots.
- Quote amounts follow the Shipping package's existing float/two-decimal contract.
- The adapter does not create shipments, labels, carrier bookings, delivery estimates, taxes, or inventory reservations.
- Checkout and Shipping must use the application's default database connection.

#### Compatibility
- PHP 8.3+
- Laravel 12 and 13
- `itmosoft/laravel-checkout`
- `itmosoft/laravel-shipping`
- Existing Cart, Orders, Payments, Inventory and Pricing dependencies.

#### Testing
Run both lowest and stable dependency strategies and confirm GitHub Actions is green. Static ZIP validation is not a substitute for PHPUnit.

## Local Git commands

Run these yourself after tests pass:

```bash
git status
git add .
git commit -m "feat: add checkout to shipping quote integration"
git push origin main

git tag -a v0.6.0 -m "Release v0.6.0"
git push origin v0.6.0
```

Create the GitHub Release from tag `v0.6.0` using the title and notes above. If Packagist is connected to the repository webhook, it should discover the tag automatically; otherwise trigger an update from the package page.
