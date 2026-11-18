<?php

namespace ItmoSoft\CommerceAdapters\Tests;

use ItmoSoft\Cart\CartServiceProvider;
use ItmoSoft\Checkout\CheckoutServiceProvider;
use ItmoSoft\CommerceAdapters\CommerceAdaptersServiceProvider;
use ItmoSoft\Orders\OrdersServiceProvider;
use ItmoSoft\Inventory\InventoryServiceProvider;
use ItmoSoft\Payments\PaymentsServiceProvider;
use ItmoSoft\Shipping\ShippingServiceProvider;
use ItmoSoft\Pricing\PricingServiceProvider;
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
            CommerceAdaptersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('itmosoft-cart.table_prefix', 'cart_');
        $app['config']->set('pricing.table_prefix', 'pricing_');
        $app['config']->set('pricing.default_currency', 'USD');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('itmosoft-checkout.rules', [
            'require_billing_address' => true,
            'require_shipping_address' => true,
            'require_shipping_method' => true,
        ]);
        $app['config']->set('itmosoft-commerce-adapters.checkout_orders.customer_type', 'user');
        $app['config']->set('itmosoft-commerce-adapters.payments_orders.enabled', true);
        $app['config']->set('itmosoft-commerce-adapters.inventory_orders.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}