# Release instructions

## Package

- GitHub repository: `ITMOSOFT/laravel-commerce-adapters`
- Composer package: `itmosoft/laravel-commerce-adapters`
- Version: `v0.2.0`
- Release title: `v0.2.0 — Payments-to-Orders Lifecycle Integration`
- Description: `Adds an optional Payments-to-Orders adapter with aggregate capture/refund reconciliation, retry-safe failed-payment handling, idempotent order transitions, and integration tests. Preserves the existing Checkout-to-Orders adapter.`

## Repository description

`Optional integration adapters connecting independent ITMOSOFT Laravel commerce packages.`

## Install and test

```bash
composer validate --strict
composer update --no-interaction --prefer-dist
composer test
```

Lowest dependency strategy:

```bash
composer update --no-interaction --prefer-dist --prefer-lowest
composer test
```

## User-run Git commands

Run these only after local tests pass and the GitHub Actions matrix is green:

```bash
git add .
git commit -m "feat: add payments-to-orders lifecycle adapter"
git push origin main

git tag v0.2.0
git push origin v0.2.0
```

Do not create the tag until CI is green. These commands are for the repository owner to run; no GitHub writes are performed by the assistant.

## GitHub Release notes

### What's Changed
- Added Payments → Orders lifecycle synchronization.
- Automatically advances pending orders to processing after aggregate captured payments meet the order total.
- Leaves orders pending after failed payment attempts so retry remains possible.
- Marks processing/completed orders refunded only after all linked captured funds are refunded.
- Handles multiple payment records per order and ignores currency mismatches without rolling back the payment event.
- Added manual synchronization API and regression coverage.
- Preserved Checkout → Orders integration and corrected its fixtures for older Checkout model casts.

### Testing
- Run the full PHPUnit suite locally.
- Confirm every PHP/Laravel/dependency matrix job is green before publishing.
- Static validation is not a substitute for PHPUnit or GitHub Actions.

## Packagist

If the package is already registered on Packagist, confirm its GitHub webhook updates the new tag. Otherwise submit `https://github.com/ITMOSOFT/laravel-commerce-adapters` as the package repository.
