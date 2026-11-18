# Checkout → Tax integration (v0.7.0)

`CheckoutTaxAdapter` resolves active Tax rates for a pending Checkout session, calculates exclusive tax, updates Checkout totals, and saves a calculation snapshot in session metadata under `itmosoft_tax`.

## Usage

```php
use ItmoSoft\CommerceAdapters\Services\CheckoutTaxAdapter;
use ItmoSoft\Tax\Models\TaxClass;

$taxClass = TaxClass::query()->where('code', 'standard')->firstOrFail();
$result = app(CheckoutTaxAdapter::class)->calculateForCheckout($checkoutSession, $taxClass);
```

The adapter requires an explicit tax class. Checkout's current cart snapshot has no canonical per-line tax-class field, so the adapter does not guess a product-to-tax mapping. Applications with mixed tax classes must calculate each class against its own taxable line base and orchestrate allocation themselves; this adapter currently applies one tax class to the checkout taxable base.

## Destination and taxable base

By default the adapter uses `shipping_address`. Set `ITMOSOFT_CHECKOUT_TAX_DESTINATION=billing` to use `billing_address`, or pass an explicit destination array containing `country` and optionally `region` / `state` / `province` and `postal_code` / `zip`.

Taxable base is `subtotal - discount`. Shipping is excluded by default; set `ITMOSOFT_CHECKOUT_TAX_INCLUDE_SHIPPING=true` to include it. Rates are resolved through `TaxResolver`, which applies the Tax package's location specificity, priority, active state and date rules.

## Snapshot and recalculation

The adapter recalculates tax each time it is called and applies the resulting tax through `CheckoutManager::setTotals()` when the installed Checkout model supports its totals contract. It retains the calculation inputs, destination, rate breakdown, class ID, currency and timestamp in `metadata.itmosoft_tax`. Call it again after changing the address, cart subtotal, discount, shipping amount, or tax rules.

Pass `apply: false` to preview a calculation without changing Checkout totals or metadata.

## Boundaries and limitations

- Exclusive tax only. Checkout's current grand-total formula adds tax to subtotal; inclusive-tax extraction cannot be applied safely without a separate tax-inclusive total contract.
- One tax class per checkout calculation. Mixed-class carts need per-line allocation support in a future API.
- Tax package amounts and Checkout totals use floating-point / two-decimal contracts; this adapter follows those existing contracts and does not solve currency-exponent precision.
- Checkout and Tax models must use the application's default database connection.
- Tax calculation is not legal/tax advice and does not determine whether a jurisdiction requires collection, exemptions, nexus, registration, filing or remittance. Have tax rules reviewed by a qualified professional.
- The adapter does not write Tax tables or modify core package schemas.
