<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\CommerceAdapters\DTOs\InventoryOrderSyncResult;
use ItmoSoft\CommerceAdapters\Exceptions\InventoryOrderSyncException;
use ItmoSoft\Inventory\Exceptions\InsufficientStockException;
use ItmoSoft\Inventory\Models\StockLocation;
use ItmoSoft\Inventory\Models\StockReservation;
use ItmoSoft\Inventory\Services\InventoryManager;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Models\OrderItem;

class InventoryOrderAdapter
{
    public function __construct(protected InventoryManager $inventory)
    {
    }

    /** Reserve every stock-tracked order line. Safe to call repeatedly. */
    public function reserveOrder(Order $order, StockLocation|int|null $location = null): InventoryOrderSyncResult
    {
        return $this->synchronize($order, 'reserve', $location);
    }

    /** Release active reservations when an order is cancelled. */
    public function releaseOrder(Order $order): InventoryOrderSyncResult
    {
        return $this->synchronize($order, 'release');
    }

    /** Fulfil active reservations and decrement on-hand stock. */
    public function fulfillOrder(Order $order): InventoryOrderSyncResult
    {
        return $this->synchronize($order, 'fulfill');
    }

    public function synchronize(
        Order $order,
        string $action,
        StockLocation|int|null $location = null,
    ): InventoryOrderSyncResult {
        if (! in_array($action, ['reserve', 'release', 'fulfill'], true)) {
            throw new \InvalidArgumentException('Inventory/order action must be reserve, release, or fulfill.');
        }

        $connection = DB::getDefaultConnection();
        if ($order->getConnection()->getName() !== $connection) {
            throw new InventoryOrderSyncException('Orders and Inventory must use the same default database connection.');
        }

        return DB::connection($connection)->transaction(function () use ($order, $action, $location, $connection): InventoryOrderSyncResult {
            $orderClass = config('itmosoft-orders.models.order', Order::class);
            /** @var Order|null $lockedOrder */
            $lockedOrder = $orderClass::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $lockedOrder) {
                throw new InventoryOrderSyncException('The order could not be found.');
            }
            if ($lockedOrder->getConnection()->getName() !== $connection) {
                throw new InventoryOrderSyncException('Orders and Inventory must use the same default database connection.');
            }

            $itemClass = config('itmosoft-orders.models.order_item', OrderItem::class);
            $items = $itemClass::query()
                ->where('order_id', $lockedOrder->getKey())
                ->orderBy('id')
                ->get();

            $counts = ['created' => 0, 'released' => 0, 'fulfilled' => 0];

            foreach ($items as $item) {
                if (! $this->isInventoryTracked($item)) {
                    continue;
                }

                $reference = $this->reservationReference($lockedOrder, $item);
                $reservationClass = config('inventory.models.reservation', StockReservation::class);
                $reservation = $reservationClass::query()
                    ->where('reference', $reference)
                    ->lockForUpdate()
                    ->first();

                if ($action === 'reserve') {
                    if ($reservation) {
                        // Any prior state is terminal for this order line. In
                        // particular, never duplicate a fulfilled/released hold.
                        continue;
                    }

                    $product = $this->resolveProduct($item);
                    $stockLocation = $this->resolveLocation($lockedOrder, $item, $location);
                    $quantity = $this->resolveQuantity($item);

                    // Serialize duplicate event deliveries on the stock row
                    // before checking for an existing reservation and reserving.
                    $stock = $this->inventory->stockFor($product, $stockLocation);
                    $stock->newQuery()->lockForUpdate()->findOrFail($stock->getKey());
                    $reservation = $reservationClass::query()
                        ->where('reference', $reference)
                        ->lockForUpdate()
                        ->first();
                    if ($reservation) {
                        continue;
                    }

                    try {
                        $this->inventory->reserve(
                            $product,
                            $stockLocation,
                            $quantity,
                            $reference,
                            null,
                            [
                                'order_id' => $lockedOrder->getKey(),
                                'order_item_id' => $item->getKey(),
                            ],
                        );
                    } catch (InsufficientStockException $exception) {
                        throw new InventoryOrderSyncException(
                            "Insufficient stock for order item [{$item->getKey()}]: {$exception->getMessage()}",
                            previous: $exception,
                        );
                    }
                    $counts['created']++;
                    continue;
                }

                if (! $reservation) {
                    if ($action === 'release') {
                        // Cancelling a pending order has no reservations to release.
                        continue;
                    }

                    throw new InventoryOrderSyncException(
                        "Cannot complete order: reservation [{$reference}] does not exist."
                    );
                }

                if ($action === 'release') {
                    if ($reservation->status === 'active') {
                        $this->inventory->release($reservation);
                        $counts['released']++;
                    } elseif ($reservation->status === 'fulfilled') {
                        throw new InventoryOrderSyncException(
                            "Cannot cancel order: reservation [{$reference}] has already been fulfilled."
                        );
                    }

                    continue;
                }

                if ($reservation->status === 'active') {
                    $this->inventory->fulfill($reservation);
                    $counts['fulfilled']++;
                } elseif ($reservation->status !== 'fulfilled') {
                    throw new InventoryOrderSyncException(
                        "Cannot complete order: reservation [{$reference}] is [{$reservation->status}], not active."
                    );
                }
            }

            $outcome = match ($action) {
                'reserve' => $counts['created'] > 0 ? 'reserved' : 'unchanged',
                'release' => $counts['released'] > 0 ? 'released' : 'unchanged',
                'fulfill' => $counts['fulfilled'] > 0 ? 'fulfilled' : 'unchanged',
            };

            return new InventoryOrderSyncResult(
                $outcome,
                $lockedOrder->fresh(),
                $counts['created'],
                $counts['released'],
                $counts['fulfilled'],
            );
        });
    }

    protected function isInventoryTracked(Model $item): bool
    {
        $type = $item->getAttribute('product_type');
        $id = $item->getAttribute('product_id');

        if ($type === null && $id === null) {
            return false;
        }

        if ($type === null || $id === null) {
            throw new InventoryOrderSyncException(
                "Order item [{$item->getKey()}] has an incomplete product morph reference."
            );
        }

        return true;
    }

    protected function resolveProduct(Model $item): Model
    {
        $product = $item->product;
        if (! $product instanceof Model) {
            throw new InventoryOrderSyncException(
                "The inventory product for order item [{$item->getKey()}] could not be resolved."
            );
        }

        return $product;
    }

    protected function resolveQuantity(Model $item): int
    {
        $quantity = (float) $item->quantity;
        if ($quantity < 1 || floor($quantity) !== $quantity) {
            throw new InventoryOrderSyncException(
                "Inventory quantity for order item [{$item->getKey()}] must be a positive whole number."
            );
        }

        return (int) $quantity;
    }

    protected function resolveLocation(
        Order $order,
        Model $item,
        StockLocation|int|null $location,
    ): StockLocation {
        if ($location instanceof StockLocation) {
            return $location;
        }

        $metadata = is_array($item->metadata) ? $item->metadata : [];
        $orderMetadata = is_array($order->metadata) ? $order->metadata : [];
        $locationId = $location
            ?? ($metadata['inventory_location_id'] ?? null)
            ?? ($orderMetadata['inventory_location_id'] ?? null)
            ?? config('itmosoft-commerce-adapters.inventory_orders.location_id');

        if ($locationId === null) {
            throw new InventoryOrderSyncException(
                'No inventory location is configured. Set inventory_orders.location_id or provide inventory_location_id in order/item metadata.'
            );
        }

        $locationClass = config('inventory.models.location', StockLocation::class);
        $resolved = $locationClass::query()->find($locationId);
        if (! $resolved) {
            throw new InventoryOrderSyncException("Inventory location [{$locationId}] could not be found.");
        }

        return $resolved;
    }

    protected function reservationReference(Order $order, Model $item): string
    {
        return 'itmosoft-order:'.$order->getKey().':item:'.$item->getKey();
    }
}
