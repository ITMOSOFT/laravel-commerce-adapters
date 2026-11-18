# Changelog

## [0.1.1] - 2026-11-18

- Require laravel-orders ^1.0.2 because v1.0.0 and v1.0.1 contain invalid Laravel Composer provider metadata.
- Simplify CI dependency installation to a single Composer update per matrix entry.
- Pin CI runner to Ubuntu 24.04 to avoid the announced ubuntu-latest image migration.
- Remove unused test imports.

## [0.1.0] - 2026-11-18

- Initial optional Checkout → Orders adapter.
- Maps checkout snapshots, totals, addresses, customer morph configuration and metadata.
- Adds transactional checkout completion and metadata-based idempotency.
- Adds integration tests and Laravel 12/13 CI workflow.
