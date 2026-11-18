<?php

namespace ItmoSoft\CommerceAdapters\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ItmoSoft\Checkout\Models\CheckoutSession;
use ItmoSoft\Checkout\Services\CheckoutManager;
use ItmoSoft\Orders\Models\Order;
use ItmoSoft\Orders\Services\OrderService;
use RuntimeException;

class CheckoutOrderAdapter
{
    public function __construct(
        protected OrderService $orders,
        protected CheckoutManager $checkout,
    ) {
    }

    /**
     * Create an order from a pending checkout session and complete the session.
     *
     * Order creation, the checkout metadata link, and checkout completion are
     * wrapped in one database transaction. Both packages must use the default
     * database connection for this atomicity guarantee.
     *
     * Repeated calls return the already-created order referenced in checkout
     * metadata. A completed session without this adapter's order reference is
     * rejected rather than silently creating a second order.
     */
    public function createOrder(CheckoutSession $session, array $overrides = []): Order
    {
        $defaultConnection = DB::getDefaultConnection();
        if ($session->getConnection()->getName() !== $defaultConnection) {
            throw new RuntimeException('Checkout and Orders must use the default database connection for an atomic conversion.');
        }

        return DB::connection($defaultConnection)->transaction(function () use ($session, $overrides): Order {
            $sessionClass = get_class($session);
            /** @var CheckoutSession $locked */
            $locked = $sessionClass::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $metadataKey = (string) config(
                'itmosoft-commerce-adapters.checkout_orders.metadata_key',
                'itmosoft_commerce_adapters'
            );
            if ($metadataKey === '') {
                throw new RuntimeException('The checkout order adapter metadata key cannot be empty.');
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $adapterMetadata = $metadata[$metadataKey] ?? [];
            $linkedOrderId = is_array($adapterMetadata) ? ($adapterMetadata['order_id'] ?? null) : null;

            if ($linkedOrderId !== null) {
                $orderClass = config('itmosoft-orders.models.order', Order::class);
                /** @var Order|null $existing */
                $existing = $orderClass::query()->find($linkedOrderId);
                if (! $existing) {
                    throw new RuntimeException('Checkout metadata references an order that no longer exists.');
                }

                if ($locked->status === 'pending') {
                    $this->checkout->complete($locked);
                } elseif ($locked->status !== 'completed') {
                    throw new RuntimeException('The checkout session is not in a state that can return its linked order.');
                }

                return $existing->loadMissing('items', 'statusHistory');
            }

            if ($locked->status !== 'pending') {
                throw new RuntimeException('Only pending checkout sessions can be converted into orders.');
            }

            $errors = $this->checkout->validate($locked);
            if ($errors !== []) {
                throw new InvalidArgumentException('Checkout validation failed: '.implode(' ', $errors));
            }

            $items = $this->mapItems($locked->cart_snapshot ?? []);
            $totals = is_array($locked->totals) ? $locked->totals : [];

            $orderMetadata = [
                'source' => 'itmosoft-laravel-checkout',
                'checkout_session_id' => $locked->getKey(),
                'checkout_public_id' => $locked->public_id,
                'cart_id' => $locked->cart_id,
                'shipping_method' => $locked->shipping_method,
                'shipping_details' => $locked->shipping_details,
                'checkout_metadata' => $metadata,
            ];

            $customerType = config('itmosoft-commerce-adapters.checkout_orders.customer_type');
            $payload = [
                'currency' => strtoupper((string) $locked->currency),
                'subtotal' => $totals['subtotal'] ?? 0,
                'discount_total' => $totals['discount'] ?? 0,
                'tax_total' => $totals['tax'] ?? 0,
                'shipping_total' => $totals['shipping'] ?? 0,
                'grand_total' => $totals['grand_total'] ?? null,
                'billing_address' => $locked->billing_address,
                'shipping_address' => $locked->shipping_address,
                'items' => $items,
                'metadata' => $orderMetadata,
            ];

            if ($customerType !== null && $customerType !== '' && $locked->user_id !== null) {
                $payload['customer_type'] = $customerType;
                $payload['customer_id'] = $locked->user_id;
            }

            $order = $this->orders->create(array_replace($payload, $overrides));

            $metadata[$metadataKey] = [
                'order_id' => $order->getKey(),
                'order_number' => $order->order_number,
                'created_at' => now()->toISOString(),
            ];
            $locked->metadata = $metadata;
            $locked->save();

            $this->checkout->complete($locked);

            return $order->load('items', 'statusHistory');
        });
    }

    /**
     * @param array<int, array<string, mixed>> $snapshot
     * @return array<int, array<string, mixed>>
     */
    protected function mapItems(array $snapshot): array
    {
        if ($snapshot === []) {
            throw new InvalidArgumentException('The checkout session has no cart snapshot items.');
        }

        return array_map(function (mixed $item, int $index): array {
            if (! is_array($item)) {
                throw new InvalidArgumentException("Checkout snapshot item at index {$index} must be an array.");
            }

            $name = $item['name'] ?? null;
            $quantity = $item['quantity'] ?? null;
            $unitPrice = $item['unit_price'] ?? null;

            if (! is_string($name) || trim($name) === '' || ! is_numeric($quantity) || ! is_numeric($unitPrice)) {
                throw new InvalidArgumentException("Checkout snapshot item at index {$index} requires a name, numeric quantity, and numeric unit_price.");
            }

            $options = is_array($item['options'] ?? null) ? $item['options'] : [];

            return [
                'product_type' => $item['itemable_type'] ?? null,
                'product_id' => $item['itemable_id'] ?? null,
                'name' => $name,
                'sku' => $item['sku'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $item['line_total'] ?? null,
                'product_snapshot' => $item,
                'metadata' => [
                    'checkout_cart_item_id' => $item['cart_item_id'] ?? null,
                    'options' => $options,
                ],
            ];
        }, $snapshot, array_keys($snapshot));
    }
}