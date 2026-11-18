<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Cart\Models\Cart;
use ItmoSoft\Cart\Models\CartItem;
use ItmoSoft\Cart\Services\CartManager;
use ItmoSoft\CommerceAdapters\Exceptions\CartInventoryException;
use ItmoSoft\Inventory\Models\StockLocation;
use ItmoSoft\Inventory\Services\InventoryManager;
use InvalidArgumentException;

/**
 * Checks cart demand against Inventory without reserving stock.
 *
 * Cart contents are advisory demand only. Inventory is reserved by the
 * Inventory → Orders adapter when an order enters its configured lifecycle.
 */
class CartInventoryAdapter
{
    public function __construct(
        protected InventoryManager $inventory,
        protected CartManager $carts,
    ) {
    }

    /**
     * Add an item only when the cart's resulting demand fits available stock.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $priceSnapshot
     */
    public function addAvailableItem(
        Cart $cart,
        Model $item,
        int $quantity = 1,
        StockLocation|int|null $location = null,
        int|float|string|null $unitPrice = null,
        array $options = [],
        array $priceSnapshot = [],
    ): CartItem {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $connection = DB::getDefaultConnection();
        $this->assertConnection($cart, $connection, 'Cart');
        $this->assertConnection($item, $connection, 'Cart item product');

        return DB::connection($connection)->transaction(function () use (
            $cart,
            $item,
            $quantity,
            $location,
            $unitPrice,
            $options,
            $priceSnapshot,
            $connection,
        ): CartItem {
            $cartClass = config('itmosoft-cart.models.cart', Cart::class);
            /** @var Cart $lockedCart */
            $lockedCart = $cartClass::query()
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertConnection($lockedCart, $connection, 'Cart');
            $stockLocation = $this->resolveLocation($location);
            $this->assertConnection($stockLocation, $connection, 'Inventory location');

            $currentDemand = (int) $lockedCart->items()
                ->where('itemable_type', $item->getMorphClass())
                ->where('itemable_id', $item->getKey())
                ->sum('quantity');

            $this->assertStockAvailable($item, $currentDemand + $quantity, $stockLocation, $connection);

            return $this->carts->add(
                cart: $lockedCart,
                item: $item,
                quantity: $quantity,
                unitPrice: $unitPrice,
                options: $options,
                priceSnapshot: $priceSnapshot,
            );
        });
    }

    /**
     * Change a cart line quantity only when total cart demand remains available.
     */
    public function setAvailableQuantity(
        CartItem $cartItem,
        int $quantity,
        StockLocation|int|null $location = null,
    ): CartItem {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $connection = DB::getDefaultConnection();
        $this->assertConnection($cartItem, $connection, 'Cart item');

        return DB::connection($connection)->transaction(function () use ($cartItem, $quantity, $location, $connection): CartItem {
            $cartClass = config('itmosoft-cart.models.cart', Cart::class);
            /** @var Cart $cart */
            $cart = $cartClass::query()
                ->whereKey($cartItem->cart_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertConnection($cart, $connection, 'Cart');

            $itemClass = config('itmosoft-cart.models.cart_item', CartItem::class);
            /** @var CartItem $lockedItem */
            $lockedItem = $itemClass::query()
                ->whereKey($cartItem->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $product = $lockedItem->itemable;
            if (! $product instanceof Model) {
                throw new CartInventoryException('The cart item product could not be resolved.');
            }
            $this->assertConnection($product, $connection, 'Cart item product');

            $stockLocation = $this->resolveLocation($location);
            $this->assertConnection($stockLocation, $connection, 'Inventory location');

            $otherDemand = (int) $cart->items()
                ->where('itemable_type', $lockedItem->itemable_type)
                ->where('itemable_id', $lockedItem->itemable_id)
                ->where($lockedItem->getQualifiedKeyName(), '!=', $lockedItem->getKey())
                ->sum('quantity');

            $this->assertStockAvailable($product, $otherDemand + $quantity, $stockLocation, $connection);

            return $this->carts->setQuantity($lockedItem, $quantity);
        });
    }

    /**
     * Validate a proposed addition without changing the cart.
     *
     * Returns the stock quantity currently available at the selected location.
     * This is useful when composing the check with CartPricingAdapter, but it
     * does not reserve stock and therefore cannot guarantee later availability.
     */
    public function assertAvailableForCart(
        Cart $cart,
        Model $item,
        int $additionalQuantity,
        StockLocation|int|null $location = null,
    ): int {
        if ($additionalQuantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $connection = DB::getDefaultConnection();
        $this->assertConnection($cart, $connection, 'Cart');
        $this->assertConnection($item, $connection, 'Cart item product');

        return DB::connection($connection)->transaction(function () use ($cart, $item, $additionalQuantity, $location, $connection): int {
            $cartClass = config('itmosoft-cart.models.cart', Cart::class);
            /** @var Cart $lockedCart */
            $lockedCart = $cartClass::query()
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertConnection($lockedCart, $connection, 'Cart');

            $stockLocation = $this->resolveLocation($location);
            $this->assertConnection($stockLocation, $connection, 'Inventory location');
            $currentDemand = (int) $lockedCart->items()
                ->where('itemable_type', $item->getMorphClass())
                ->where('itemable_id', $item->getKey())
                ->sum('quantity');

            $this->assertStockAvailable($item, $currentDemand + $additionalQuantity, $stockLocation, $connection);
            return $this->availableQuantity($item, $stockLocation);
        });
    }

    /** Return the current available integer quantity at a location. */
    public function availableQuantity(
        Model $item,
        StockLocation|int|null $location = null,
    ): int {
        $connection = DB::getDefaultConnection();
        $this->assertConnection($item, $connection, 'Cart item product');
        $stockLocation = $this->resolveLocation($location);
        $this->assertConnection($stockLocation, $connection, 'Inventory location');

        $stock = $this->inventory->stockFor($item, $stockLocation);
        $this->assertConnection($stock, $connection, 'Inventory stock');

        return (int) $stock->fresh()->available_quantity;
    }

    protected function assertStockAvailable(
        Model $item,
        int $requested,
        StockLocation $location,
        string $connection,
    ): void {
        $stock = $this->inventory->stockFor($item, $location);
        $this->assertConnection($stock, $connection, 'Inventory stock');
        $lockedStock = $stock->newQuery()->lockForUpdate()->findOrFail($stock->getKey());
        $available = (int) $lockedStock->available_quantity;

        if ($requested > $available) {
            throw new CartInventoryException(
                "Insufficient stock for [{$item->getMorphClass()}#{$item->getKey()}]: cart requests {$requested}, but only {$available} are available at location [{$location->getKey()}]."
            );
        }
    }

    protected function resolveLocation(StockLocation|int|null $location): StockLocation
    {
        if ($location instanceof StockLocation) {
            $resolved = $location;
        } else {
            $locationId = $location ?? config('itmosoft-commerce-adapters.cart_inventory.location_id');
            if ($locationId === null || $locationId === '') {
                throw new CartInventoryException(
                    'No inventory location is configured. Set cart_inventory.location_id or pass a location to the adapter.'
                );
            }

            $locationClass = config('inventory.models.location', StockLocation::class);
            $resolved = $locationClass::query()->find($locationId);
            if (! $resolved) {
                throw new CartInventoryException("Inventory location [{$locationId}] could not be found.");
            }
        }

        if (! $resolved->exists) {
            throw new CartInventoryException('The selected inventory location must be a persisted model.');
        }

        if (! $resolved->is_active) {
            throw new CartInventoryException("Inventory location [{$resolved->getKey()}] is inactive.");
        }

        return $resolved;
    }

    protected function assertConnection(Model $model, string $connection, string $label): void
    {
        if ($model->getConnection()->getName() !== $connection) {
            throw new CartInventoryException("{$label}, Cart and Inventory must use the application's default database connection.");
        }
    }
}
