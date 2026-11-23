<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItmoSoft\Cart\Models\Cart;
use ItmoSoft\CommerceAdapters\Exceptions\CartPricingException;
use ItmoSoft\CommerceAdapters\Services\CartPricingAdapter;
use ItmoSoft\CommerceAdapters\Tests\Fixtures\PricingProduct;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Pricing\Models\Price;

class CartPricingAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('adapter_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->timestamps();
        });
    }

    protected function product(): PricingProduct
    {
        return PricingProduct::query()->create(['name' => 'Adapter Product', 'sku' => 'ADP-1']);
    }

    protected function price(PricingProduct $product, array $attributes = []): Price
    {
        return $product->prices()->create(array_merge([
            'currency' => 'USD',
            'amount' => '100.0000',
            'min_quantity' => 1,
            'priority' => 0,
            'is_active' => true,
        ], $attributes));
    }

    protected function cart(string $currency = 'USD'): Cart
    {
        return app(\ItmoSoft\Cart\Services\CartManager::class)->create(userId: 42, currency: $currency);
    }

    public function test_adds_item_using_resolved_price_and_persists_price_snapshot(): void
    {
        $product = $this->product();
        $price = $this->price($product, ['amount' => '49.9500', 'compare_at_amount' => '59.9900']);
        $cart = $this->cart();

        $item = app(CartPricingAdapter::class)->addPricedItem($cart, $product, 2);

        $this->assertSame(2, $item->quantity);
        $this->assertSame('49.95', $item->unit_price);
        $this->assertSame('99.90', $item->line_total);
        $this->assertSame($price->id, $item->price_snapshot['price_id']);
        $this->assertSame('49.9500', $item->price_snapshot['resolved_amount']);
        $this->assertSame('itmosoft-laravel-pricing', $item->price_snapshot['source']);
    }

    public function test_quantity_break_is_resolved_for_combined_cart_line_quantity(): void
    {
        $product = $this->product();
        $this->price($product, ['amount' => '100.0000']);
        $bulk = $this->price($product, ['amount' => '80.0000', 'min_quantity' => 3]);
        $cart = $this->cart();
        $adapter = app(CartPricingAdapter::class);

        $adapter->addPricedItem($cart, $product, 1);
        $line = $adapter->addPricedItem($cart, $product, 2);

        $this->assertSame(3, $line->quantity);
        $this->assertSame('80.00', $line->unit_price);
        $this->assertSame('240.00', $line->line_total);
        $this->assertSame($bulk->id, $line->price_snapshot['price_id']);
        $this->assertSame(3, $line->price_snapshot['quantity']);
    }

    public function test_context_specific_price_is_used_when_context_matches(): void
    {
        $product = $this->product();
        $this->price($product, ['amount' => '100.0000']);
        $wholesale = $this->price($product, [
            'amount' => '75.0000',
            'context' => ['customer_group' => 'wholesale'],
        ]);
        $cart = $this->cart();

        $item = app(CartPricingAdapter::class)->addPricedItem(
            $cart,
            $product,
            1,
            context: ['customer_group' => 'wholesale'],
        );

        $this->assertSame('75.00', $item->unit_price);
        $this->assertSame($wholesale->id, $item->price_snapshot['price_id']);
    }

    public function test_uses_cart_currency_and_rejects_missing_currency_price(): void
    {
        $product = $this->product();
        $this->price($product, ['currency' => 'EUR', 'amount' => '20.0000']);
        $cart = $this->cart('USD');

        $this->expectException(CartPricingException::class);
        app(CartPricingAdapter::class)->addPricedItem($cart, $product, 1);
    }

    public function test_inactive_and_future_prices_are_not_applied(): void
    {
        $product = $this->product();
        $this->price($product, ['is_active' => false]);
        $this->price($product, ['starts_at' => now()->addDay()]);
        $cart = $this->cart();

        $this->expectException(CartPricingException::class);
        app(CartPricingAdapter::class)->addPricedItem($cart, $product, 1);
    }

    public function test_rounds_four_decimal_pricing_amount_to_cart_two_decimal_contract(): void
    {
        $product = $this->product();
        $price = $this->price($product, ['amount' => '12.3456']);
        $cart = $this->cart();

        $item = app(CartPricingAdapter::class)->addPricedItem($cart, $product, 1);

        $this->assertSame('12.35', $item->unit_price);
        $this->assertSame('12.3456', $item->price_snapshot['resolved_amount']);
        $this->assertSame('12.35', $item->price_snapshot['cart_unit_price']);
        $this->assertSame($price->id, $item->price_snapshot['price_id']);
    }

    public function test_negative_price_is_rejected(): void
    {
        $product = $this->product();
        $this->price($product, ['amount' => '-1.0000']);
        $cart = $this->cart();

        $this->expectException(CartPricingException::class);
        app(CartPricingAdapter::class)->addPricedItem($cart, $product, 1);
    }

    public function test_different_options_keep_separate_cart_lines_and_resolve_independently(): void
    {
        $product = $this->product();
        $this->price($product, ['amount' => '25.0000']);
        $cart = $this->cart();
        $adapter = app(CartPricingAdapter::class);

        $first = $adapter->addPricedItem($cart, $product, 1, options: ['size' => 'M']);
        $second = $adapter->addPricedItem($cart, $product, 1, options: ['size' => 'L']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $cart->items()->count());
    }
    
    
public function test_half_up_rounding_uses_money_manager_for_pricing_amounts(): void
{
    $product = $this->product();
    $this->price($product, ['amount' => '1.0050']);
    $cart = $this->cart();

    $item = app(CartPricingAdapter::class)->addPricedItem($cart, $product);

    $this->assertSame('1.01', $item->unit_price);
    $this->assertSame('1.01', $item->line_total);
    $this->assertSame('1.0050', $item->price_snapshot['resolved_amount']);
}

public function test_cart_rejects_currency_with_unsupported_precision(): void
{
    $product = $this->product();
    $this->price($product, ['currency' => 'JPY', 'amount' => '100']);
    $cart = Cart::query()->create([
        'public_id' => (string) \Illuminate\Support\Str::uuid(),
        'user_id' => 42,
        'session_id' => null,
        'currency' => 'JPY',
        'status' => 'active',
        'metadata' => [],
    ]);

    $this->expectException(CartPricingException::class);

    app(CartPricingAdapter::class)->addPricedItem($cart, $product);
}

}
