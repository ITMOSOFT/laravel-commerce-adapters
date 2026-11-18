# Release v0.5.0

## Summary
Adds Cart → Inventory availability checks while retaining Checkout → Orders, Payments → Orders, Inventory → Orders and Cart → Pricing.

## Before release

Run both dependency strategies and verify the full suite:

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

- **Tag:** `v0.5.0`
- **Title:** `v0.5.0 – Cart to Inventory Availability Integration`

### Release notes

#### What's new
- Check stock availability before adding a product to a cart.
- Validate cart line quantity changes against stock availability.
- Count total cart demand for a product across different option lines.
- Support a configured default stock location or an explicit location per call.
- Reject missing and inactive locations, and enforce a shared default database connection.

#### Important behavior
- Cart additions do not reserve inventory. Cart contents remain provisional and abandoned carts do not hold stock.
- Availability checks are advisory; multiple carts can pass against the same unreserved stock.
- Inventory → Orders remains responsible for authoritative reservations when an order enters `processing`.
- Integer stock quantities only; fractional quantities, multi-warehouse allocation and unit conversion are out of scope.

#### Compatibility
- PHP 8.3+
- Laravel 12 and 13
- `itmosoft/laravel-cart` and `itmosoft/laravel-inventory`
- Existing Checkout, Orders, Payments and Pricing dependencies.

#### Testing
Run both lowest and stable dependency strategies and confirm GitHub Actions is green. The ZIP's static checks are not a substitute for PHPUnit.

## Local Git commands

Run these yourself after tests pass:

```bash
git status
git add .
git commit -m "feat: add cart to inventory availability integration"
git push origin main

git tag -a v0.5.0 -m "Release v0.5.0"
git push origin v0.5.0
```

Create the GitHub Release from tag `v0.5.0` using the title and notes above. If Packagist is connected to the repository webhook, it should discover the tag automatically; otherwise trigger an update from the package page.
