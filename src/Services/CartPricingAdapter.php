<?php

namespace ItmoSoft\CommerceAdapters\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Cart\Models\Cart;
use ItmoSoft\Cart\Models\CartItem;
use ItmoSoft\Cart\Services\CartManager;
use ItmoSoft\CommerceAdapters\Exceptions\CartPricingException;
use ItmoSoft\Pricing\Models\Price;
use ItmoSoft\Pricing\Services\PriceResolver;
use InvalidArgumentException;

/**
 * Resolves a trusted catalog price and passes it to CartManager, which owns
 * all cart persistence and price-snapshot storage.
 */
class CartPricingAdapter
{
    public function __construct(
        protected PriceResolver $prices,
        protected CartManager $carts,
    ) {
    }

    /**
     * Add an item using the best applicable Pricing price.
     *
     * If the same item/options line already exists, the price is resolved
     * against the resulting quantity so quantity-break prices apply to the
     * complete cart line, not merely the newly added units.
     *
     * Context must be built from trusted server-side facts. Do not pass raw
     * customer-controlled pricing context without validation.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $context
     */
    public function addPricedItem(
        Cart $cart,
        Model $item,
        int $quantity = 1,
        array $options = [],
        array $context = [],
        ?DateTimeInterface $at = null,
    ): CartItem {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $connection = DB::getDefaultConnection();
        if ($cart->getConnection()->getName() !== $connection || $item->getConnection()->getName() !== $connection) {
            throw new CartPricingException('Cart, priceable item, and Pricing must use the application default database connection.');
        }

        return DB::connection($connection)->transaction(function () use ($cart, $item, $quantity, $options, $context, $at, $connection): CartItem {
            $cartClass = config('itmosoft-cart.models.cart', Cart::class);
            /** @var Cart $lockedCart */
            $lockedCart = $cartClass::query()
                ->whereKey($cart->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCart->getConnection()->getName() !== DB::getDefaultConnection()) {
                throw new CartPricingException('The cart does not use the application default database connection.');
            }

            $optionsHash = $this->optionsHash($options);
            $existing = $lockedCart->items()
                ->where('itemable_type', $item->getMorphClass())
                ->where('itemable_id', $item->getKey())
                ->where('options_hash', $optionsHash)
                ->lockForUpdate()
                ->first();

            $resolvedQuantity = $quantity + (int) ($existing?->quantity ?? 0);
            $currency = strtoupper((string) $lockedCart->currency);

            $price = $this->prices->resolve(
                priceable: $item,
                currency: $currency,
                quantity: $resolvedQuantity,
                context: $context,
                at: $at,
            );

            if (! $price instanceof Price) {
                throw new CartPricingException(
                    "No applicable {$currency} price was found for [{$item->getMorphClass()}#{$item->getKey()}] at quantity {$resolvedQuantity}."
                );
            }

            if ($price->getConnection()->getName() !== $connection) {
                throw new CartPricingException('The Pricing model must use the application default database connection.');
            }

            if (strtoupper((string) $price->currency) !== $currency) {
                throw new CartPricingException('The resolved price currency does not match the cart currency.');
            }

            $rawAmount = (string) $price->amount;
            if (! preg_match('/^\\d+(?:\\.\\d+)?$/', $rawAmount)) {
                throw new CartPricingException('The resolved price must be a non-negative decimal amount.');
            }

            // Round the database decimal string without converting through a
            // binary float. Cart's schema persists monetary values at 2dp.
            $cartAmount = $this->roundHalfUpToCartPrecision($rawAmount);

            $snapshot = [
                'source' => 'itmosoft-laravel-pricing',
                'price_id' => $price->getKey(),
                'currency' => $currency,
                'resolved_amount' => $rawAmount,
                'cart_unit_price' => $cartAmount,
                'compare_at_amount' => $price->compare_at_amount,
                'min_quantity' => (int) $price->min_quantity,
                'priority' => (int) $price->priority,
                'label' => $price->label,
                'context' => $price->context ?? [],
                'quantity' => $resolvedQuantity,
                'resolved_at' => ($at ? \Illuminate\Support\Carbon::instance($at) : now())->toISOString(),
                'rounding' => 'half_up_2dp',
            ];

            return $this->carts->add(
                cart: $lockedCart,
                item: $item,
                quantity: $quantity,
                unitPrice: $cartAmount,
                options: $options,
                priceSnapshot: $snapshot,
            );
        });
    }

    /** Resolve a price without mutating the cart. */
    public function resolvePrice(
        Model $item,
        string $currency,
        int $quantity = 1,
        array $context = [],
        ?DateTimeInterface $at = null,
    ): ?Price {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        return $this->prices->resolve(
            priceable: $item,
            currency: strtoupper($currency),
            quantity: $quantity,
            context: $context,
            at: $at,
        );
    }


    /** Round a non-negative decimal string to Cart's fixed two-decimal scale. */
    protected function roundHalfUpToCartPrecision(string $amount): string
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 3, '0');
        $cents = substr($fraction, 0, 2);

        if ((int) ($fraction[2] ?? '0') >= 5) {
            $cents = (string) ((int) $cents + 1);
            if (strlen($cents) > 2) {
                $cents = '00';
                $whole = $this->incrementDecimalInteger($whole);
            } else {
                $cents = str_pad($cents, 2, '0', STR_PAD_LEFT);
            }
        }

        // Cart's DECIMAL(14,2) column allows at most 12 integer digits.
        if (strlen($whole) > 12) {
            throw new CartPricingException('The resolved price exceeds the Cart DECIMAL(14,2) storage range.');
        }

        return $whole.'.'.$cents;
    }

    /** Increment a non-negative integer represented as a decimal string. */
    protected function incrementDecimalInteger(string $value): string
    {
        $digits = str_split($value);
        for ($index = count($digits) - 1; $index >= 0; $index--) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);
                return implode('', $digits);
            }
            $digits[$index] = '0';
        }

        return '1'.implode('', $digits);
    }

    /** Match CartManager's options hash so the adapter predicts its merge line. */
    protected function optionsHash(array $options): string
    {
        return hash('sha256', json_encode($this->sortRecursive($options), JSON_THROW_ON_ERROR));
    }

    protected function sortRecursive(array $value): array
    {
        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }
}
