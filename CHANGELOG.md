# Changelog

## [0.6.0] - 2026-11-18

### Added
- Checkout → Shipping quote integration through `CheckoutShippingAdapter`.
- Added a compatibility fallback for published Checkout versions whose `CheckoutManager::selectShipping()` calls a missing `CheckoutSession::recalculateGrandTotal()` method.
- Fresh quote revalidation before selection to reject stale or client-modified amounts.
- Shipping quote snapshot persistence in Checkout `shipping_details` for downstream order metadata.
- Integration tests for destination matching, quote selection, stale quotes, invalid addresses, completed sessions, and weight validation.
- Shipping package dependency and Testbench provider registration.

### Notes
- The adapter requires Checkout and Shipping to use the default database connection.
- Packed shipment weight is supplied by the application; it is not inferred from cart snapshots.
- Shipment creation, carrier booking, labels, delivery estimates, and inventory reservations remain out of scope.


## [0.5.0] - 2026-11-18

### Added
- Cart → Inventory adapter for stock availability checks before adding cart items or changing line quantities.
- Aggregate demand checks across all cart lines for the same product, including lines with different options.
- Configurable default Inventory location and explicit per-call location selection.
- Active-location validation and default-connection enforcement.
- Cart/Inventory integration tests covering available stock, insufficient stock, aggregate demand, quantity changes and non-reserving behavior.

### Changed
- Documented that cart availability is advisory and does not reserve stock; authoritative reservation remains in Inventory → Orders.


## [0.4.0] - 2026-11-18

### Added
- Cart → Pricing adapter that resolves applicable prices before cart insertion.
- Quantity-break resolution against the resulting merged cart-line quantity.
- Context-aware and currency-aware price resolution using the Pricing package's public `PriceResolver`.
- Price provenance snapshots containing source price ID, original amount, cart amount, context and resolution time.
- Cart/Pricing integration tests for price resolution, quantity breaks, context, currency, schedules, rounding and options.

### Changed
- Added `itmosoft/laravel-cart` and `itmosoft/laravel-pricing` dependencies.
- Cart line persistence remains delegated to `CartManager`; no Cart or Pricing tables are written directly.
- Cart's two-decimal money contract is made explicit; four-decimal Pricing amounts are rounded half-up for Cart and retained in the snapshot.


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
