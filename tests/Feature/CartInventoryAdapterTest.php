<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItmoSoft\Cart\Models\Cart;
use ItmoSoft\Cart\Services\CartManager;
use ItmoSoft\CommerceAdapters\Exceptions\CartInventoryException;
use ItmoSoft\CommerceAdapters\Services\CartInventoryAdapter;
use ItmoSoft\CommerceAdapters\Tests\Fixtures\Product;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Inventory\Models\StockItem;
use ItmoSoft\Inventory\Models\StockLocation;
use ItmoSoft\Inventory\Services\InventoryManager;

class CartInventoryAdapterTest extends TestCase
{
    protected StockLocation $location;
    protected Product $product;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->timestamps();
        });

        $this->location = StockLocation::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $this->product = Product::query()->create(['name' => 'Test product', 'price' => 12.50]);
        $this->cart = app(CartManager::class)->create(userId: 10, currency: 'USD');

        config()->set('itmosoft-commerce-adapters.cart_inventory.location_id', $this->location->getKey());
    }

    protected function receiveStock(int $quantity): void
    {
        app(InventoryManager::class)->receive($this->product, $this->location, $quantity, 'CART-TEST-RECEIPT');
    }

    protected function stock(): StockItem
    {
        return StockItem::query()
            ->where('inventoryable_type', $this->product->getMorphClass())
            ->where('inventoryable_id', $this->product->getKey())
            ->where('stock_location_id', $this->location->getKey())
            ->firstOrFail();
    }

    public function test_adds_item_when_resulting_cart_demand_is_available_without_reserving_stock(): void
    {
        $this->receiveStock(5);

        $line = app(CartInventoryAdapter::class)->addAvailableItem(
            cart: $this->cart,
            item: $this->product,
            quantity: 2,
            options: ['size' => 'M'],
        );

        $this->assertSame(2, $line->quantity);
        $this->assertSame(1, $this->cart->items()->count());
        $this->assertSame(5, $this->stock()->quantity_on_hand);
        $this->assertSame(0, $this->stock()->quantity_reserved);
    }

    public function test_rejects_addition_when_available_stock_is_insufficient(): void
    {
        $this->receiveStock(1);

        try {
            app(CartInventoryAdapter::class)->addAvailableItem($this->cart, $this->product, 2);
            $this->fail('Expected insufficient stock to reject the cart addition.');
        } catch (CartInventoryException $exception) {
            $this->assertStringContainsString('cart requests 2', $exception->getMessage());
        }

        $this->assertSame(0, $this->cart->items()->count());
        $this->assertSame(0, $this->stock()->quantity_reserved);
    }

    public function test_counts_same_product_across_different_option_lines(): void
    {
        $this->receiveStock(3);
        $adapter = app(CartInventoryAdapter::class);
        $adapter->addAvailableItem($this->cart, $this->product, 2, options: ['size' => 'S']);

        $this->expectException(CartInventoryException::class);
        $adapter->addAvailableItem($this->cart->fresh(), $this->product, 2, options: ['size' => 'L']);
    }

    public function test_updates_line_quantity_only_when_total_product_demand_remains_available(): void
    {
        $this->receiveStock(4);
        $adapter = app(CartInventoryAdapter::class);
        $line = $adapter->addAvailableItem($this->cart, $this->product, 2, options: ['size' => 'S']);
        $adapter->addAvailableItem($this->cart->fresh(), $this->product, 1, options: ['size' => 'L']);

        try {
            $adapter->setAvailableQuantity($line->fresh(), 4);
            $this->fail('Expected quantity update to exceed total available stock.');
        } catch (CartInventoryException $exception) {
            $this->assertStringContainsString('cart requests 5', $exception->getMessage());
        }

        $this->assertSame(2, (int) $line->fresh()->quantity);
    }

    public function test_can_reduce_cart_line_quantity(): void
    {
        $this->receiveStock(5);
        $adapter = app(CartInventoryAdapter::class);
        $line = $adapter->addAvailableItem($this->cart, $this->product, 4);

        $updated = $adapter->setAvailableQuantity($line, 2);

        $this->assertSame(2, $updated->quantity);
    }

    public function test_reports_available_quantity_for_selected_location(): void
    {
        $this->receiveStock(7);

        $this->assertSame(7, app(CartInventoryAdapter::class)->availableQuantity($this->product));
    }

    public function test_requires_a_configured_or_explicit_location(): void
    {
        config()->set('itmosoft-commerce-adapters.cart_inventory.location_id', null);

        $this->expectException(CartInventoryException::class);
        app(CartInventoryAdapter::class)->addAvailableItem($this->cart, $this->product, 1);
    }

    public function test_rejects_inactive_stock_location(): void
    {
        $this->location->is_active = false;
        $this->location->save();
        $this->receiveStock(5);

        $this->expectException(CartInventoryException::class);
        app(CartInventoryAdapter::class)->addAvailableItem($this->cart, $this->product, 1);
    }
}
