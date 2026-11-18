# Checkout → Shipping integration (v0.6.0)

`CheckoutShippingAdapter` connects pending Checkout sessions to the Shipping package's quote service. It does not create shipments or contact carriers.

## Quote shipping methods

```php
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Services\CheckoutShippingAdapter;

$session = CheckoutSession::query()->findOrFail($checkoutId);
$adapter = app(CheckoutShippingAdapter::class);

// Pass the packed shipment weight using the same unit as configured Shipping rates.
$quotes = $adapter->quoteForCheckout($session, weight: 2.5);
```

The adapter uses the checkout's shipping address (`country`, plus optional `region`/`state`/`province` and `postal_code`/`zip`/`zip_code`), subtotal, and currency. The Shipping package's zone and rate rules remain authoritative. If no zone/rate matches, the result is an empty array.

## Select a quote

```php
$selected = $adapter->selectQuote($session, $quotes[0], weight: 2.5);
```

Selection re-runs the Shipping quote calculation inside a database transaction and locks the checkout session. The adapter compares method ID, method code, rate type, currency, and amount against the fresh result. It never trusts a client-submitted shipping amount. If the quote changed or is no longer available, it throws `CheckoutShippingException`; request fresh quotes instead.

The adapter prefers `CheckoutManager::selectShipping()` when the installed Checkout session model provides `recalculateGrandTotal()`. Some published Checkout versions call that method from the manager but omit the trait that defines it; for those versions, the adapter applies the same shipping fields and grand-total formula directly to the session model. It stores a shipping quote snapshot in `shipping_details`, including method identity, amount, currency, rate type, weight, subtotal, destination fields, source, and timestamp. `CheckoutOrderAdapter` already carries this `shipping_details` snapshot into order metadata during conversion.

## Configuration

```php
// config/itmosoft-commerce-adapters.php
'checkout_shipping' => [
    'default_weight' => env('ITMOSOFT_CHECKOUT_SHIPPING_DEFAULT_WEIGHT', 0),
],
```

Pass `weight` explicitly for weight-based rates. The configured default is only a fallback and must use the same unit as the Shipping rates in the application.

## Boundaries and limitations

- Checkout and Shipping models must use the application's default database connection.
- Only pending checkout sessions can be quoted or updated.
- A shipping address with a country and a valid non-negative subtotal are required.
- The adapter does not infer package weight from cart products. Applications must calculate packed weight and pass it explicitly when using weight-based rates.
- Shipping zone matching, rate calculation, currency filtering, and rate types are owned by `laravel-shipping`; this adapter does not alter those rules.
- The Shipping package currently returns quote amounts as floats rounded to two decimals. This adapter preserves that existing contract; it does not solve currency-exponent-aware money arithmetic.
- A quote is revalidated when selected, but availability/pricing can still change after selection. Revalidate again before order conversion or payment if the business requires a stricter guarantee.
- This integration does not create shipments, labels, carrier bookings, delivery estimates, taxes, or inventory reservations.
- Direct calls to `CheckoutManager::selectShipping()` can bypass this adapter's quote revalidation. Use `CheckoutShippingAdapter` for trusted Shipping-package quotes.
