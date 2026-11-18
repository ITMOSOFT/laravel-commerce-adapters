# Changelog

## [0.3.0] - 2026-11-18

### Added
- Inventory → Orders adapter using the Inventory package's public reservation APIs.
- Automatic reservation on `pending` → `processing`, release on cancellation, and fulfilment on `processing` → `completed`.
- Deterministic order-line reservation references and idempotent repeated event handling.
- Configurable default inventory location with order/item metadata overrides.
- Explicit handling for insufficient stock, missing reservations, unresolved products, and fractional inventory quantities.
- Inventory integration feature tests, including payment-capture preservation when stock is insufficient.

### Changed
- Added `itmosoft/laravel-inventory` as a package dependency.
- Inventory event integration is opt-in and disabled by default.
- Payment → Orders integration now preserves a captured payment when an inventory reservation prevents the order from entering processing.

## [0.2.0] - 2026-11-18

### Added
- Payments → Orders integration for capture and refund lifecycle events.
- Aggregate captured/refunded amount reconciliation and idempotent order status transitions.
- Currency mismatch protection and retry-friendly failed payment handling.
- Checkout fixture compatibility with older JSON-backed model attributes.

## [0.1.2] - 2026-11-18

### Added
- Checkout → Orders integration adapter.
- Optional customer morph mapping and checkout metadata idempotency link.
