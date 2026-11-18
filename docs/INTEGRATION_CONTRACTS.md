# Integration contracts

## Checkout → Orders

### Source contract: Checkout

The checkout session persists:
- `cart_snapshot` as a JSON array.
- `totals` as a JSON object with `subtotal`, `discount`, `tax`, `shipping`, and `grand_total`.
- `billing_address`, `shipping_address`, `shipping_method`, `shipping_details`.
- `currency`, `user_id`, `cart_id`, `public_id`, `status`, `metadata`.

Each cart snapshot item contains `itemable_type`, `itemable_id`, `name`, `sku`, `quantity`, `unit_price`, `line_total`, `currency`, `options`, and `cart_item_id`.

### Target contract: Orders

The Orders service accepts:
- `items[]` with `product_type`, `product_id`, `name`, `sku`, `quantity`, `unit_price`, `line_total`, `product_snapshot`, and `metadata`.
- `subtotal`, `discount_total`, `tax_total`, `shipping_total`, `grand_total`.
- `currency`, billing/shipping addresses, optional customer morph, and metadata.

### Mapping rules

| Checkout | Orders |
|---|---|
| `itemable_type` | `product_type` |
| `itemable_id` | `product_id` |
| `name` | `name` |
| `sku` | `sku` |
| `quantity` | `quantity` |
| `unit_price` | `unit_price` |
| `line_total` | `line_total` |
| entire snapshot item | `product_snapshot` |
| `options` | item metadata `options` |
| `totals.subtotal` | `subtotal` |
| `totals.discount` | `discount_total` |
| `totals.tax` | `tax_total` |
| `totals.shipping` | `shipping_total` |
| `totals.grand_total` | `grand_total` |

## Idempotency

The adapter stores its order ID and order number under the configured checkout metadata key. The checkout row lock serializes concurrent conversion attempts on databases that support row-level locking. The order and checkout metadata/status writes must share one database transaction.

## Money precision

The current upstream packages use decimal database values and floating-point arithmetic in parts of their service layer. This adapter preserves the supplied decimal strings at the mapping boundary but cannot eliminate floating-point behavior inside the existing Orders service. A future ecosystem-wide Money contract should standardize integer minor units and currency exponents.
