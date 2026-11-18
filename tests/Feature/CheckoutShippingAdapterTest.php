<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Exceptions\CheckoutShippingException;
use ItmoSoft\CommerceAdapters\Services\CheckoutShippingAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Shipping\Models\ShippingMethod;
use ItmoSoft\Shipping\Models\ShippingRate;
use ItmoSoft\Shipping\Models\ShippingZone;
use ItmoSoft\Shipping\Models\ShippingZoneRule;
use Illuminate\Support\Str;

class CheckoutShippingAdapterTest extends TestCase
{
    protected ShippingMethod $method;
    protected ShippingZone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = ShippingMethod::query()->create([
            'name' => 'Standard Delivery',
            'code' => 'standard',
            'is_active' => true,
        ]);
        $this->zone = ShippingZone::query()->create([
            'name' => 'Pakistan',
            'is_active' => true,
        ]);
        ShippingZoneRule::query()->create([
            'zone_id' => $this->zone->getKey(),
            'type' => 'country',
            'value' => 'PK',
        ]);
        ShippingRate::query()->create([
            'shipping_method_id' => $this->method->getKey(),
            'zone_id' => $this->zone->getKey(),
            'type' => 'flat',
            'amount' => '500.0000',
            'currency' => 'PKR',
        ]);
    }

    protected function makeSession(array $overrides = []): CheckoutSession
    {
        $attributes = array_replace([
            'public_id' => (string) Str::uuid(),
            'cart_id' => 101,
            'user_id' => 7,
            'status' => 'pending',
            'currency' => 'PKR',
            'cart_snapshot' => [[
                'cart_item_id' => 1,
                'itemable_type' => 'catalog_product',
                'itemable_id' => 42,
                'name' => 'Test product',
                'quantity' => 2,
                'unit_price' => '1000.00',
                'line_total' => '2000.00',
                'currency' => 'PKR',
                'options' => [],
            ]],
            'shipping_address' => [
                'name' => 'Buyer',
                'address_line_1' => '1 Main Street',
                'city' => 'Multan',
                'region' => 'Punjab',
                'postal_code' => '60000',
                'country' => 'PK',
            ],
            'totals' => [
                'subtotal' => '2000.00',
                'discount' => '0.00',
                'tax' => '0.00',
                'shipping' => '0.00',
                'grand_total' => '2000.00',
            ],
        ], $overrides);

        $model = new CheckoutSession();
        foreach (['shipping_address', 'totals', 'cart_snapshot'] as $column) {
            if (is_array($attributes[$column] ?? null) && ! $model->hasCast($column)) {
                $attributes[$column] = json_encode($attributes[$column], JSON_THROW_ON_ERROR);
            }
        }

        return CheckoutSession::query()->create($attributes);
    }

    public function test_quotes_shipping_methods_for_checkout_destination_and_currency(): void
    {
        $session = $this->makeSession();

        $quotes = app(CheckoutShippingAdapter::class)->quoteForCheckout($session, 2.5);

        $this->assertCount(1, $quotes);
        $this->assertSame((int) $this->method->getKey(), $quotes[0]['method_id']);
        $this->assertSame('standard', $quotes[0]['code']);
        $this->assertSame('500.00', $quotes[0]['amount']);
        $this->assertSame('PKR', $quotes[0]['currency']);
    }

    public function test_selects_a_fresh_quote_and_updates_checkout_totals(): void
    {
        $session = $this->makeSession();
        $adapter = app(CheckoutShippingAdapter::class);
        $quote = $adapter->quoteForCheckout($session)[0];

        $updated = $adapter->selectQuote($session, $quote);

        $this->assertSame('standard', $updated->shipping_method);
        $this->assertSame('500.00', $updated->totals['shipping']);
        $this->assertSame('2500.00', $updated->totals['grand_total']);
        $this->assertSame('itmosoft-laravel-shipping', $updated->shipping_details['source']);
        $this->assertSame((int) $this->method->getKey(), $updated->shipping_details['shipping_method_id']);
        $this->assertSame('PK', $updated->shipping_details['destination']['country']);
    }

    public function test_calculates_weight_based_rate_using_supplied_packed_weight(): void
    {
        $weightMethod = ShippingMethod::query()->create([
            'name' => 'Express by Weight',
            'code' => 'express-weight',
            'is_active' => true,
        ]);
        ShippingRate::query()->create([
            'shipping_method_id' => $weightMethod->getKey(),
            'zone_id' => $this->zone->getKey(),
            'type' => 'weight',
            'amount' => '100.0000',
            'currency' => 'PKR',
        ]);

        $quotes = app(CheckoutShippingAdapter::class)->quoteForCheckout($this->makeSession(), 2.5);
        $weightQuote = collect($quotes)->firstWhere('code', 'express-weight');

        $this->assertNotNull($weightQuote);
        $this->assertSame('250.00', $weightQuote['amount']);
    }

    public function test_rejects_client_modified_quote_amount(): void
    {
        $session = $this->makeSession();
        $adapter = app(CheckoutShippingAdapter::class);
        $quote = $adapter->quoteForCheckout($session)[0];
        $quote['amount'] = '1.00';

        $this->expectException(CheckoutShippingException::class);
        $this->expectExceptionMessage('no longer available or has changed');
        $adapter->selectQuote($session, $quote);
    }

    public function test_rejects_quote_when_rate_changes_after_it_was_requested(): void
    {
        $session = $this->makeSession();
        $adapter = app(CheckoutShippingAdapter::class);
        $quote = $adapter->quoteForCheckout($session)[0];

        $rate = ShippingRate::query()->firstOrFail();
        $rate->amount = '650.0000';
        $rate->save();

        $this->expectException(CheckoutShippingException::class);
        $adapter->selectQuote($session, $quote);
    }

    public function test_does_not_quote_without_a_shipping_country(): void
    {
        $session = $this->makeSession(['shipping_address' => []]);

        $this->expectException(CheckoutShippingException::class);
        $this->expectExceptionMessage('country is required');
        app(CheckoutShippingAdapter::class)->quoteForCheckout($session);
    }

    public function test_does_not_quote_for_completed_checkout(): void
    {
        $session = $this->makeSession(['status' => 'completed']);

        $this->expectException(CheckoutShippingException::class);
        app(CheckoutShippingAdapter::class)->quoteForCheckout($session);
    }

    public function test_returns_no_quotes_when_no_shipping_zone_matches(): void
    {
        $session = $this->makeSession(['shipping_address' => [
            'name' => 'Buyer',
            'address_line_1' => '1 Main Street',
            'city' => 'London',
            'country' => 'GB',
        ]]);

        $this->assertSame([], app(CheckoutShippingAdapter::class)->quoteForCheckout($session));
    }

    public function test_rejects_invalid_negative_weight(): void
    {
        $session = $this->makeSession();

        $this->expectException(\InvalidArgumentException::class);
        app(CheckoutShippingAdapter::class)->quoteForCheckout($session, -1);
    }
}
