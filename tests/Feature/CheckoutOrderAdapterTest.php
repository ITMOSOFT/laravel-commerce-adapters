<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Support\Facades\DB;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\CommerceAdapters\Services\CheckoutOrderAdapter;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Orders\Models\Order;
use InvalidArgumentException;
use RuntimeException;

class CheckoutOrderAdapterTest extends TestCase
{
    protected function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function makeSession(array $overrides = []): CheckoutSession
    {
        // The minimum supported Checkout release does not cast these fields.
        // Encode them explicitly so the fixtures work with both old and new models.
        $attributes = array_replace([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'cart_id' => 55,
            'user_id' => 7,
            'status' => 'pending',
            'currency' => 'USD',
            'cart_snapshot' => [[
                'cart_item_id' => 9,
                'itemable_type' => 'catalog_product',
                'itemable_id' => 42,
                'name' => 'Test product',
                'sku' => 'TEST-42',
                'quantity' => 2,
                'unit_price' => '12.5000',
                'line_total' => '25.0000',
                'currency' => 'USD',
                'options' => ['color' => 'blue'],
            ]],
            'billing_address' => [
                'name' => 'Buyer',
                'address_line_1' => '1 Main Street',
                'city' => 'Multan',
                'country' => 'Pakistan',
            ],
            'shipping_address' => [
                'name' => 'Buyer',
                'address_line_1' => '1 Main Street',
                'city' => 'Multan',
                'country' => 'Pakistan',
            ],
            'shipping_method' => 'standard',
            'shipping_details' => ['carrier' => 'Test Carrier'],
            'totals' => [
                'subtotal' => '25.0000',
                'discount' => '2.0000',
                'tax' => '1.0000',
                'shipping' => '3.0000',
                'grand_total' => '27.0000',
            ],
            'metadata' => ['campaign' => 'winter'],
        ], $overrides);

        $model = new CheckoutSession();

        foreach (['shipping_details', 'metadata'] as $jsonColumn) {
            if (
                is_array($attributes[$jsonColumn] ?? null)
                && ! $model->hasCast($jsonColumn)
            ) {
                $attributes[$jsonColumn] = json_encode(
                    $attributes[$jsonColumn],
                    JSON_THROW_ON_ERROR
                );
            }
        }

        return CheckoutSession::query()->create($attributes);
    }

    public function test_it_converts_checkout_snapshot_and_completes_session(): void
    {
        $session = $this->makeSession();

        $order = app(CheckoutOrderAdapter::class)->createOrder($session);

        $this->assertSame('completed', $session->fresh()->status);
        $this->assertNotNull($session->fresh()->completed_at);
        $this->assertSame('USD', $order->currency);
        $this->assertSame('25.0000', $order->subtotal);
        $this->assertSame('2.0000', $order->discount_total);
        $this->assertSame('1.0000', $order->tax_total);
        $this->assertSame('3.0000', $order->shipping_total);
        $this->assertSame('27.0000', $order->grand_total);
        $this->assertSame($session->billing_address, $order->billing_address);
        $this->assertSame($session->shipping_address, $order->shipping_address);
        $this->assertSame(1, $order->items()->count());

        $item = $order->items()->firstOrFail();
        $this->assertSame('catalog_product', $item->product_type);
        $this->assertSame(42, (int) $item->product_id);
        $this->assertSame('TEST-42', $item->sku);
        $this->assertSame('Test product', $item->name);
        $this->assertSame('blue', $item->metadata['options']['color']);

        $freshMetadata = $this->decodedArray($session->fresh()->metadata);
        $this->assertSame(
            $order->getKey(),
            $freshMetadata['itmosoft_commerce_adapters']['order_id']
        );
        $this->assertSame('winter', $order->metadata['checkout_metadata']['campaign']);
    }

    public function test_repeated_conversion_returns_same_order_without_duplicates(): void
    {
        $session = $this->makeSession();
        $adapter = app(CheckoutOrderAdapter::class);

        $first = $adapter->createOrder($session);
        $second = $adapter->createOrder($session->fresh());

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_it_rejects_checkout_without_snapshot_items(): void
    {
        $session = $this->makeSession(['cart_snapshot' => []]);

        $this->expectException(InvalidArgumentException::class);
        app(CheckoutOrderAdapter::class)->createOrder($session);
    }

    public function test_it_rejects_invalid_snapshot_item(): void
    {
        $session = $this->makeSession(['cart_snapshot' => [[
            'name' => '',
            'quantity' => 1,
            'unit_price' => '10.00',
        ]]]);

        $this->expectException(InvalidArgumentException::class);
        app(CheckoutOrderAdapter::class)->createOrder($session);
    }

    public function test_failed_conversion_rolls_back_order_and_checkout_metadata(): void
    {
        $session = $this->makeSession(['totals' => [
            'subtotal' => '-1.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'shipping' => '0.00',
            'grand_total' => '-1.00',
        ]]);

        try {
            app(CheckoutOrderAdapter::class)->createOrder($session);
            $this->fail('Expected invalid order totals to fail.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Order::query()->count());
            $fresh = $session->fresh();
            $this->assertSame('pending', $fresh->status);
            $this->assertArrayNotHasKey(
                'itmosoft_commerce_adapters',
                $this->decodedArray($fresh->metadata)
            );
        }
    }

    public function test_completed_session_without_adapter_order_reference_is_not_reconverted(): void
    {
        $session = $this->makeSession(['status' => 'completed', 'completed_at' => now()]);

        $this->expectException(RuntimeException::class);
        app(CheckoutOrderAdapter::class)->createOrder($session);
    }
}