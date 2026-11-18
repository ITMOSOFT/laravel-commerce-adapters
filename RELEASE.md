# Release v0.7.0

## Summary
Adds Checkout → Tax integration while retaining all v0.6.0 adapters.

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

## Repository
- **Repository:** `ITMOSOFT/laravel-commerce-adapters`
- **Composer package:** `itmosoft/laravel-commerce-adapters`
- **Description:** `Optional integration adapters connecting independent ITMOSOFT Laravel commerce packages.`

## GitHub release
- **Tag:** `v0.7.0`
- **Title:** `v0.7.0 – Checkout to Tax Integration`

### Release notes
- Resolve location-based Tax rates for pending Checkout sessions.
- Apply exclusive tax through the Checkout totals API, with compatibility fallback for older Checkout model releases.
- Support shipping or billing destination, explicit destination overrides, and optional inclusion of shipping in the taxable base.
- Store tax class, destination, taxable base, tax amount, rate breakdown, currency and timestamp under Checkout metadata `itmosoft_tax`.
- Support preview calculations with `apply: false`.
- Requires `itmosoft/laravel-tax`.

### Important limitations
- One tax class per checkout calculation; mixed-class per-line allocation is not inferred.
- Exclusive tax only because Checkout's current grand-total formula adds tax.
- Currency precision follows existing Tax and Checkout two-decimal/float contracts.
- Checkout and Tax must use the default database connection.
- The package does not determine legal tax obligations, exemptions, nexus, registration, filing or remittance.

### Testing
Run PHPUnit under both lowest and stable dependency strategies and confirm GitHub Actions is green. Static validation is not a substitute for PHPUnit.

## Local Git commands

Run these yourself after tests pass:

```bash
git status
git add .
git commit -m "feat: add checkout to tax integration"
git push origin main

git tag -a v0.7.0 -m "Release v0.7.0"
git push origin v0.7.0
```

Create the GitHub Release from tag `v0.7.0` using the title and notes above. If Packagist is connected to the repository webhook, it should discover the tag automatically; otherwise trigger an update from the package page.
