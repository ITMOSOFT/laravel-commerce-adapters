# Checkout → Promotions

`CheckoutPromotionAdapter` connects `itmosoft/laravel-promotions` to `itmosoft/laravel-checkout` without writing to Promotions tables directly.

## Apply a code

```php
use ItmoSoft\CommerceAdapters\Services\CheckoutPromotionAdapter;

$snapshot = app(CheckoutPromotionAdapter::class)->applyCode(
    session: $checkoutSession,
    code: 'WELCOME10',
    context: ['region' => 'PK', 'customer_tier' => 'gold'],
    customer: auth()->user(), // optional persisted Eloquent model
    targets: [$product],      // optional persisted models when promotion target restrictions are configured
);
```

The adapter calls `PromotionService::calculate()` and updates Checkout's discount total. It stores a snapshot under `metadata.itmosoft_promotion` by default. It does not record a redemption or reserve usage.

## Remove a code

```php
app(CheckoutPromotionAdapter::class)->removePromotion($checkoutSession);
```

The adapter only clears a discount it previously recorded in its own metadata. It refuses to overwrite a pre-existing discount that it cannot identify as its own.

## Redeem at order creation

Call redemption within the same outer database transaction as order creation. If you use `CheckoutOrderAdapter::createOrder()`, it completes the checkout session; call `redeemForOrder()` immediately after it returns, still inside your outer transaction, and pass the created order's ID:

```php
DB::transaction(function () use ($checkoutSession) {
    $order = app(\ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter::class)
        ->createOrder($checkoutSession);

    // If a customer or item targets were supplied to applyCode(), pass those
    // same persisted models here as well.
    app(\ItmoSoft\CommerceAdapters\Services\CheckoutPromotionAdapter::class)
        ->redeemForOrder($checkoutSession->fresh(), (string) $order->getKey());
});
```

The customer and target models must match those supplied when applying the code. For a completed session, its Checkout → Orders metadata link must match the supplied order ID. Repeated calls for the same promotion/order reference return the existing redemption. The Promotions package enforces usage limits during `redeem()`. The adapter also compares the redeemed discount with the amount originally applied and aborts if promotion terms changed in between.

## Important boundaries

- The adapter supports one promotion at a time; promotions do not stack.
- Checkout's single `discount` total is treated as owned by this adapter while its promotion snapshot exists. A pre-existing unowned discount is never silently overwritten.
- `PromotionService::calculate()` does not check total or per-customer usage caps. Those are checked only by `redeem()`. A code can calculate at checkout and fail redemption later if its usage cap is exhausted in the meantime. This adapter does not reserve coupon capacity.
- Apply and redeem must use the same default database connection as Checkout and Promotions. For atomic order/redemption behavior, call `redeemForOrder()` inside the order-creation transaction.
- Pass the same eligible target models at apply and redeem time when the promotion has item-level target restrictions.
- Promotion context is supplied by the application; the adapter does not infer customer tiers, region, channel, or other business eligibility data.
- Promotions currently calculate in major currency units and round discounts to two decimals. This adapter preserves that package contract; it does not provide currency-exponent-aware money arithmetic.
- The adapter does not implement stacking, coupon reservations, refunds/reversals, or automatic redemption from an order event.
