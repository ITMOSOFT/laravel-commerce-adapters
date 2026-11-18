<?php

namespace ItmoSoft\CommerceAdapters\Tests;

use ItmoSoft\Checkout\CheckoutServiceProvider;
use ItmoSoft\CommerceAdapters\CommerceAdaptersServiceProvider;
use ItmoSoft\Orders\OrdersServiceProvider;
use ItmoSoft\Payments\PaymentsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CheckoutServiceProvider::class,
            OrdersServiceProvider::class,
            PaymentsServiceProvider::class,
            CommerceAdaptersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
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
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'testing'])->run();
    }
}