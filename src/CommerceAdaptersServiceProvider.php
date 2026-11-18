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
use ItmoSoft\CommerceAdapters\Services\CheckoutPromotionAdapter;
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

        foreach ([
            CartPricingAdapter::class => 'cart-pricing',
            CartInventoryAdapter::class => 'cart-inventory',
            CheckoutTaxAdapter::class => 'checkout-tax',
            CheckoutShippingAdapter::class => 'checkout-shipping',
            CheckoutPromotionAdapter::class => 'checkout-promotions',
            CheckoutOrderAdapter::class => 'checkout-orders',
            PaymentOrderAdapter::class => 'payment-orders',
            InventoryOrderAdapter::class => 'inventory-orders',
        ] as $class => $alias) {
            $this->app->singleton($class);
            $this->app->alias($class, 'itmosoft.commerce-adapters.'.$alias);
        }
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
