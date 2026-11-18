<?php

namespace ItmoSoft\CommerceAdapters;

use Illuminate\Support\ServiceProvider;
use ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter;

class CommerceAdaptersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/commerce-adapters.php', 'itmosoft-commerce-adapters');

        $this->app->singleton(CheckoutOrderAdapter::class);
        $this->app->alias(CheckoutOrderAdapter::class, 'itmosoft.commerce-adapters.checkout-orders');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/commerce-adapters.php' => config_path('itmosoft-commerce-adapters.php'),
        ], 'itmosoft-commerce-adapters-config');
    }
}