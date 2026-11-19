<?php

namespace ItmoSoft\CommerceAdapters;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ItmoSoft\CommerceAdapters\Console\ImportLegacyProductVariants;
use ItmoSoft\CommerceAdapters\Listeners\InventoryOrderEventListener;
use ItmoSoft\CommerceAdapters\Listeners\PaymentOrderEventListener;
use ItmoSoft\CommerceAdapters\Models\CatalogProductVariant;
use ItmoSoft\CommerceAdapters\Services\CartInventoryAdapter;
use ItmoSoft\CommerceAdapters\Services\CartPricingAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutPromotionAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutShippingAdapter;
use ItmoSoft\CommerceAdapters\Services\CheckoutTaxAdapter;
use ItmoSoft\CommerceAdapters\Services\InventoryOrderAdapter;
use ItmoSoft\CommerceAdapters\Services\PaymentOrderAdapter;
use ItmoSoft\CommerceAdapters\Services\ProductCatalogVariantAdapter;
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

        if (config('itmosoft-commerce-adapters.product_catalog_variants.enabled', true)) {
            // Provider registration completes before providers are booted.
            // Route Catalog products to the shared Catalog-backed variant model.
            $productModel = config('product-catalog.models.product', \ItmoSoft\ProductCatalog\Models\Product::class);
            config()->set('product-variants.models.by_product.'.$productModel, CatalogProductVariant::class);
            config()->set('product-catalog.models.variant', CatalogProductVariant::class);

            if (! config('itmosoft-commerce-adapters.product_catalog_variants.standalone_models_enabled', false)) {
                config()->set('product-variants.migrations.enabled', false);
            }

            $this->app->singleton(ProductCatalogVariantAdapter::class);
            $this->app->alias(ProductCatalogVariantAdapter::class, 'itmosoft.commerce-adapters.product-catalog-variants');
        }
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/commerce-adapters.php' => config_path('itmosoft-commerce-adapters.php'),
        ], 'itmosoft-commerce-adapters-config');

        if (config('itmosoft-commerce-adapters.product_catalog_variants.enabled', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

            if ($this->app->runningInConsole()) {
                $this->commands([ImportLegacyProductVariants::class]);
            }
        }

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
