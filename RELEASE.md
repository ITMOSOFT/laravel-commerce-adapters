# v0.8.0 Release

## Repository
- Name: `ITMOSOFT/laravel-commerce-adapters`
- Composer package: `itmosoft/laravel-commerce-adapters`
- Description: `Optional integration adapters connecting independent ITMOSOFT Laravel commerce packages.`

## Validate locally

```bash
composer update --no-interaction --prefer-dist --prefer-stable
composer test
```

Also run the lowest-dependency test matrix:

```bash
composer update --no-interaction --prefer-dist --prefer-lowest
composer test
```

Do not publish until both supported dependency strategies pass locally and GitHub Actions is green for every matrix job.

## Commit and push (run manually)

```bash
git add composer.json composer.lock config src tests README.md CHANGELOG.md RELEASE.md docs
git commit -m "feat: add checkout promotions adapter"
git push origin main
```

## Tag and release (run manually)

```bash
git tag -a v0.8.0 -m "v0.8.0"
git push origin v0.8.0
```

GitHub Release title: `v0.8.0 — Checkout → Promotions`

Suggested release notes:

```markdown
## Added
- CheckoutPromotionAdapter integrates Promotions code calculation with Checkout totals.
- Promotion snapshots are stored in checkout metadata.
- Supports removing an applied promotion and recording redemption at order creation.
- Redemption is idempotent by promotion and order reference.

## Important
- Applying a code does not reserve usage. Promotion usage caps are checked when redeeming.
- Call redemption in the same database transaction as order creation.
- Promotions and Checkout must share the default database connection.
- One promotion at a time; stacking is not supported.
```

## Packagist

After the GitHub Release is published, confirm the Packagist package `itmosoft/laravel-commerce-adapters` has synchronized v0.8.0. If automatic GitHub synchronization is configured, no manual upload is needed; otherwise trigger an update from the Packagist package page.
