<?php

namespace ItmoSoft\CommerceAdapters;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ItmoSoft\CommerceAdapters\Listeners\InventoryOrderEventListener;
use ItmoSoft\CommerceAdapters\Listeners\PaymentOrderEventListener;
use ItmoSoft\CommerceAdapters\Services\CartPricingAdapter;
use ItmoSoft\CommerceAdapters\Services\CartInventoryAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutShippingAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutTaxAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter;
use ItmoSoft\CommerceAdapters\Services\InventoryOrderAdapter;
use ItmoSoft\CommerceAdapters\Services\PaymentOrderAdapter;
use ItmoSoft\Orders\Events\OrderStatusChanged;
use ItmoSoft\Payments\Events\PaymentCaptured;
use ItmoSoft\Payments\Events\PaymentFailed;
use ItmoSoft\Payments\Events\PaymentRefundUpdated;

class CommerceAdaptersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce-adapters.php', 'itmosoft-commerce-adapters');

        $this->app->singleton(CartPricingAdapter::class);
        $this->app->alias(CartPricingAdapter::class, 'itmosoft.commerce-adapters.cart-pricing');

        $this->app->singleton(CartInventoryAdapter::class);
        $this->app->alias(CartInventoryAdapter::class, 'itmosoft.commerce-adapters.cart-inventory');

        $this->app->singleton(CheckoutTaxAdapter::class);
        $this->app->alias(CheckoutTaxAdapter::class, 'itmosoft.commerce-adapters.checkout-tax');

        $this->app->singleton(CheckoutShippingAdapter::class);
        $this->app->alias(CheckoutShippingAdapter::class, 'itmosoft.commerce-adapters.checkout-shipping');

        $this->app->singleton(CheckoutOrderAdapter::class);
        $this->app->alias(CheckoutOrderAdapter::class, 'itmosoft.commerce-adapters.checkout-orders');

        $this->app->singleton(PaymentOrderAdapter::class);
        $this->app->alias(PaymentOrderAdapter::class, 'itmosoft.commerce-adapters.payment-orders');

        $this->app->singleton(InventoryOrderAdapter::class);
        $this->app->alias(InventoryOrderAdapter::class, 'itmosoft.commerce-adapters.inventory-orders');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/commerce-adapters.php' => config_path('itmosoft-commerce-adapters.php'),
        ], 'itmosoft-commerce-adapters-config');

        if (config('itmosoft-commerce-adapters.payments_orders.enabled', true)) {
            Event::listen(PaymentCaptured::class, PaymentOrderEventListener::class.'@handleCaptured');
            Event::listen(PaymentFailed::class, PaymentOrderEventListener::class.'@handleFailed');
            Event::listen(PaymentRefundUpdated::class, PaymentOrderEventListener::class.'@handleRefundUpdated');
        }

        if (config('itmosoft-commerce-adapters.inventory_orders.enabled', false)) {
            Event::listen(OrderStatusChanged::class, InventoryOrderEventListener::class.'@handle');
        }
    }
}
