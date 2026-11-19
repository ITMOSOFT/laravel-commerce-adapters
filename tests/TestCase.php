<?php

namespace ItmoSoft\CommerceAdapters\Tests;

use ItmoSoft\Cart\CartServiceProvider;
use ItmoSoft\Checkout\CheckoutServiceProvider;
use ItmoSoft\CommerceAdapters\CommerceAdaptersServiceProvider;
use ItmoSoft\Inventory\InventoryServiceProvider;
use ItmoSoft\Orders\OrdersServiceProvider;
use ItmoSoft\Payments\PaymentsServiceProvider;
use ItmoSoft\Pricing\PricingServiceProvider;
use ItmoSoft\ProductCatalog\ProductCatalogServiceProvider;
use ItmoSoft\ProductVariants\ProductVariantsServiceProvider;
use ItmoSoft\Promotions\PromotionsServiceProvider;
use ItmoSoft\Shipping\ShippingServiceProvider;
use ItmoSoft\Tax\TaxServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CartServiceProvider::class,
            PricingServiceProvider::class,
            CheckoutServiceProvider::class,
            OrdersServiceProvider::class,
            InventoryServiceProvider::class,
            PaymentsServiceProvider::class,
            ShippingServiceProvider::class,
            TaxServiceProvider::class,
            PromotionsServiceProvider::class,
            ProductCatalogServiceProvider::class,
            ProductVariantsServiceProvider::class,
            CommerceAdaptersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $app['config']->set('itmosoft-cart.table_prefix', 'cart_');
        $app['config']->set('pricing.table_prefix', 'pricing_');
        $app['config']->set('pricing.default_currency', 'USD');
        $app['config']->set('promotions.table_prefix', 'promotion_');
        $app['config']->set('product-catalog.table_prefix', 'catalog_');
        $app['config']->set('product-variants.table_prefix', 'itmosoft_');
        $app['config']->set('itmosoft-checkout.rules', [
            'require_billing_address' => true, 'require_shipping_address' => true, 'require_shipping_method' => true,
        ]);
        $app['config']->set('itmosoft-commerce-adapters.checkout_orders.customer_type', 'user');
        $app['config']->set('itmosoft-commerce-adapters.payments_orders.enabled', true);
        $app['config']->set('itmosoft-commerce-adapters.inventory_orders.enabled', true);
        $app['config']->set('itmosoft-commerce-adapters.product_catalog_variants.enabled', true);
        $app['config']->set('itmosoft-commerce-adapters.product_catalog_variants.standalone_models_enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}
