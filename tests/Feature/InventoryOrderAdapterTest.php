<?php

namespace ItmoSoft\CommerceAdapters\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use ItmoSoft\CommerceAdapters\Exceptions\InventoryOrderSyncException;
use ItmoSoft\CommerceAdapters\Services\InventoryOrderAdapter;
use ItmoSoft\CommerceAdapters\Tests\Fixtures\Product;
use ItmoSoft\CommerceAdapters\Tests\TestCase;
use ItmoSoft\Inventory\Models\StockItem;
use ItmoSoft\Inventory\Models\StockLocation;
use ItmoSoft\Inventory\Models\StockReservation;
use ItmoSoft\Inventory\Services\InventoryManager;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Services\OrderService;
use ItmoSoft\Payments\Services\PaymentManager;

class InventoryOrderAdapterTest extends TestCase
{
    protected StockLocation $location;
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->location = StockLocation::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $this->product = Product::query()->create(['name' => 'Test product']);

        config()->set('itmosoft-commerce-adapters.inventory_orders.enabled', true);
        config()->set('itmosoft-commerce-adapters.inventory_orders.location_id', $this->location->getKey());
    }

    protected function makeOrder(int|float $quantity = 2): Order
    {
        return app(OrderService::class)->create([
            'currency' => 'USD',
            'subtotal' => 20,
            'grand_total' => 20,
            'items' => [[
                'product_type' => Product::class,
                'product_id' => $this->product->getKey(),
                'name' => 'Test product',
                'quantity' => $quantity,
                'unit_price' => 10,
                'line_total' => $quantity * 10,
            ]],
        ]);
    }

    protected function receiveStock(int $quantity): void
    {
        app(InventoryManager::class)->receive($this->product, $this->location, $quantity, 'TEST-RECEIPT');
    }

    protected function stock(): StockItem
    {
        return StockItem::query()
            ->where('inventoryable_type', $this->product->getMorphClass())
            ->where('inventoryable_id', $this->product->getKey())
            ->where('stock_location_id', $this->location->getKey())
            ->firstOrFail();
    }

    public function test_processing_reserves_inventory_for_order_items(): void
    {
        $this->receiveStock(10);
        $order = $this->makeOrder(2);

        app(OrderService::class)->changeStatus($order, 'processing');

        $this->assertSame(10, $this->stock()->quantity_on_hand);
        $this->assertSame(2, $this->stock()->quantity_reserved);
        $this->assertSame(1, StockReservation::query()->where('reference', "itmosoft-order:{$order->id}:item:{$order->items()->first()->id}")->count());
    }

    public function test_repeated_processing_event_does_not_duplicate_reservation(): void
    {
        $this->receiveStock(10);
        $order = $this->makeOrder(2);
        $adapter = app(InventoryOrderAdapter::class);

        $adapter->reserveOrder($order, $this->location);
        $adapter->reserveOrder($order->fresh(), $this->location);

        $this->assertSame(2, $this->stock()->quantity_reserved);
        $this->assertSame(1, StockReservation::query()->count());
    }

    public function test_cancellation_releases_active_reservation(): void
    {
        $this->receiveStock(10);
        $order = $this->makeOrder(3);
        $service = app(OrderService::class);
        $service->changeStatus($order, 'processing');
        $service->changeStatus($order->fresh(), 'cancelled');

        $this->assertSame(10, $this->stock()->quantity_on_hand);
        $this->assertSame(0, $this->stock()->quantity_reserved);
        $this->assertSame('released', StockReservation::query()->firstOrFail()->status);
    }

    public function test_completion_fulfills_reservation_and_decrements_on_hand_stock(): void
    {
        $this->receiveStock(10);
        $order = $this->makeOrder(4);
        $service = app(OrderService::class);
        $service->changeStatus($order, 'processing');
        $service->changeStatus($order->fresh(), 'completed');

        $this->assertSame(6, $this->stock()->quantity_on_hand);
        $this->assertSame(0, $this->stock()->quantity_reserved);
        $this->assertSame('fulfilled', StockReservation::query()->firstOrFail()->status);
    }

    public function test_insufficient_stock_rolls_back_processing_transition(): void
    {
        $this->receiveStock(1);
        $order = $this->makeOrder(2);

        try {
            app(OrderService::class)->changeStatus($order, 'processing');
            $this->fail('Expected insufficient stock to block processing.');
        } catch (InventoryOrderSyncException $exception) {
            $this->assertStringContainsString('Insufficient stock', $exception->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $this->stock()->quantity_reserved);
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_insufficient_stock_on_a_later_line_rolls_back_earlier_reservations(): void
    {
        $secondProduct = Product::query()->create(['name' => 'Second product']);
        app(InventoryManager::class)->receive($this->product, $this->location, 5, 'FIRST-RECEIPT');
        app(InventoryManager::class)->receive($secondProduct, $this->location, 1, 'SECOND-RECEIPT');

        $order = app(OrderService::class)->create([
            'currency' => 'USD',
            'subtotal' => 30,
            'grand_total' => 30,
            'items' => [
                [
                    'product_type' => Product::class,
                    'product_id' => $this->product->getKey(),
                    'name' => 'Test product',
                    'quantity' => 2,
                    'unit_price' => 10,
                    'line_total' => 20,
                ],
                [
                    'product_type' => Product::class,
                    'product_id' => $secondProduct->getKey(),
                    'name' => 'Second product',
                    'quantity' => 2,
                    'unit_price' => 5,
                    'line_total' => 10,
                ],
            ],
        ]);

        try {
            app(OrderService::class)->changeStatus($order, 'processing');
            $this->fail('Expected insufficient stock on the second line to block processing.');
        } catch (InventoryOrderSyncException $exception) {
            $this->assertStringContainsString('Insufficient stock', $exception->getMessage());
        }

        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $this->stock()->quantity_reserved);
        $secondStock = StockItem::query()
            ->where('inventoryable_type', $secondProduct->getMorphClass())
            ->where('inventoryable_id', $secondProduct->getKey())
            ->where('stock_location_id', $this->location->getKey())
            ->firstOrFail();
        $this->assertSame(0, $secondStock->quantity_reserved);
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_insufficient_stock_does_not_rollback_captured_payment(): void
    {
        $this->receiveStock(1);
        $order = $this->makeOrder(2);
        $payment = app(PaymentManager::class)->create([
            'order_id' => $order->getKey(),
            'amount' => 20,
            'currency' => 'USD',
            'gateway' => 'test',
        ]);

        $captured = app(PaymentManager::class)->capture($payment);

        $this->assertSame('captured', $captured->fresh()->status);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(0, $this->stock()->quantity_reserved);
    }

    public function test_fractional_inventory_quantity_is_rejected_without_transition(): void
    {
        $this->receiveStock(10);
        $order = $this->makeOrder(1.5);

        $this->expectException(InventoryOrderSyncException::class);
        app(OrderService::class)->changeStatus($order, 'processing');
    }

    public function test_pending_order_can_be_cancelled_without_a_reservation(): void
    {
        $order = $this->makeOrder(2);
        app(OrderService::class)->changeStatus($order, 'cancelled');

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
    }

    public function test_non_inventory_order_lines_are_ignored(): void
    {
        $order = app(OrderService::class)->create([
            'currency' => 'USD',
            'subtotal' => 5,
            'grand_total' => 5,
            'items' => [[
                'name' => 'Consulting service',
                'quantity' => 1,
                'unit_price' => 5,
            ]],
        ]);

        app(OrderService::class)->changeStatus($order, 'processing');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(0, StockReservation::query()->count());
    }
}
