# Changelog

## [0.2.0] - 2026-11-18

### Added
- Added optional Payments → Orders lifecycle adapter.
- Added automatic listeners for payment capture, failure and refund updates.
- Added aggregate capture/refund reconciliation across multiple payment records linked to an order.
- Added conservative order transitions: pending → processing after full collection; processing/completed → refunded after full refund.
- Added manual synchronization API and structured result DTO.
- Added eleven Payments → Orders integration tests, including a regression check that an unused failed attempt in another currency does not block a valid capture.

### Preserved
- Existing Checkout → Orders adapter and its regression tests.

### Fixed
- Decode JSON-backed Checkout metadata and shipping details when using older Checkout model versions that do not define array casts. This preserves incoming metadata and makes repeated conversion idempotency work across the supported dependency range.
- Made Checkout integration test fixtures explicitly JSON-encode `shipping_details` and `metadata` for compatibility with the minimum supported Checkout model casts.

### Notes
- Failed payment attempts intentionally do not cancel orders.
- Partial refunds intentionally do not change order status.
- Amount comparisons follow the current Payments package's two-decimal amount contract.
