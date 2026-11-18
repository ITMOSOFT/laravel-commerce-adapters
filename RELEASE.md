# Release guide

## Repository

- Repository: `ITMOSOFT/laravel-commerce-adapters`
- Composer package: `itmosoft/laravel-commerce-adapters`
- Description: `Optional integration adapters connecting independent ITMOSOFT Laravel commerce packages.`

Create an empty public GitHub repository with the name above, then extract this ZIP into your already initialized and connected local repository.

## Validate and test

```bash
composer validate --strict
composer update --no-interaction --prefer-dist
composer test
```

The test suite requires the upstream Checkout and Orders packages. Composer is configured to resolve their public Git repositories when stable Packagist versions are unavailable.

## User-run Git commands

Only after local tests pass:

```bash
git add .
git commit -m "feat: add checkout to orders commerce adapter"
git push -u origin main
```

After the GitHub Actions workflow is green:

```bash
git tag -a v0.1.0 -m "Initial commerce adapters release"
git push origin v0.1.0
```

## GitHub Release

**Title:** `v0.1.0 — Initial Commerce Adapters`

**Description:**

### Added
- Optional Checkout → Orders adapter.
- Maps checkout cart snapshots and totals to order records.
- Preserves product morph identifiers, SKU, quantity, prices, item snapshots, options, addresses and shipping details.
- Stores the created order reference in checkout metadata.
- Completes checkout and creates the order within one database transaction.
- Returns the existing order on repeated conversion attempts.
- Adds integration tests and Laravel 12/13 CI matrix.

### Notes
- Requires `itmosoft/laravel-checkout` and `itmosoft/laravel-orders`.
- Payment capture and inventory reservation remain separate workflows.
- Synchronous `OrderCreated` listeners should defer external side effects until after commit.
- Both packages must use the same default database connection.

## Packagist

After the GitHub repository and tag are available, submit `https://github.com/ITMOSOFT/laravel-commerce-adapters` to Packagist or update the existing package webhook. Verify that Packagist indexes `v0.1.0` before installing by version.
